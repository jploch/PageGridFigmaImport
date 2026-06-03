<?php namespace ProcessWire;

/**
 * Parses a Figma data.json export into a structured import plan.
 *
 * Returns a nested array describing groups, blocks, grid positions,
 * visual styles, content (HTML / plain text), and image paths.
 * No ProcessWire API calls are made here.
 */
class FigmaParser {

    private $data;
    private $frameWidth;
    private $calc;
    private $extractDir;

    /** @var bool Whether a layoutGrid is defined for the frame. */
    private $hasGrid = false;

    /** @var bool True when no layoutGrids was found and defaults were used. */
    private $gridAutoDetected = false;

    /** @var string Pattern name that triggered auto-detect (e.g. 'GRID', 'ROWS'). */
    private $gridAutoDetectedPattern = '';

    /** @var string[] Names of nodes with non-zero rotation (collected during parsing). */
    private $rotatedNodeNames = [];

    /**
     * Maps rounded font size (px) → HTML tag, built once by buildFontTagMap().
     * e.g. [26 => 'h2', 21 => 'h3', 15 => 'p', 10 => 'small']
     * @var array<int,string>
     */
    private $sizeToTag = [];

    /**
     * Maps textStyleId → [className, cssProps], built from the top-level textStyles array.
     * Used to connect TEXT nodes to global CSS classes via textStyleId.
     * @var array<string,array{className:string,cssProps:array}>
     */
    private $textStyleMap = [];

    /**
     * When true, skip textStyleMap lookups so CSS props are written directly
     * onto each block instead of delegated to a global pg-class page.
     * @var bool
     */
    private $skipTextStyles = false;

    public function __construct(array $data, string $extractDir, bool $skipTextStyles = false) {
        $this->data           = $data;
        $this->extractDir     = rtrim($extractDir, '/') . '/';
        $this->frameWidth     = (float)($data['absoluteBoundingBox']['width'] ?? 1280);
        $this->skipTextStyles = $skipTextStyles;

        if(!empty($data['layoutGrids'])) {
            $grid    = $data['layoutGrids'][0];
            $pattern = $grid['pattern'] ?? '';

            if($pattern === 'COLUMNS') {
                $this->calc = new FigmaGridCalculator($grid, $this->frameWidth);
            } else {
                // GRID, ROWS, or unknown — cannot derive column positions; fall back to auto-detect
                $this->gridAutoDetected        = true;
                $this->gridAutoDetectedPattern = $pattern;
                $detectedOffset = $this->detectFrameOffset($data['children'] ?? []);
                $this->calc = new FigmaGridCalculator([
                    'pattern'    => 'COLUMNS',
                    'alignment'  => 'STRETCH',
                    'gutterSize' => 20,
                    'offset'     => $detectedOffset,
                    'count'      => 12,
                ], $this->frameWidth);
            }
        } else {
            $detectedOffset         = $this->detectFrameOffset($data['children'] ?? []);
            $this->gridAutoDetected = true;
            $this->calc = new FigmaGridCalculator([
                'pattern'    => 'COLUMNS',
                'alignment'  => 'STRETCH',
                'gutterSize' => 20,
                'offset'     => $detectedOffset,
                'count'      => 12,
            ], $this->frameWidth);
        }
        $this->hasGrid = true;

        $this->buildFontTagMap();
        $this->buildTextStyleMap();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns the full import plan array:
     * [
     *   frameBackground  => string|null   rgba() of frame fill
     *   framePadding     => int           px padding from layout offset
     *   gridCount        => int           number of columns
     *   gridGutter       => int           gap in px
     *   groups           => array         sorted top-to-bottom
     *   rowGaps          => array         gap in px between consecutive groups
     *   warnings         => string[]      non-fatal issues (e.g. no layout grid found)
     * ]
     */
    public function parse(): array {
        $groups   = $this->pruneEmptyGroups($this->parsedGroups());
        $rowGaps  = $this->calcRowGaps($groups);
        $warnings = [];

        if($this->gridAutoDetected) {
            if($this->gridAutoDetectedPattern !== '') {
                $warnings[] = "Figma '{$this->gridAutoDetectedPattern}' layout grid does not define column positions — column positions are approximate. Use a COLUMNS layout grid in Figma for best results.";
            } else {
                $warnings[] = 'No layout grid found in the Figma file — column positions are approximate. For best results, define a Layout Grid in Figma before exporting.';
            }
        }

        if(!empty($this->rotatedNodeNames)) {
            $names      = array_unique($this->rotatedNodeNames);
            $nameList   = implode(', ', array_map(fn($n) => "<strong>{$n}</strong>", $names));
            $warnings[] = "Grid positions may be inaccurate for rotated items: {$nameList}";
        }

        return [
            'frameBackground' => $this->frameBackground(),
            'framePadding'    => (int)$this->calc->getOffset(),
            'gridCount'       => $this->calc->getCount(),
            'gridGutter'      => (int)$this->calc->getGutterSize(),
            'groups'          => $groups,
            'rowGaps'         => $rowGaps,
            'textStyles'      => $this->parseTextStyles(),
            'warnings'        => $warnings,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Text style helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Converts the top-level textStyles array into an array of
     * [name, className, cssProps] entries for use as global CSS classes.
     *
     * @return array<int,array{name:string,className:string,cssProps:array}>
     */
    private function parseTextStyles(): array {
        $result = [];
        foreach($this->data['textStyles'] ?? [] as $style) {
            $name     = $style['name'] ?? '';
            if($name === '') continue;
            $cssProps = $this->segmentToStyles($style);
            if(empty($cssProps)) continue;
            $result[] = [
                'name'      => $name,
                'className' => $this->toClassName($name),
                'cssProps'  => $cssProps,
            ];
        }
        return $result;
    }

    /**
     * Builds $textStyleMap: textStyleId → [className, cssProps].
     * Enables fast lookup when TEXT nodes reference a global textStyle via textStyleId.
     */
    private function buildTextStyleMap(): void {
        foreach($this->data['textStyles'] ?? [] as $style) {
            $id   = $style['id'] ?? '';
            $name = $style['name'] ?? '';
            if($id === '' || $name === '') continue;
            $this->textStyleMap[$id] = [
                'className' => $this->toClassName($name),
                'cssProps'  => $this->segmentToStyles($style),
            ];
        }
    }

    /**
     * Converts a human-readable style name to a lowercase hyphenated CSS classname.
     * e.g. "muted text small" → "muted-text-small"
     *      "body copy 17px"   → "body-copy-17px"
     */
    private function toClassName(string $name): string {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', '-', $name);
        return trim($name, '-');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Frame helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Estimates the frame's left margin from its direct children's absolute x positions.
     * Returns the minimum positive x value (≈ offset), or 20.0 as a fallback.
     */
    private function detectFrameOffset(array $children): float {
        $xValues = [];
        foreach($children as $child) {
            $x = (float)($child['absoluteBoundingBox']['x'] ?? 0);
            if($x > 0) {
                $xValues[] = $x;
            }
        }
        return !empty($xValues) ? min($xValues) : 20.0;
    }

    /**
     * Scans every textSegment in the document and builds $sizeToTag:
     *   - size < 12px   → 'small'
     *   - size < 17px   → 'p'  (body text — fixed threshold, no hierarchy)
     *   - size ≥ 17px   → heading hierarchy: largest → h2, next → h3 … h6, overflow → 'p'
     * h1 is intentionally omitted (SEO-sensitive, user sets it manually).
     */
    private function buildFontTagMap(): void {
        $sizes = [];
        $this->collectFontSizes($this->data, $sizes);

        if(empty($sizes)) return;

        $headingSizes = [];
        foreach(array_unique($sizes) as $size) {
            if($size < 12) {
                $this->sizeToTag[$size] = 'small';
            } elseif($size < 17) {
                $this->sizeToTag[$size] = 'p';
            } else {
                $headingSizes[] = $size;
            }
        }

        // Assign h2–h6 to heading-sized text (≥ 17px), largest first
        rsort($headingSizes);
        $headings = ['h2', 'h3', 'h4', 'h5', 'h6'];
        foreach($headingSizes as $i => $size) {
            $this->sizeToTag[$size] = $headings[$i] ?? 'p';
        }
    }

    /** Recursively collects all unique rounded font sizes from textSegments. */
    private function collectFontSizes(array $node, array &$sizes): void {
        foreach($node['textSegments'] ?? [] as $seg) {
            $fs = (float)($seg['fontSize'] ?? 0);
            if($fs > 0) $sizes[] = (int)round($fs);
        }
        foreach($node['children'] ?? [] as $child) {
            $this->collectFontSizes($child, $sizes);
        }
    }

    private function frameBackground(): ?string {
        foreach($this->data['fills'] ?? [] as $fill) {
            if(($fill['type'] ?? '') === 'SOLID' && isset($fill['color'])) {
                return $this->rgba($fill['color'], $fill['opacity'] ?? 1.0);
            }
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Group parsing
    // ─────────────────────────────────────────────────────────────────────────

    private function parsedGroups(): array {
        $rawChildren = $this->data['children'] ?? [];

        $groups    = [];
        $leafNodes = []; // non-GROUP/FRAME direct children (TEXT, RECT, ELLIPSE, etc.)

        foreach($rawChildren as $node) {
            $type = $node['type'] ?? '';
            // Small INSTANCE/COMPONENT nodes that are icon wrappers (single IMAGE child,
            // no layoutGrids, < 100 px) are treated as leaf image nodes rather than groups.
            if(($type === 'COMPONENT' || $type === 'INSTANCE') && $this->isSingleImageComponent($node)) {
                $leafNodes[] = $this->flattenImageComponent($node);
            // INSTANCE/FRAME/COMPONENT with a direct asset URL (mcp_image_url/mcp_svg_url)
            // and no children — these are exported image fills on an instance, not group containers.
            } elseif($this->isDirectAssetNode($node)) {
                $leafNodes[] = $node;
            } elseif($type === 'GROUP' || $type === 'FRAME' || $type === 'COMPONENT' || $type === 'INSTANCE') {
                $groups[] = $this->parseGroupNode($node);
            } elseif($type !== 'LINE') {
                // LINE nodes at frame level have no parent for a border — silently drop
                $leafNodes[] = $node;
            }
        }

        // Parse leaf nodes into direct blocks (no row assignment yet — done below).
        $groups = array_merge($groups, $this->parseDirectBlocks($leafNodes));

        // Sort ALL top-level items by absolute y so row assignment and calcRowGaps()
        // both see items in visual top-to-bottom order.
        usort($groups, static function($a, $b) {
            $yDiff = ($a['y'] ?? 0) <=> ($b['y'] ?? 0);
            if($yDiff !== 0) return $yDiff;
            return (int)($a['gridStyles']['grid-column-start'] ?? 1) <=> (int)($b['gridStyles']['grid-column-start'] ?? 1);
        });

        // Assign grid-row-start (and span) to EVERY top-level item — groups and
        // direct blocks together — so their row numbers are globally consistent.
        $gutter = $this->hasGrid ? (float)$this->calc->getGutterSize() : 20.0;
        $this->assignUnifiedRows($groups, $gutter);

        // Drop truly empty groups — those with neither children nor visual styles.
        // Direct block items always kept (isDirectBlock flag).
        return array_values(array_filter($groups, fn($g) => !empty($g['isDirectBlock']) || !empty($g['children']) || !empty($g['groupStyles'])));
    }

    /**
     * Parses direct-frame leaf nodes (TEXT, RECTANGLE, ELLIPSE, etc.) as top-level
     * content blocks, assigning row positions via y-coordinate clustering so that
     * a tall item spanning multiple visual rows receives the correct grid-row-end.
     *
     * @param array $leafNodes  Raw Figma nodes (non-GROUP/FRAME, non-LINE)
     * @return array            Array of parseChildNode results with isDirectBlock=true
     */
    private function parseDirectBlocks(array $leafNodes): array {
        if(empty($leafNodes)) return [];

        $items = [];
        foreach($leafNodes as $node) {
            $bbox   = $node['absoluteBoundingBox'] ?? [];
            $y      = (float)($bbox['y']      ?? 0);
            $h      = (float)($bbox['height'] ?? 0);
            $parsed = $this->parseChildNode($node, $this->calc, 0.0);
            $parsed['isDirectBlock'] = true;
            $parsed['y']             = $y;
            $parsed['height']        = $h;
            $items[] = $parsed;
        }

        // Row assignment is handled in parsedGroups() via assignUnifiedRows()
        // so all top-level items (groups + direct blocks) share the same row numbering.
        return $items;
    }

    /**
     * Assigns grid-row-start (and grid-row-end span when > 1) to every top-level item
     * — both pg_group nodes and direct-block leaf nodes — using y-position clustering.
     *
     * Items whose y-start values lie within $tolerance of each other share the same CSS
     * grid row. A tall item whose bottom edge crosses subsequent cluster centres receives
     * grid-row-end: span N. Using a single pass over all items ensures group nodes and
     * leaf nodes get globally consistent row numbers.
     *
     * @param array $items   All top-level parsed items; each has 'y' and 'height'
     * @param float $gutter  Clustering tolerance (px)
     */
    private function assignUnifiedRows(array &$items, float $gutter): void {
        if(empty($items)) return;

        $tolerance = max($gutter, 10.0);

        // Build sorted y-cluster representatives
        $clusters = [];
        foreach($items as $item) {
            $y       = (float)($item['y'] ?? 0);
            $matched = false;
            foreach($clusters as &$cy) {
                if(abs($y - $cy) <= $tolerance) {
                    $cy      = ($cy + $y) / 2.0;
                    $matched = true;
                    break;
                }
            }
            unset($cy);
            if(!$matched) $clusters[] = $y;
        }
        sort($clusters);

        foreach($items as &$item) {
            $y    = (float)($item['y']      ?? 0);
            $yEnd = $y + (float)($item['height'] ?? 0);

            $rowStartIdx = 0;
            foreach($clusters as $ci => $cy) {
                if(abs($y - $cy) <= $tolerance) {
                    $rowStartIdx = $ci;
                    break;
                }
            }

            $lastClusterIdx = $rowStartIdx;
            foreach($clusters as $ci => $cy) {
                if($ci >= $rowStartIdx && $cy < $yEnd) {
                    $lastClusterIdx = $ci;
                }
            }

            $rowStart = $rowStartIdx + 1;
            $span     = $lastClusterIdx - $rowStartIdx + 1;

            $item['gridStyles']['grid-row-start'] = (string)$rowStart;
            if($span > 1) {
                $item['gridStyles']['grid-row-end'] = 'span ' . $span;
            }
        }
        unset($item);
    }

    private function parseGroupNode(array $node, bool $isNested = false): array {
        $bbox = $node['absoluteBoundingBox'] ?? [];
        $x    = (float)($bbox['x']      ?? 0);
        $y    = (float)($bbox['y']      ?? 0);
        $w    = (float)($bbox['width']  ?? $this->frameWidth);
        $h    = (float)($bbox['height'] ?? 0);

        // Group's column position within the frame grid
        $gridStyles = $this->hasGrid ? $this->calc->getGridStyles($x, $w) : $this->fullWidthStyles();

        $groupName = $node['name'] ?? '';
        $isPgGroup = ($this->templateHint($groupName, $node['type'] ?? '') === 'pg_group');

        // A group whose left AND right edges both lie within ±30 px of the frame boundaries
        // is a full-bleed section: its background should fill the viewport edge-to-edge.
        // The importer gives it negative margins to break out of the frame's content padding,
        // plus matching inner padding so children remain on the standard column grid.
        $isFullBleed = !$isNested &&
                       $this->hasGrid &&
                       ($x >= -30.0 && $x <= 5.0) &&
                       (($x + $w) >= $this->frameWidth - 30.0);

        // Determine calc for THIS group's children.
        // Sub-frames with their own layoutGrids use those; otherwise inherit the frame calc.
        $childCalc    = $this->hasGrid ? $this->calc : null;
        $childXOffset = 0.0; // x coords of children are frame-relative; sub-frames need an offset
        $hasOwnGrid   = false; // true only when this node defines its own layoutGrids
        $leftInset    = 0.0;  // detected CSS padding-left for wide groups
        $rightInset   = 0.0;  // detected CSS padding-right for wide groups
        if(!empty($node['layoutGrids'])) {
            $childCalc = new FigmaGridCalculator($node['layoutGrids'][0], $w);
            // FRAME, COMPONENT, INSTANCE nodes create their own coordinate space → children
            // already use local x. GROUP nodes share the root coordinate space → subtract group x.
            $childXOffset = ($node['type'] ?? '') !== 'GROUP' ? 0.0 : $x;
            $hasOwnGrid   = true;
        } elseif($isFullBleed) {
            // Full-bleed groups sit at x ≈ 0 in frame space; use the frame calc directly.
            // Children have frame-relative coordinates, so no offset adjustment is needed.
            $childCalc    = $this->calc;
            $childXOffset = 0.0;
        } elseif($this->hasGrid && ($isNested || $this->calc->colStart($x) > 1 || $this->calc->colSpan($w) < $this->calc->getCount())) {
            // Group with no own layoutGrids: build a local calc using this group's width
            // so children are positioned relative to the group's own coordinate origin.
            // Applies to: (a) all nested groups, (b) top-level groups that start at col 2+,
            // and (c) top-level groups at col 1 that span fewer than N columns — all need
            // repeat(S, 1fr) tracks so the inner grid matches the frame's track size exactly.
            // Same column count and gutter as the frame; no padding offset (the group has no margins).
            $lgGutter = $this->calc->getGutterSize();
            $lgCount  = $this->calc->colSpan($w); // S = group's span in parent frame (not full N)

            // For wide groups detect horizontal content insets (e.g. card left/right padding).
            // Uses absolute bounding boxes of raw children, so the same formula works for both
            // GROUP nodes (absolute coords) and INSTANCE/FRAME nodes (also absolute in export).
            if($w >= 250) {
                $insets     = $this->computeContentInsets($node['children'] ?? [], $x, $w, $lgGutter, $lgCount);
                $leftInset  = $insets['left'];
                $rightInset = $insets['right'];
            }

            $contentWidth = $w - $leftInset - $rightInset;
            $localGrid    = [
                'pattern'     => 'COLUMNS',
                'alignment'   => 'STRETCH',
                'gutterSize'  => $lgGutter,
                'offset'      => 0,
                'count'       => $lgCount,
                'sectionSize' => $this->calc->getSectionSize(), // inherit frame's track size
            ];
            if($leftInset > 0) {
                // Override sectionSize so the inner grid reflects the padded content area.
                $localGrid['sectionSize'] = ($contentWidth - ($lgCount - 1) * $lgGutter) / $lgCount;
            }
            $childCalc    = new FigmaGridCalculator($localGrid, $leftInset > 0 ? $contentWidth : $w);
            // Normalise child x to content-area origin: subtract group x AND left inset.
            $childXOffset = $x + $leftInset;
            // Non-GROUP nodes (FRAME/COMPONENT/INSTANCE) can have children positioned
            // before their own left edge (e.g. a frame shifted right while contents
            // start at the root-grid origin). Use the minimum child x as the content
            // origin so column placement is correct.
            if(($node['type'] ?? '') !== 'GROUP') {
                $minChildX = PHP_FLOAT_MAX;
                foreach($node['children'] ?? [] as $ch) {
                    $chx = (float)($ch['absoluteBoundingBox']['x'] ?? PHP_FLOAT_MAX);
                    $minChildX = min($minChildX, $chx);
                }
                if($minChildX < $childXOffset) {
                    $childXOffset = $minChildX;
                }
            }
        }

        // ── Collect visual decorator styles ────────────────────────────────
        $groupVisualStyles = [];
        $pgChildNodes      = [];

        // Pre-count decorator-eligible rectangles. A single rect that fills the entire
        // group bounding box (±5px) can be lifted as a visual decorator (background/border)
        // onto the group. A rect that only covers part of the group (e.g. a partial-height
        // color block) is content, not a decorator. When there are 2+ decorator rects they
        // are independent content blocks, so none should be treated as a decorator.
        $decoratorRectCount = 0;
        foreach($node['children'] ?? [] as $child) {
            $cn  = $child['name'] ?? '';
            $ct  = $child['type'] ?? '';
            if($ct !== 'RECTANGLE' || strpos($cn, 'pg_') === 0 || !empty($child['children'])
                || !empty($child['mcp_image_url']) || !empty($child['mcp_svg_url'])
                || in_array('IMAGE', array_column($child['fills'] ?? [], 'type'), true)) {
                continue;
            }
            $cbb  = $child['absoluteBoundingBox'] ?? [];
            $crx  = (float)($cbb['x']      ?? 0);
            $cry  = (float)($cbb['y']      ?? 0);
            $crw  = (float)($cbb['width']  ?? 0);
            $crh  = (float)($cbb['height'] ?? 0);
            $xyTol = 5.0;
            $yhTol = 15.0;
            $isFrameNode = ($node['type'] ?? '') !== 'GROUP';
            $gx0 = $isFrameNode ? 0.0 : $x;
            $gy0 = $isFrameNode ? 0.0 : $y;
            if(abs($crx - $gx0) <= $xyTol && abs($cry - $gy0) <= $yhTol
                && abs($crw - $w) <= $xyTol && abs($crh - $h) <= $yhTol) {
                $decoratorRectCount++;
            }
        }
        $singleDecoratorRect = $decoratorRectCount === 1;

        foreach($node['children'] ?? [] as $child) {
            $childName = $child['name'] ?? '';
            $childType = $child['type'] ?? '';
            $isPgNamed = strpos($childName, 'pg_') === 0;
            // A RECTANGLE with an IMAGE fill or an image asset URL is content (pg_image),
            // not a visual decorator — checking both fill type and asset URL for robustness.
            // Multiple decorator-eligible rects in one group are also treated as content.
            $hasImageFill    = !empty($child['mcp_image_url']) || !empty($child['mcp_svg_url'])
                || in_array('IMAGE', array_column($child['fills'] ?? [], 'type'), true);
            $isDecoratorRect = $singleDecoratorRect && !$isPgNamed && empty($child['children']) && $childType === 'RECTANGLE' && !$hasImageFill;
            $isDecoratorLine = !$isPgNamed && $childType === 'LINE';
            // An empty container (FRAME/GROUP/COMPONENT with no children and no image asset)
            // never produces a visible block and must not influence clustering or layout decisions.
            $isEmptyContainer = !$isPgNamed && empty($child['children']) && !$hasImageFill
                && in_array($childType, ['FRAME', 'GROUP', 'COMPONENT'], true);

            if($isDecoratorLine) {
                $lineStyles        = $this->extractLineBorder($child, $x, $y, $w, $h);
                $groupVisualStyles = array_merge($groupVisualStyles, $lineStyles);
            } elseif(!$isDecoratorRect && !$isEmptyContainer && ($isPgNamed || $childType !== '')) {
                $pgChildNodes[] = $child;
            } else {
                $decoratorStyles   = $this->extractDecoratorStyles($child);
                $groupVisualStyles = array_merge($groupVisualStyles, $decoratorStyles);
            }
        }

        // Own fills (e.g. background on a FRAME-type pg_group)
        if(!empty($node['fills']) || !empty($node['strokes'])) {
            $ownStyles         = $this->extractDecoratorStyles($node);
            $groupVisualStyles = array_merge($ownStyles, $groupVisualStyles);
        }

        // ── Layout styles (always applied to metadata, not CSS output) ─────
        $groupLayoutStyles = [];
        if($childCalc) {
            $gutter        = (float)$childCalc->getGutterSize();
            $offset        = (float)$childCalc->getOffset();
            $unitRowHeight = $this->computeUnitRowHeight($pgChildNodes, $gutter);

            $hasHPair   = count($pgChildNodes) >= 2 && $this->hasHorizontalPair($pgChildNodes);
            $isMixedRow = false;

            // Check for explicit Figma auto-layout (applies regardless of template name)
            $layoutMode   = $node['layoutMode'] ?? null;
            $isAutoLayout = $layoutMode !== null && $layoutMode !== 'NONE';

            if($isAutoLayout) {
                $isMixedRow = ($layoutMode === 'HORIZONTAL' && ($node['layoutWrap'] ?? '') === 'WRAP');
                $groupLayoutStyles = $this->buildAutoLayoutStyles($node, $layoutMode);
            } elseif($isPgGroup && $w < 400 && $hasHPair) {
                // Flex + flex-wrap: groups < 400px wide with any two children sharing
                // horizontal space. Covers pure single-row layouts (icon+label) as well as
                // mixed-row cards (image, icon+label, text stacked). Always wraps so items
                // reflow on mobile. Stacked-only groups (no horizontal pairs) use grid.
                $isMixedRow = true;
                $colGap = $this->computeInlineClusterGap($pgChildNodes, $h);
                $rowGap = $this->computeChildRowGap($pgChildNodes, (int)$colGap);
                $groupLayoutStyles = [
                    'display'     => 'flex',
                    'flex-wrap'   => 'wrap',
                    'align-items' => 'flex-start',
                    'column-gap'  => (int)round($colGap) . 'px',
                    'row-gap'     => $rowGap . 'px',
                ];
                if($leftInset > 0) {
                    $groupLayoutStyles['padding-left'] = (int)round($leftInset) . 'px';
                    if($rightInset > 0)
                        $groupLayoutStyles['padding-right'] = (int)round($rightInset) . 'px';
                }
                $isFrameNode = ($node['type'] ?? '') !== 'GROUP';
                $localY      = $isFrameNode ? 0.0 : $y;
                $topInset    = $this->computeTopInset($pgChildNodes, $localY);
                if($topInset !== null) $groupLayoutStyles['padding-top'] = $topInset;
                $bottomInset = $this->computeBottomInset($pgChildNodes, $localY, $h);
                if($bottomInset !== null) $groupLayoutStyles['padding-bottom'] = $bottomInset;
            } elseif($isPgGroup && $w < 250) {
                // Narrow containers with no horizontal pairs: block layout.
                // FRAME, COMPONENT, INSTANCE nodes use local coordinates (lx=0, ly=0);
                // GROUP nodes share the root coordinate space (lx=$x, ly=$y).
                $isFrameNode = ($node['type'] ?? '') !== 'GROUP';
                $lx = $isFrameNode ? 0.0 : $x;
                $ly = $isFrameNode ? 0.0 : $y;
                $groupLayoutStyles = array_merge(
                    ['display' => 'block'],
                    $this->computeGroupPadding($pgChildNodes, $lx, $ly, $w, $h)
                );
            } elseif($isPgGroup) {
                // Wide containers (≥ 400px) or narrow-but-no-special-case: grid layout.
                $groupLayoutStyles = [
                    'display'               => 'grid',
                    'grid-template-columns' => 'repeat(' . $childCalc->getCount() . ', 1fr)',
                    'gap'                   => (int)$gutter . 'px',
                    'align-items'           => 'start',
                ];
                if($hasOwnGrid && $offset > 0) {
                    $groupLayoutStyles['padding-left']  = (int)$offset . 'px';
                    $groupLayoutStyles['padding-right'] = (int)$offset . 'px';
                } elseif($isFullBleed) {
                    // Break out of the frame's content padding so the background fills the full
                    // viewport, then re-add the same offset as inner padding so children remain
                    // on the standard column grid.
                    // Uses the piccalilli "full-bleed" utility:
                    //   width: 100vw          — overrides global `width: 100%` (grid area = 1320px)
                    //   margin-left: calc(50% - 50vw) — 50% of grid area minus 50vw = -offset px
                    // No margin-right needed; width is explicit.
                    $o = (int)$offset;
                    $groupLayoutStyles['width']         = '100vw';
                    $groupLayoutStyles['margin-left']   = 'calc(50% - 50vw)';
                    $groupLayoutStyles['padding-left']  = "{$o}px";
                    $groupLayoutStyles['padding-right'] = "{$o}px";
                } elseif($leftInset > 0) {
                    $groupLayoutStyles['padding-left'] = (int)round($leftInset) . 'px';
                    if($rightInset > 0)
                        $groupLayoutStyles['padding-right'] = (int)round($rightInset) . 'px';
                }
                // FRAME, COMPONENT, INSTANCE nodes export child coordinates relative to their
                // own origin (local), so compare against y=0 / bottom=h.
                // GROUP nodes share the outer-frame coordinate space; use the group's y and y+h.
                $isFrameNode = ($node['type'] ?? '') !== 'GROUP';
                $localY      = $isFrameNode ? 0.0 : $y;
                $topInset    = $this->computeTopInset($pgChildNodes, $localY);
                if($topInset !== null) $groupLayoutStyles['padding-top'] = $topInset;
                $bottomInset = $this->computeBottomInset($pgChildNodes, $localY, $h);
                if($bottomInset !== null) $groupLayoutStyles['padding-bottom'] = $bottomInset;
            } else {
                $isMixedRow    = false;
                $gutter        = $this->hasGrid ? (float)$this->calc->getGutterSize() : 20.0;
                $unitRowHeight = 0.0;
                $groupLayoutStyles = ['display' => 'block'];
            }
        } else {
            $isMixedRow    = false;
            $gutter        = $this->hasGrid ? (float)$this->calc->getGutterSize() : 20.0;
            $unitRowHeight = 0.0;
            if(!$isPgGroup) {
                $groupLayoutStyles = ['display' => 'block'];
            }
        }

        // ── Parse pg_* children with row-span assignment ───────────────────
        $pgChildren = $this->parseGroupChildren($pgChildNodes, $childCalc, $childXOffset, $unitRowHeight, $gutter, $isMixedRow, $isFullBleed, $isAutoLayout, $layoutMode);

        // For narrow groups (e.g. buttons: background rect + single label), force the one
        // content child to fill the full group width. Flex rows, auto-layout frames, and
        // wide groups keep the child's naturally calculated position.
        if($w < 250 && !$isMixedRow && !$isAutoLayout && count($pgChildNodes) === 1 && !empty($pgChildren)) {
            $pgChildren[0]['gridStyles']['grid-column-start'] = '1';
            $pgChildren[0]['gridStyles']['grid-column-end']   = '-1';
        }

        return [
            'id'               => $node['id'] ?? '',
            'name'             => $groupName,
            'x'                => $x,
            'y'                => $y,
            'width'            => $w,
            'height'           => $h,
            'gridStyles'       => $gridStyles,
            'groupLayoutStyles' => $groupLayoutStyles,
            'groupStyles'      => $groupVisualStyles,
            'children'         => $pgChildren,
        ];
    }

    /**
     * Returns the minimum child height — used as the CSS-grid unit row height.
     * Ignores children with height <= 0.
     */
    private function computeUnitRowHeight(array $childNodes, float $gutter): float {
        $heights = [];
        foreach($childNodes as $node) {
            $h = (float)($node['absoluteBoundingBox']['height'] ?? 0);
            if($h > 0) $heights[] = $h;
        }
        return !empty($heights) ? min($heights) : 0.0;
    }

    /**
     * Returns true when a node is a small icon component that should be flattened
     * to a pg_image block instead of treated as a pg_group container.
     *
     * Criteria (all must hold):
     *   - type is INSTANCE or COMPONENT
     *   - width AND height < 100 px
     *   - layoutGrids is empty (no column grid defined)
     *   - exactly 1 child
     *   - that child is an image asset (mcp_svg_url, mcp_image_url, or type=IMAGE)
     */
    private function isSingleImageComponent(array $node): bool {
        $type = $node['type'] ?? '';
        if($type !== 'INSTANCE' && $type !== 'COMPONENT') return false;
        if(!empty($node['layoutGrids'])) return false;
        $bb = $node['absoluteBoundingBox'] ?? [];
        if(($bb['width'] ?? PHP_INT_MAX) >= 100 || ($bb['height'] ?? PHP_INT_MAX) >= 100) return false;
        $kids = $node['children'] ?? [];
        if(count($kids) !== 1) return false;
        $child = $kids[0];
        return !empty($child['mcp_svg_url']) || !empty($child['mcp_image_url'])
            || ($child['type'] ?? '') === 'IMAGE';
    }

    /**
     * Returns true when a node is an INSTANCE, COMPONENT, or FRAME that has its own
     * asset URL (mcp_image_url or mcp_svg_url) directly on the node with no children.
     *
     * These occur when the Figma exporter captures an image fill on an instance node:
     * the mcp_*_url ends up on the instance wrapper rather than on a child element.
     */
    private function isDirectAssetNode(array $node): bool {
        $type = $node['type'] ?? '';
        if(!in_array($type, ['INSTANCE', 'COMPONENT', 'FRAME'], true)) return false;
        if(!empty($node['children'])) return false;
        return !empty($node['mcp_svg_url']) || !empty($node['mcp_image_url']);
    }

    /**
     * Flattens a single-image component to a synthetic leaf node suitable for
     * parseChildNode(). The IMAGE child's asset data is preserved; its
     * absoluteBoundingBox is replaced with the INSTANCE's so that grid placement
     * uses the INSTANCE's canvas position (child coordinates are LOCAL, not absolute).
     */
    private function flattenImageComponent(array $node): array {
        return array_merge($node['children'][0], ['absoluteBoundingBox' => $node['absoluteBoundingBox']]);
    }

    /**
     * Detects horizontal content insets (left/right padding) for wide groups.
     *
     * Scans raw children using absoluteBoundingBox — works identically for GROUP
     * nodes (absolute coords) and INSTANCE/FRAME nodes (also absolute in Figma export).
     * Full-width children (background rects, dividers) are excluded via |cw − gw| < 5.
     *
     * Right padding uses a symmetric mirror of the left inset rather than detecting
     * the right boundary from child widths, because text nodes in Figma auto-size to
     * their content and their right edge does not reliably define the design boundary.
     * Exception: if any non-full-width child is flush to the right edge (rightGap < 5),
     * the design intentionally has no right padding, so right is set to 0.
     *
     * Guards (all must pass — returns ['left'=>0,'right'=>0] if any fails):
     *   G1  leftInset > 5 px
     *   G2  symmetric total padding < 40 % of group width
     *   G3  contentWidth >= (count−1)×gutter + count×5  (grid + gaps must fit)
     *
     * @param array $rawChildren  $node['children'] — raw Figma child array
     * @param float $gx           Group's absolute x position (absoluteBoundingBox.x)
     * @param float $gw           Group width
     * @param float $gutter       Column gutter size in px
     * @param int   $count        Column count
     * @return array              ['left' => float, 'right' => float]
     */
    private function computeContentInsets(array $rawChildren, float $gx, float $gw, float $gutter, int $count): array {
        $zero = ['left' => 0.0, 'right' => 0.0];
        if(empty($rawChildren)) return $zero;

        $minLeft         = PHP_FLOAT_MAX;
        $minRightGap     = PHP_FLOAT_MAX;
        $hasContent      = false;
        $hasFullWidthItem = false;

        foreach($rawChildren as $child) {
            $cb = $child['absoluteBoundingBox'] ?? [];
            $cx = (float)($cb['x']     ?? 0);
            $cw = (float)($cb['width'] ?? 0);
            // Skip full-width children that are real content (images, dividers spanning full width).
            // Decorator rectangles (SOLID or empty fill, no children, no pg_ name) are ignored
            // because they are card backgrounds and don't affect the inset calculation.
            if(abs($cw - $gw) < 5.0) {
                $isImageFill = false;
                foreach($child['fills'] ?? [] as $fill) {
                    if(($fill['type'] ?? '') === 'IMAGE') { $isImageFill = true; break; }
                }
                $isDecorator = !$isImageFill
                    && ($child['type'] ?? '') === 'RECTANGLE'
                    && empty($child['children'])
                    && strpos($child['name'] ?? '', 'pg_') !== 0;
                if($isDecorator) continue; // background rect — safe to ignore
                $hasFullWidthItem = true;
                continue;
            }

            $cxRel       = $cx - $gx;
            $rightGap    = $gw - ($cxRel + $cw);
            $minLeft     = min($minLeft, $cxRel);
            $minRightGap = min($minRightGap, $rightGap);
            $hasContent  = true;
        }

        if(!$hasContent) return $zero;
        // Container padding can't correctly represent both full-width and inset children:
        // applying padding-left would also shift the full-width items off their left edge.
        if($hasFullWidthItem && $minLeft > 5.0) return $zero;

        // G1: leftInset must be meaningful
        if($minLeft <= 5.0) return $zero;

        $leftInset  = $minLeft;
        // If any child is flush to the right edge the design has no right padding.
        $rightInset = ($minRightGap < 5.0) ? 0.0 : $leftInset;

        // G2: proportional cap — total padding < 40 % of group width
        if(($leftInset + $rightInset) >= $gw * 0.4) return $zero;

        // G3: content area must still accommodate the grid (min 5 px per column)
        $contentWidth    = $gw - $leftInset - $rightInset;
        $minContentWidth = ($count - 1) * $gutter + $count * 5;
        if($contentWidth < $minContentWidth) return $zero;

        return ['left' => $leftInset, 'right' => $rightInset];
    }

    /**
     * Computes padding-top from the smallest top-offset of any content child
     * inside the group bounding box. Returns null when the offset is ≤ 1 px.
     */
    private function computeTopInset(array $pgChildNodes, float $gy): ?string {
        if(empty($pgChildNodes)) return null;
        $minT = PHP_INT_MAX;
        foreach($pgChildNodes as $child) {
            $cy   = (float)($child['absoluteBoundingBox']['y'] ?? 0);
            $minT = min($minT, $cy - $gy);
        }
        return ($minT > 1) ? (int)round($minT) . 'px' : null;
    }

    /**
     * Computes padding-bottom from the gap between the bottommost child edge
     * and the group's bottom boundary. Returns null when the gap is ≤ 1 px.
     */
    private function computeBottomInset(array $pgChildNodes, float $gy, float $gh): ?string {
        if(empty($pgChildNodes)) return null;
        $maxBottom = PHP_INT_MIN;
        foreach($pgChildNodes as $child) {
            $cy        = (float)($child['absoluteBoundingBox']['y']      ?? 0);
            $ch        = (float)($child['absoluteBoundingBox']['height'] ?? 0);
            $maxBottom = max($maxBottom, $cy + $ch);
        }
        $gap = ($gy + $gh) - $maxBottom;
        return ($gap > 1) ? (int)round($gap) . 'px' : null;
    }

    /**
     * Derives padding values for a narrow group by measuring each content
     * child's inset from the group's bounding box edges.
     *
     * The minimum inset on each side is used (most constrained child wins),
     * so padding is only added for sides where all children are inset by > 1 px.
     *
     * @param array $pgChildNodes  Raw Figma nodes (content children only)
     * @param float $gx   Group bounding box x
     * @param float $gy   Group bounding box y
     * @param float $gw   Group bounding box width
     * @param float $gh   Group bounding box height
     * @return array      CSS padding-* properties (may be empty)
     */
    private function computeGroupPadding(array $pgChildNodes, float $gx, float $gy, float $gw, float $gh): array {
        if(empty($pgChildNodes)) return [];

        $minL = PHP_INT_MAX;
        $minT = PHP_INT_MAX;
        $minR = PHP_INT_MAX;
        $minB = PHP_INT_MAX;

        foreach($pgChildNodes as $child) {
            $cb  = $child['absoluteBoundingBox'] ?? [];
            $cx  = (float)($cb['x']      ?? 0);
            $cy  = (float)($cb['y']      ?? 0);
            $cw  = (float)($cb['width']  ?? 0);
            $ch  = (float)($cb['height'] ?? 0);

            $minL = min($minL, $cx - $gx);
            $minT = min($minT, $cy - $gy);
            $minR = min($minR, ($gx + $gw) - ($cx + $cw));
            $minB = min($minB, ($gy + $gh) - ($cy + $ch));
        }

        $result = [];
        if($minL > 1) $result['padding-left']   = (int)round($minL) . 'px';
        if($minT > 1) $result['padding-top']    = (int)round($minT) . 'px';
        if($minR > 1) $result['padding-right']  = (int)round($minR) . 'px';
        if($minB > 1) $result['padding-bottom'] = (int)round($minB) . 'px';

        return $result;
    }

    /**
     * Builds CSS flexbox layout styles from explicit Figma auto-layout properties.
     *
     * @param array  $node        The Figma node with auto-layout props
     * @param string $layoutMode  "HORIZONTAL" or "VERTICAL"
     * @return array              ['display' => 'flex', 'flex-direction' => ..., ...]
     */
    private function buildAutoLayoutStyles(array $node, string $layoutMode): array {
        $styles = ['display' => 'flex'];
        if($layoutMode === 'VERTICAL') {
            $styles['flex-direction'] = 'column';
        }

        $itemSpacing        = (int)($node['itemSpacing']        ?? 0);
        $counterAxisSpacing = (int)($node['counterAxisSpacing'] ?? 0);

        if($layoutMode === 'HORIZONTAL') {
            if($itemSpacing > 0 && $counterAxisSpacing > 0) {
                $styles['gap'] = $counterAxisSpacing . 'px ' . $itemSpacing . 'px';
            } elseif($itemSpacing > 0) {
                $styles['gap'] = $itemSpacing . 'px';
            } elseif($counterAxisSpacing > 0) {
                $styles['gap'] = $counterAxisSpacing . 'px';
            } else {
                $styles['gap'] = '0';
            }
        } else {
            if($itemSpacing > 0 && $counterAxisSpacing > 0) {
                $styles['gap'] = $itemSpacing . 'px ' . $counterAxisSpacing . 'px';
            } elseif($itemSpacing > 0) {
                $styles['gap'] = $itemSpacing . 'px';
            } elseif($counterAxisSpacing > 0) {
                $styles['gap'] = $counterAxisSpacing . 'px';
            } else {
                $styles['gap'] = '0';
            }
        }

        if(($node['layoutWrap'] ?? '') === 'WRAP') {
            $styles['flex-wrap'] = 'wrap';
        }

        $primaryAlign = $node['primaryAxisAlignItems'] ?? null;
        $primaryMap   = ['MIN' => 'flex-start', 'CENTER' => 'center', 'MAX' => 'flex-end', 'SPACE_BETWEEN' => 'space-between'];
        if($primaryAlign && isset($primaryMap[$primaryAlign])) {
            $styles['justify-content'] = $primaryMap[$primaryAlign];
        }

        $counterAlign = $node['counterAxisAlignItems'] ?? null;
        $counterMap   = ['MIN' => 'flex-start', 'CENTER' => 'center', 'MAX' => 'flex-end', 'BASELINE' => 'baseline'];
        if($counterAlign && isset($counterMap[$counterAlign])) {
            $styles['align-items'] = $counterMap[$counterAlign];
        }

        if(($node['paddingLeft']   ?? 0) > 0) $styles['padding-left']   = (int)$node['paddingLeft']   . 'px';
        if(($node['paddingRight']  ?? 0) > 0) $styles['padding-right']  = (int)$node['paddingRight']  . 'px';
        if(($node['paddingTop']    ?? 0) > 0) $styles['padding-top']    = (int)$node['paddingTop']    . 'px';
        if(($node['paddingBottom'] ?? 0) > 0) $styles['padding-bottom'] = (int)$node['paddingBottom'] . 'px';

        return $styles;
    }

    /**
     * Returns true if any two children share vertical space (i.e., are side-by-side).
     * Uses exact Y-extent overlap — no tolerance heuristic needed.
     *
     * @param array $children  Raw Figma child nodes with absoluteBoundingBox
     * @return bool
     */
    private function hasHorizontalPair(array $children): bool {
        foreach($children as $i => $a) {
            $ay1 = (float)($a['absoluteBoundingBox']['y']      ?? 0);
            $ay2 = $ay1 + (float)($a['absoluteBoundingBox']['height'] ?? 0);
            foreach($children as $j => $b) {
                if($i >= $j) continue;
                $by1 = (float)($b['absoluteBoundingBox']['y']      ?? 0);
                $by2 = $by1 + (float)($b['absoluteBoundingBox']['height'] ?? 0);
                if($ay1 < $by2 && $by1 < $ay2) return true;
            }
        }
        return false;
    }

    /**
     * Computes the average gap between adjacent children when sorted by X.
     * Used to determine the CSS `gap` value for flex-row containers.
     *
     * @param array $pgChildNodes  Filtered content children
     * @return float               Gap in px (≥ 0)
     */
    private function computeFlexGap(array $pgChildNodes): float {
        if(count($pgChildNodes) < 2) return 0.0;

        $items = $pgChildNodes;
        usort($items, static function($a, $b) {
            $ax = (float)($a['absoluteBoundingBox']['x'] ?? 0);
            $bx = (float)($b['absoluteBoundingBox']['x'] ?? 0);
            return $ax <=> $bx;
        });

        $gaps = [];
        for($i = 0; $i < count($items) - 1; $i++) {
            $rightEdge = (float)($items[$i]['absoluteBoundingBox']['x']     ?? 0)
                       + (float)($items[$i]['absoluteBoundingBox']['width'] ?? 0);
            $nextLeft  = (float)($items[$i + 1]['absoluteBoundingBox']['x'] ?? 0);
            $g = $nextLeft - $rightEdge;
            if($g > 0) $gaps[] = $g;
        }

        return !empty($gaps) ? array_sum($gaps) / count($gaps) : 0.0;
    }

    /**
     * Groups content children into Y-clusters. Children whose Y-centres are within
     * max(20px, groupH × 15%) of an existing cluster centre are merged into it.
     * Returns an array of clusters (each cluster is an array of children), sorted
     * top-to-bottom by cluster centre.
     *
     * 15% keeps same-row items together (centers differ by at most item-height-diff/2
     * ≈ a few px in practice) while keeping different rows separate even when a tall
     * item (e.g. 50px description) and the next row are close (19px row gap gives
     * a center-to-center distance of 50px+19px/2 ≈ 44px > 15% of 190px = 28.5px).
     *
     * @param array $pgChildNodes  Filtered content children
     * @param float $groupH        Group bounding-box height
     * @return array[]
     */
    private function computeYClusters(array $pgChildNodes, float $groupH): array {
        if(empty($pgChildNodes)) return [];

        $tolerance   = max(20.0, $groupH * 0.15);
        $clusterData = []; // [['center' => float, 'items' => array], ...]

        foreach($pgChildNodes as $child) {
            $bb      = $child['absoluteBoundingBox'] ?? [];
            $cy      = (float)($bb['y']      ?? 0);
            $ch      = (float)($bb['height'] ?? 0);
            $yCenter = ($ch > 0) ? ($cy + $ch / 2.0) : $cy;

            $matched = false;
            foreach($clusterData as &$cluster) {
                if(abs($yCenter - $cluster['center']) <= $tolerance) {
                    $cluster['center'] = ($cluster['center'] + $yCenter) / 2.0;
                    $cluster['items'][] = $child;
                    $matched = true;
                    break;
                }
            }
            unset($cluster);

            if(!$matched) {
                $clusterData[] = ['center' => $yCenter, 'items' => [$child]];
            }
        }

        usort($clusterData, static fn($a, $b) => $a['center'] <=> $b['center']);
        return array_column($clusterData, 'items');
    }

    /**
     * Computes the column gap for the first inline Y-cluster (the cluster with 2+ items).
     * Returns the average X-gap between adjacent children in that cluster.
     *
     * @param array $pgChildNodes  Filtered content children
     * @param float $groupH        Group bounding-box height
     * @return float               Column gap in px (≥ 0)
     */
    private function computeInlineClusterGap(array $pgChildNodes, float $groupH): float {
        foreach($this->computeYClusters($pgChildNodes, $groupH) as $cluster) {
            if(count($cluster) >= 2) return $this->computeFlexGap($cluster);
        }
        return 0.0;
    }

    /**
     * Computes the minimum vertical gap between consecutive children sorted by Y.
     * Used as the CSS row-gap for flex-wrap containers.
     *
     * @param array $pgChildNodes  Filtered content children
     * @param int   $defaultGap    Fallback when no positive gap is found
     * @return int                 Row gap in px (≥ 0)
     */
    private function computeChildRowGap(array $pgChildNodes, int $defaultGap): int {
        if(count($pgChildNodes) < 2) return $defaultGap;

        $sorted = $pgChildNodes;
        usort($sorted, static fn($a, $b) =>
            (float)($a['absoluteBoundingBox']['y'] ?? 0) <=> (float)($b['absoluteBoundingBox']['y'] ?? 0)
        );

        $minGap = PHP_INT_MAX;
        for($i = 0; $i < count($sorted) - 1; $i++) {
            $bottom = (float)($sorted[$i]['absoluteBoundingBox']['y']      ?? 0)
                    + (float)($sorted[$i]['absoluteBoundingBox']['height'] ?? 0);
            $top    = (float)($sorted[$i + 1]['absoluteBoundingBox']['y']  ?? 0);
            $gap    = $top - $bottom;
            if($gap >= 0) $minGap = min($minGap, (int)round($gap));
        }

        return ($minGap === PHP_INT_MAX) ? $defaultGap : max(0, $minGap);
    }

    /**
     * Builds y-cluster–based row data for an array of raw Figma child nodes.
     *
     * Items whose y-start values lie within $gutter tolerance of each other are
     * grouped into the same CSS grid row. A tall item whose bottom edge ($y + $h)
     * crosses one or more subsequent cluster centres receives a proportional
     * grid-row-end: span N.
     *
     * @param array $childNodes  Raw Figma nodes with absoluteBoundingBox
     * @param float $gutter      Clustering tolerance (px)
     * @return array             [idx => ['start' => int, 'span' => int]]
     */
    private function computeYClusterRowData(array $childNodes, float $gutter): array {
        if(empty($childNodes)) return [];

        $tolerance = max($gutter, 10.0);

        // Build clusters as [min, max] y-ranges rather than averaging scalar centres.
        // Averaging causes centre-drift: when several items with y values spread across
        // 30 px merge, the averaged centre can move more than $tolerance away from the
        // original first member, which then fails the lookup check below.
        // Using ranges, the original member is always within [min-tol, max+tol].
        $clusters = []; // each entry: ['min' => float, 'max' => float]
        foreach($childNodes as $node) {
            $y       = (float)($node['absoluteBoundingBox']['y'] ?? 0);
            $matched = false;
            foreach($clusters as &$cl) {
                if($y >= $cl['min'] - $tolerance && $y <= $cl['max'] + $tolerance) {
                    $cl['min'] = min($cl['min'], $y);
                    $cl['max'] = max($cl['max'], $y);
                    $matched   = true;
                    break;
                }
            }
            unset($cl);
            if(!$matched) $clusters[] = ['min' => $y, 'max' => $y];
        }
        usort($clusters, static fn($a, $b) => $a['min'] <=> $b['min']);

        // Assign row-start (1-based cluster index) and span for each node
        $rowData = [];
        foreach($childNodes as $idx => $node) {
            $y    = (float)($node['absoluteBoundingBox']['y']      ?? 0);
            $h    = (float)($node['absoluteBoundingBox']['height'] ?? 0);
            $yEnd = $y + $h;

            $rowStartIdx = 0;
            foreach($clusters as $ci => $cl) {
                if($y >= $cl['min'] - $tolerance && $y <= $cl['max'] + $tolerance) {
                    $rowStartIdx = $ci;
                    break;
                }
            }

            $lastClusterIdx = $rowStartIdx;
            foreach($clusters as $ci => $cl) {
                if($ci > $rowStartIdx && $cl['min'] < $yEnd) {
                    $lastClusterIdx = $ci;
                }
            }

            $rowData[$idx] = [
                'start' => $rowStartIdx + 1,
                'span'  => $lastClusterIdx - $rowStartIdx + 1,
            ];
        }

        return $rowData;
    }

    /**
     * Parses pg_* children of a group, assigning explicit grid-row-start and
     * grid-row-end values.
     *
     * Row positions are derived from y-position clustering (same algorithm used
     * for direct frame children), which correctly handles heterogeneous layouts
     * where tiny text nodes coexist with large image blocks.
     *
     * @param array                      $childNodes    Raw Figma nodes (only pg_* ones)
     * @param FigmaGridCalculator|null   $calc          Grid calc to use for column positions
     * @param float                      $xOffset       Subtract from child x before calc (for sub-frames)
     * @param float                      $unitRowHeight Unused — kept for signature compat
     * @param float                      $gutter        Gap size in px (cluster tolerance)
     * @param bool                       $isMixedRow    Flex-wrap: cluster-aware sizing per child
     */
    private function parseGroupChildren(array $childNodes, ?FigmaGridCalculator $calc, float $xOffset = 0.0, float $unitRowHeight = 0.0, float $gutter = 20.0, bool $isMixedRow = false, bool $isFullBleedParent = false, bool $isAutoLayout = false, ?string $layoutMode = null): array {
        if(empty($childNodes)) return [];

        // Stamp each child with its original Figma children-array index.
        // Figma stacks later children on top of earlier ones. The grid-position
        // sort below loses that order; we preserve it so we can assign z-index
        // when two items share overlapping grid ranges.
        foreach($childNodes as $i => &$cn) {
            $cn['_origIdx'] = $i;
        }
        unset($cn);

        // For flex-wrap groups, sort by visual reading order: items sharing horizontal
        // space (Y-overlap) go left-to-right by X; different horizontal bands go top-to-bottom.
        // For grid groups, simple Y-then-X sort is fine (positions are CSS-explicit).
        if($isMixedRow) {
            $bands = [];
            foreach($childNodes as $child) {
                $cy1 = (float)($child['absoluteBoundingBox']['y']      ?? 0);
                $cy2 = $cy1 + (float)($child['absoluteBoundingBox']['height'] ?? 0);
                $placed = false;
                foreach($bands as &$band) {
                    foreach($band['items'] as $bi) {
                        $by1 = (float)($bi['absoluteBoundingBox']['y']      ?? 0);
                        $by2 = $by1 + (float)($bi['absoluteBoundingBox']['height'] ?? 0);
                        if($cy1 < $by2 && $by1 < $cy2) {
                            $band['items'][] = $child;
                            $band['minY']    = min($band['minY'], $cy1);
                            $placed = true;
                            break;
                        }
                    }
                    if($placed) break;
                }
                unset($band);
                if(!$placed) $bands[] = ['minY' => $cy1, 'items' => [$child]];
            }
            usort($bands, static fn($a, $b) => $a['minY'] <=> $b['minY']);
            $childNodes = [];
            foreach($bands as $band) {
                $row = $band['items'];
                usort($row, static fn($a, $b) =>
                    ($a['absoluteBoundingBox']['x'] ?? 0) <=> ($b['absoluteBoundingBox']['x'] ?? 0)
                );
                $childNodes = array_merge($childNodes, $row);
            }
        } elseif(!$isAutoLayout) {
            // Sort globally top-to-bottom, then left-to-right.
            // Auto-layout containers preserve their original Figma DOM order
            // (which matches the visual order defined by layoutMode).
            usort($childNodes, static function($a, $b) {
                $ay = $a['absoluteBoundingBox']['y'] ?? 0;
                $by = $b['absoluteBoundingBox']['y'] ?? 0;
                if($ay !== $by) return $ay <=> $by;
                $ax = $a['absoluteBoundingBox']['x'] ?? 0;
                $bx = $b['absoluteBoundingBox']['x'] ?? 0;
                return $ax <=> $bx;
            });
        }

        // Compute row positions via y-position clustering.
        // Items with similar y-start share a CSS grid row; a tall item whose
        // bottom edge crosses subsequent clusters gets grid-row-end: span N.
        $rowData = $this->computeYClusterRowData($childNodes, $gutter);

        // For flex-wrap groups, build a map from each child's "x,y" key to cluster info
        // so we can assign per-child widths based on whether the child is in an inline
        // (multi-item) cluster or a block (single-item) cluster.
        $clusterMap = [];
        if($isMixedRow) {
            $minY = PHP_FLOAT_MAX;
            $maxY = -PHP_FLOAT_MAX;
            foreach($childNodes as $cn) {
                $cbb  = $cn['absoluteBoundingBox'] ?? [];
                $cy   = (float)($cbb['y']      ?? 0);
                $ch   = (float)($cbb['height'] ?? 0);
                $minY = min($minY, $cy);
                $maxY = max($maxY, $cy + $ch);
            }
            $computedGroupH = max($maxY - $minY, 1.0);
            $yClusters = $this->computeYClusters($childNodes, $computedGroupH);
            foreach($yClusters as $cluster) {
                $isInline = count($cluster) >= 2;
                foreach($cluster as $clusterItem) {
                    $bb  = $clusterItem['absoluteBoundingBox'] ?? [];
                    $key = ($bb['x'] ?? 0) . ',' . ($bb['y'] ?? 0);
                    $clusterMap[$key] = ['isInline' => $isInline];
                }
            }
        }

        // ── Build result ────────────────────────────────────────────────────
        $result = [];
        $hasFlexGrowSibling = false;
        if($isAutoLayout && $layoutMode === 'HORIZONTAL') {
            foreach($childNodes as $cn) {
                if(((int)($cn['layoutGrow'] ?? 0) > 0)) {
                    $hasFlexGrowSibling = true;
                    break;
                }
            }
        }
        foreach($childNodes as $idx => $child) {
            $childName = $child['name'] ?? '';
            $childType = $child['type'] ?? '';
            $hint      = $this->templateHint($childName, $childType);

            // Track nodes with meaningful rotation (excluding floating-point -0°)
            if(abs((float)($child['rotation'] ?? 0)) > 0.5) {
                $this->rotatedNodeNames[] = $childName ?: $childType;
            }

            // Nested group: recurse via parseGroupNode to capture sub-children.
            // gridStyles are computed from the parent calc+xOffset so that column
            // placement is relative to the parent container, not the nested group's
            // own internal calc.
            // Exception: small INSTANCE/COMPONENT nodes that wrap a single image asset
            // (icon components like "Edit 7") are flattened directly to pg_image so they
            // render at their actual pixel size rather than as a group container.
            // Exception 2: INSTANCE/COMPONENT with a direct asset URL and no children —
            // these are exported image fills (mcp_image_url/mcp_svg_url on the node itself).
            if($this->isDirectAssetNode($child)) {
                $parsed = $this->parseChildNode($child, $calc, $xOffset);
                $parsed['templateHint'] = 'pg_image';
            } elseif($hint === 'pg_group' && $this->isSingleImageComponent($child)) {
                $parsed = $this->parseChildNode($this->flattenImageComponent($child), $calc, $xOffset);
            } elseif($hint === 'pg_group' && !empty($child['children'])) {
                $groupData = $this->parseGroupNode($child, true);
                $bbox      = $child['absoluteBoundingBox'] ?? [];
                $cx        = (float)($bbox['x'] ?? 0) - $xOffset;
                $cw        = (float)($bbox['width'] ?? 0);
                $parsed = [
                    'templateHint'      => 'pg_group',
                    'gridStyles'        => $calc ? $calc->getGridStyles($cx, $cw) : $this->fullWidthStyles(),
                    'groupLayoutStyles' => $groupData['groupLayoutStyles'],
                    'groupStyles'       => $groupData['groupStyles'],
                    'children'          => $groupData['children'],
                    'blockStyles'       => [],
                    'innerStyles'       => [],
                    'textAlign'         => null,
                    'html'              => null,
                    'plainText'         => null,
                    'imagePath'         => null,
                ];
                if($hasFlexGrowSibling && !empty($parsed['groupLayoutStyles'])
                    && ($parsed['groupLayoutStyles']['display'] ?? '') === 'flex') {
                    $parsed['groupLayoutStyles']['width'] = 'auto';
                }
            } else {
                $parsed = $this->parseChildNode($child, $calc, $xOffset);
                if($child['type'] === 'RECTANGLE' && empty($child['mcp_svg_url']) && empty($child['mcp_image_url'])) {
                    $parsed['templateHint'] = 'pg_group';
                }
                if(empty($parsed['blockStyles']) && empty($parsed['children'] ?? [])
                    && empty($parsed['plainText'] ?? '') && $parsed['imagePath'] === null) {
                    continue;
                }
            }

            if(!$isMixedRow && isset($rowData[$idx])) {
                $rs = $rowData[$idx]['start'];
                $sp = $rowData[$idx]['span'];
                // Emit explicit row-start so placement is independent of DOM order
                $parsed['gridStyles']['grid-row-start'] = (string)$rs;
                if($sp > 1) {
                    $parsed['gridStyles']['grid-row-end'] = 'span ' . $sp;
                }
            }

            // Apply flex child sizing for flex-wrap (isMixedRow) containers.
            if($isMixedRow) {
                $bb  = $child['absoluteBoundingBox'] ?? [];
                $tpl = $parsed['templateHint'] ?? '';

                // flex-wrap: use cluster membership to decide per-child sizing.
                $key  = ($bb['x'] ?? 0) . ',' . ($bb['y'] ?? 0);
                $info = $clusterMap[$key] ?? ['isInline' => false];

                if(!$info['isInline']) {
                    // Single-item (block) row — force full width to occupy its own row.
                    $parsed['gridStyles']['width'] = '100%';
                } else {
                    // Inline (multi-item) row — natural content sizing.
                    if(in_array($tpl, ['pg_image', 'pg_gallery'], true)) {
                        $imgW = (int)round((float)($bb['width'] ?? 0));
                        if($imgW > 0) {
                            $parsed['gridStyles']['min-width'] = $imgW . 'px';
                        }
                    }
                    // pg_group / text in inline clusters: keep natural content width.
                }
            }

            // Apply auto-layout child properties
            if($isAutoLayout) {
                $grow = (int)($child['layoutGrow'] ?? 0);
                if($grow > 0) {
                    $parsed['gridStyles']['flex-grow'] = (string)$grow;
                }
                $lAlign = $child['layoutAlign'] ?? 'INHERIT';
                if($lAlign === 'STRETCH') {
                    if($layoutMode === 'HORIZONTAL') {
                        $parsed['gridStyles']['align-self'] = 'stretch';
                        $parsed['gridStyles']['flex-grow']  = '1';
                        $cbb6 = $child['absoluteBoundingBox'] ?? [];
                        $fw = (int)round((float)($cbb6['width'] ?? 0));
                        if($fw > 0) {
                            $parsed['gridStyles']['flex-basis'] = $fw . 'px';
                        }
                    } else {
                        $parsed['gridStyles']['width'] = '100%';
                    }
                }

                // Images inside auto-layout flex: min-width from node width
                $tpl2 = $parsed['templateHint'] ?? '';
                if(in_array($tpl2, ['pg_image', 'pg_gallery'], true)) {
                    $cbb5 = $child['absoluteBoundingBox'] ?? [];
                    $imgW2 = (int)round((float)($cbb5['width'] ?? 0));
                    if($imgW2 > 0) {
                        $parsed['gridStyles']['min-width'] = $imgW2 . 'px';
                    }
                }
            }

            // Full-bleed parent: if this child also spans the full frame width, it must
            // escape the section's padding just like the section escaped the frame's.
            // Uses the same piccalilli "full-bleed" utility as the section itself:
            //   width: 100vw                  — always equals viewport width
            //   margin-left: calc(50% - 50vw) — self-calculating negative margin
            if($isFullBleedParent && $this->hasGrid && $this->calc) {
                $cbb = $child['absoluteBoundingBox'] ?? [];
                $cx  = (float)($cbb['x']     ?? 0);
                $cw  = (float)($cbb['width']  ?? 0);
                if(($cx >= -30.0 && $cx <= 5.0) && (($cx + $cw) >= $this->frameWidth - 30.0)) {
                    $vwStyles = [
                        'width'       => '100vw',
                        'margin-left' => 'calc(50% - 50vw)',
                    ];
                    if($parsed['templateHint'] === 'pg_group') {
                        $parsed['groupLayoutStyles'] = array_merge(
                            $parsed['groupLayoutStyles'] ?? [],
                            $vwStyles
                        );
                    } else {
                        $parsed['gridStyles'] = array_merge(
                            $parsed['gridStyles'] ?? [],
                            $vwStyles
                        );
                    }
                }
            }

            // Carry the original Figma children-array index so we can compute
            // z-index for overlapping items after the grid-position sort.
            $parsed['_origIdx'] = $child['_origIdx'] ?? $idx;

            $result[] = $parsed;
        }

        // ── Sort by grid position for accessibility ──────────────────────────
        // CSS grid uses explicit grid-row/column-start, so visual layout is
        // unaffected by DOM order. Sorting here makes screen readers traverse
        // items in the same order a sighted user sees them (row by row, left
        // to right within each row).
        // Flex-wrap groups already have correct DOM order (top-to-bottom, left-to-right)
        // and have no grid-row positions to sort by.
        if(!$isMixedRow) {
            usort($result, static function($a, $b) {
                $ar = (int)($a['gridStyles']['grid-row-start']    ?? 1);
                $br = (int)($b['gridStyles']['grid-row-start']    ?? 1);
                if($ar !== $br) return $ar <=> $br;
                $ac = (int)($a['gridStyles']['grid-column-start'] ?? 1);
                $bc = (int)($b['gridStyles']['grid-column-start'] ?? 1);
                return $ac <=> $bc;
            });
        }

        // ── Z-index for overlapping grid items ───────────────────────────────
        // When two siblings share both column and row grid ranges, the item
        // that was later in Figma's original children array should render on top
        // (Figma stacks by array order). Assign z-index: 1 to the topmost item.
        $resultCount = count($result);
        for($zi = 0; $zi < $resultCount; $zi++) {
            for($zj = $zi + 1; $zj < $resultCount; $zj++) {
                if($this->gridRangesOverlap($result[$zi], $result[$zj])) {
                    $topIdx = ($result[$zj]['_origIdx'] ?? $zj) > ($result[$zi]['_origIdx'] ?? $zi)
                        ? $zj : $zi;
                    $result[$topIdx]['blockStyles']['z-index'] = '1';
                    if(($result[$topIdx]['templateHint'] ?? '') === 'pg_group'
                        && !empty($result[$topIdx]['children'])) {
                        $this->stripChildZIndex($result[$topIdx]['children']);
                    }
                }
            }
        }

        // Clean internal _origIdx keys from the result
        foreach($result as &$r) unset($r['_origIdx']);
        unset($r);

        return $result;
    }

    private function parseChildNode(array $node, ?FigmaGridCalculator $calc = null, float $xOffset = 0.0): array {
        $calcToUse = $calc ?? ($this->hasGrid ? $this->calc : null);
        $bbox      = $node['absoluteBoundingBox'] ?? [];
        $x         = (float)($bbox['x']     ?? 0) - $xOffset;
        $w         = (float)($bbox['width'] ?? 0);
        $name      = $node['name']  ?? '';
        $type      = $node['type']  ?? '';
        $isText    = ($type === 'TEXT');

        $templateHint = $this->templateHint($name, $type);
        // INSTANCE/COMPONENT/FRAME with a direct asset URL and no children
        // are exported image fills, not group containers.
        if($this->isDirectAssetNode($node)) {
            $templateHint = 'pg_image';
        }
        $gridStyles   = $calcToUse ? $calcToUse->getGridStyles($x, $w) : $this->fullWidthStyles();
        $isImage      = ($templateHint === 'pg_image');

        $innerStyles = [];

        // Block-level visual styles — skipped for image blocks (the image asset
        // already embeds all fills, borders and effects from Figma).
        // Exception: border-radius cannot be embedded in an image file and must be
        // applied as CSS on the img element via innerStyles.
        // Exception 2: a RECTANGLE with no exported image asset (SOLID fill only,
        // no mcp_*_url) has no file to embed fills in — extract the full visual
        // styles so the block renders with background-color on the pgitem wrapper.
        if($isImage) {
            $hasAsset = !empty($node['mcp_image_url']) || !empty($node['mcp_svg_url']);
            if($type === 'RECTANGLE' && !$hasAsset) {
                $blockStyles = $this->extractBlockStyles($node, false);
            } else {
                $blockStyles = [];
                $br = $this->borderRadius($node);
                if($br !== null) {
                    $innerStyles['img'] = ['border-radius' => $br];
                }
            }
        } else {
            $blockStyles = $this->extractBlockStyles($node, $isText);
        }

        // Inner element styles and HTML content for text nodes
        $html           = null;
        $plainText      = null;
        $textAlign      = null;
        $leadingTrim    = null;
        $listSpacing    = 0.0;
        $hangingPunct   = false;
        $hangingList    = false;
        $hyperlink      = null;

        if($isText) {
            // Resolve textStyle class if the node references one (and global classes are enabled)
            $textStyleClass = '';
            $textStyleProps = [];
            if(!$this->skipTextStyles && !empty($node['textStyleId']) && isset($this->textStyleMap[$node['textStyleId']])) {
                $ts = $this->textStyleMap[$node['textStyleId']];
                $textStyleClass = $ts['className'];
                $textStyleProps = $ts['cssProps'];
            }

            $result         = $this->parseTextNode($node, $textStyleClass);
            $innerStyles    = $result['innerStyles'];
            $html           = $result['html'];
            $plainText      = $result['plainText'];
            $textAlign      = $result['textAlign'];
            $leadingTrim    = $result['leadingTrim'];
            $listSpacing    = $result['listSpacing'];
            $hangingPunct   = $result['hangingPunctuation'];
            $hangingList    = $result['hangingList'];
            $hyperlink      = $result['hyperlink'];

            // Strip textStyle-covered props so the class handles them (no duplication)
            if($textStyleClass !== '' && !empty($textStyleProps)) {
                foreach(array_keys($textStyleProps) as $prop) {
                    unset($blockStyles[$prop]);
                    foreach($innerStyles as $tag => &$tagProps) {
                        unset($tagProps[$prop]);
                    }
                    unset($tagProps);
                }
            }
        } else {
            $textStyleClass = '';
        }

        // Image asset path (prefer SVG)
        $imagePath = $this->resolveAssetPath($node);

        return [
            'id'             => $node['id'] ?? '',
            'templateHint'   => $templateHint,
            'gridStyles'     => $gridStyles,
            'blockStyles'    => $blockStyles,
            'innerStyles'    => $innerStyles,
            'textAlign'      => $textAlign,
            'leadingTrim'    => $leadingTrim,
            'listSpacing'    => $listSpacing,
            'hangingPunctuation' => $hangingPunct,
            'hangingList'    => $hangingList,
            'hyperlink'      => $hyperlink,
            'html'           => $html,
            'plainText'      => $plainText,
            'imagePath'      => $imagePath,
            'textStyleClass' => $textStyleClass !== '' ? $textStyleClass : null,
            'x'              => $x,
            'width'          => $w,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Text parsing
    // ─────────────────────────────────────────────────────────────────────────

    private function parseTextNode(array $node, string $textStyleClass = ''): array {
        $segments  = $node['textSegments'] ?? [];
        $style     = $node['style']        ?? [];
        $textAlign = $this->extractTextAlign($style);

        $leadingTrim       = $style['leadingTrim']       ?? null;
        $listSpacing       = (float)($style['listSpacing']       ?? 0);
        $hangingPunctuation = !empty($style['hangingPunctuation']);
        $hangingList       = !empty($style['hangingList']);
        $hyperlink         = $style['hyperlink']          ?? null;

        $html        = '';
        $innerStyles = [];

        // ── Phase A: build flat token list ───────────────────────────────────
        // Tokens: ['type' => 'content', 'tag' => ..., 'text' => ..., 'seg' => ...]
        //         ['type' => 'newlines', 'count' => N]
        // Adjacent newline tokens are always collapsed (accumulated).
        $tokens = [];

        $addNewlines = function(int $count) use (&$tokens): void {
            if($count <= 0) return;
            $last = count($tokens) - 1;
            if($last >= 0 && $tokens[$last]['type'] === 'newlines') {
                $tokens[$last]['count'] += $count;
            } else {
                $tokens[] = ['type' => 'newlines', 'count' => $count];
            }
        };

        foreach($segments as $seg) {
            $chars = str_replace("\u{2028}", "\n", $seg['characters'] ?? '');

            // Determine list type from segment
            $listType = null;
            $lo = $seg['listOptions'] ?? null;
            if($lo && !empty($lo['type']) && strtoupper($lo['type']) !== 'NONE') {
                $listType = strtoupper($lo['type']) === 'ORDERED' ? 'ol' : 'ul';
            }

            // Count and strip leading newlines (merge into preceding token)
            $leading = strlen($chars) - strlen(ltrim($chars, "\n"));
            if($leading > 0) {
                $addNewlines($leading);
                $chars = substr($chars, $leading);
            }

            // Count and strip trailing newlines
            $trailing = strlen($chars) - strlen(rtrim($chars, "\n"));
            $core = $trailing > 0 ? substr($chars, 0, -$trailing) : $chars;

            if($core === '') {
                // Nothing left after stripping newlines
                $addNewlines($trailing);
                continue;
            }

            if($listType !== null) {
                // List segments: split on any \n sequence — each \n is a list-item boundary.
                $parts = preg_split('/(\n+)/', $core, -1, PREG_SPLIT_DELIM_CAPTURE);
                if($parts === false) $parts = [$core];
                for($p = 0; $p < count($parts); $p++) {
                    $part = $parts[$p];
                    if($p % 2 === 1) {
                        // Newline separator between list items
                        $addNewlines(strlen($part));
                    } else {
                        if($part === '') continue;
                        $partHtml = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
                        $tokens[] = [
                            'type'     => 'content',
                            'tag'      => $this->segmentToTag($seg),
                            'text'     => $partHtml,
                            'seg'      => $seg,
                            'listType' => $listType,
                        ];
                    }
                }
            } else {
                // Non-list: split on double-newlines (paragraph breaks), single \n → <br>
                $parts = explode("\n\n", $core);
                foreach($parts as $i => $part) {
                    if($i > 0) {
                        $addNewlines(2);
                    }
                    $partHtml = str_replace("\n", '<br>', htmlspecialchars($part, ENT_QUOTES, 'UTF-8'));

                    $tokens[] = [
                        'type'     => 'content',
                        'tag'      => $this->segmentToTag($seg),
                        'text'     => $partHtml,
                        'seg'      => $seg,
                        'listType' => null,
                    ];
                }
            }

            $addNewlines($trailing);
        }

        // ── Phase B: render tokens ────────────────────────────────────────────
        $tagStyles     = [];
        $tokenCount    = count($tokens);
        $listOpen      = null; // null, 'ul', or 'ol'
        $seenSpanDiffs = [];   // track span diffs to avoid property merging

        for($i = 0; $i < $tokenCount; $i++) {
            $tok = $tokens[$i];
            if($tok['type'] !== 'content') continue;

            $tag      = $tok['tag'];
            $seg      = $tok['seg'];
            $listType = $tok['listType'] ?? null;

            // Collect spans: per-segment text + styles within this merged group.
            // Adjacent same-tag, same-listType content tokens with no intervening
            // newlines belong to the same paragraph or list item.
            $spans    = [];
            $spans[]  = ['text' => $tok['text'], 'styles' => $this->segmentToStyles($seg), 'seg' => $seg];
            $spans[0]['styles']['margin-top'] = '0';

            while(isset($tokens[$i + 1]) && $tokens[$i + 1]['type'] === 'content'
                    && $tokens[$i + 1]['tag'] === $tag
                    && ($tokens[$i + 1]['listType'] ?? null) === $listType) {
                $i++;
                $sStyles = $this->segmentToStyles($tokens[$i]['seg']);
                $sStyles['margin-top'] = '0';
                $spans[] = ['text' => $tokens[$i]['text'], 'styles' => $sStyles, 'seg' => $tokens[$i]['seg']];
            }

            // Merge same-tag content tokens across single newlines.
            // Figma segments with font-weight/style differences but the same
            // fontSize produce separate tokens separated by a single \n —
            // merge them into one <p> with <br> so the bold/italic span diff
            // renders inline rather than as separate paragraphs.
            if ($i + 2 < $tokenCount
                && isset($tokens[$i + 1]) && $tokens[$i + 1]['type'] === 'newlines'
                && $tokens[$i + 1]['count'] === 1
                && isset($tokens[$i + 2]) && $tokens[$i + 2]['type'] === 'content'
                && $tokens[$i + 2]['tag'] === $tag
                && ($tokens[$i + 2]['listType'] ?? null) === $listType) {
                $i += 2;
                $sStyles = $this->segmentToStyles($tokens[$i]['seg']);
                $sStyles['margin-top'] = '0';
                $spans[] = ['text' => '<br>' . $tokens[$i]['text'], 'styles' => $sStyles, 'seg' => $tokens[$i]['seg']];
            }

            // Compute base styles by merging all spans (last wins), but exclude
            // font-weight / font-style / text-decoration so they never bleed
            // across different <li>/<p>/<h*> elements in the same block.
            $subOnly    = ['font-weight', 'font-style', 'text-decoration'];
            $baseStyles = [];
            foreach($spans as $sp) {
                foreach($sp['styles'] as $k => $v) {
                    if(in_array($k, $subOnly, true)) continue;
                    $baseStyles[$k] = $v;
                }
            }
            // Strip optional props absent from the last span so they don't
            // leak into parent CSS (e.g. text-transform only on a mid-span).
            $lastSpan = end($spans);
            foreach(array_keys($baseStyles) as $k) {
                if(!isset($lastSpan['styles'][$k])) {
                    unset($baseStyles[$k]);
                }
            }

            // Derive explicit margin-bottom from the next newlines token.
            $next = isset($tokens[$i + 1]) ? $tokens[$i + 1] : null;
            if($next && $next['type'] === 'newlines' && $next['count'] >= 2) {
                $blankLines  = $next['count'] - 1;
                $nextContent = isset($tokens[$i + 2]) ? $tokens[$i + 2] : null;
                $gapSeg      = ($nextContent && $nextContent['type'] === 'content') ? $nextContent['seg'] : $seg;
                $lhData      = $gapSeg['lineHeight'] ?? [];
                $lhValue     = (float)($lhData['value'] ?? 0);
                if($lhValue > 0) {
                    if(($lhData['unit'] ?? '') === 'PERCENT') {
                        $lineHeightPx = (float)($gapSeg['fontSize'] ?? 16) * ($lhValue / 100);
                    } else {
                        $lineHeightPx = $lhValue;
                    }
                    $baseStyles['margin-bottom'] = round($lineHeightPx * $blankLines) . 'px';
                }
            }

            // Open / close list wrapper when transitioning between list and non-list
            // or between different list types.
            if($listType !== $listOpen) {
                if($listOpen !== null) {
                    $html     .= '</' . $listOpen . '>';
                    $listOpen = null;
                }
                if($listType !== null) {
                    $html     .= '<' . $listType . '>';
                    $listOpen = $listType;
                }
            }

            if($listType) {
                $html .= '<li>';
            } else {
                $classAttr = $textStyleClass !== '' ? ' class="' . htmlspecialchars($textStyleClass, ENT_QUOTES) . '"' : '';
                $html     .= '<' . $tag . $classAttr . '>';
            }

            // Render each span. When a span's styles differ from base, wrap it in
            // <strong>, <em>, <u>, <s>, or <span> using single-level innerStyles
            // keys. At most one unique <span> diff is allowed per block to
            // avoid property merging across different parent contexts.
            // Emit spans with per-span hyperlink wrapping.
            // Adjacent same-URL spans share a single <a> tag to avoid underline gaps.
            $openHref = null;
            $hasAnyHl = false;
            foreach($spans as $sp) {
                $hl    = $sp['seg']['hyperlink'] ?? null;
                $hasHl = $hl && isset($hl['type']) && $hl['type'] === 'URL' && !empty($hl['value']);
                if($hasHl) $hasAnyHl = true;
                $spHref = $hasHl ? $hl['value'] : null;

                if($openHref !== null && $spHref !== $openHref) {
                    $html .= '</a>';
                    $openHref = null;
                }
                if($spHref !== null && $openHref === null) {
                    $html .= '<a href="' . htmlspecialchars($spHref, ENT_QUOTES) . '" rel="noopener">';
                    $openHref = $spHref;
                }

                $diff = $this->computeSpanDiff($sp['styles'], $baseStyles);
                if($diff !== null && count($spans) === 1 && !$hasHl) {
                    $nonSub = array_diff_key($diff, array_flip($subOnly));
                    if(empty($nonSub)) {
                        $tagStyles[$tag] = array_merge($tagStyles[$tag] ?? [], $diff);
                        $html .= $sp['text'];
                        continue;
                    }
                }
                if($diff === null) {
                    $html .= $sp['text'];
                } elseif(trim($sp['text']) === '') {
                    $html .= $sp['text'];
                } elseif($hasHl) {
                    $pBase  = $tagStyles[$tag] ?? [];
                    $hlDiff = $this->computeSpanDiff($sp['styles'], $pBase);
                    if($hlDiff !== null) {
                        $tagStyles['a'] = array_merge($tagStyles['a'] ?? [], $hlDiff);
                    }
                    $html .= $sp['text'];
                } else {
                    $wrapper = $this->spanWrapper($diff);
                    if($wrapper === 'span') {
                        $diffKey = json_encode($diff);
                        if(!empty($seenSpanDiffs) && !isset($seenSpanDiffs[$diffKey])) {
                            $html .= $sp['text'];
                            continue;
                        }
                        if(!isset($seenSpanDiffs[$diffKey])) {
                            $seenSpanDiffs[$diffKey] = true;
                            $tagStyles[$wrapper] = array_merge($tagStyles[$wrapper] ?? [], $diff);
                        }
                    } elseif($wrapper === 'u' || $wrapper === 's') {
                        $cleanDiff = $diff;
                        $hasExtraDeco = isset($diff['text-decoration-style'])
                                     || isset($diff['text-underline-offset'])
                                     || isset($diff['text-decoration-color'])
                                     || isset($diff['text-decoration-thickness']);
                        if(!$hasExtraDeco) {
                            unset($cleanDiff['text-decoration']);
                        }
                        if(!empty($cleanDiff)) {
                            $tagStyles[$wrapper] = array_merge($tagStyles[$wrapper] ?? [], $cleanDiff);
                        }
                    } else {
                        $tagStyles[$wrapper] = array_merge($tagStyles[$wrapper] ?? [], $diff);
                    }
                    $html .= '<' . $wrapper . '>' . $sp['text'] . '</' . $wrapper . '>';
                }
            }

            if($openHref !== null) {
                $html .= '</a>';
            }

            if($listType) {
                $html .= '</li>';
            } else {
                $html .= '</' . $tag . '>';
            }

            // Merge base styles into the parent tag (list wrapper gets container props)
            if($listType) {
                $wrapperStyles = $this->listWrapperStyles($seg);
                $tagStyles[$listType] = array_merge($tagStyles[$listType] ?? [], $wrapperStyles);
                $tagStyles['li']      = array_merge($tagStyles['li']      ?? [], $baseStyles);
            } elseif(!$hasAnyHl) {
                $tagStyles[$tag] = array_merge($tagStyles[$tag] ?? [], $baseStyles);
            }
        }

        // Close any still-open list wrapper
        if($listOpen !== null) {
            $html .= '</' . $listOpen . '>';
        }

        foreach($tagStyles as $tag => $props) {
            $innerStyles[$tag] = $props;
        }

        // Fallback: if no segments, use characters field
        if($html === '' && !empty($node['characters'])) {
            $classAttr = $textStyleClass !== '' ? ' class="' . htmlspecialchars($textStyleClass, ENT_QUOTES) . '"' : '';
            $html = '<p' . $classAttr . '>' . htmlspecialchars(trim($node['characters']), ENT_QUOTES, 'UTF-8') . '</p>';
        }

        // Plain text for pg_text field: soft breaks → \n, block boundaries → \n\n (empty line)
        $withBreaks = str_replace(['<br>', '<br/>', '<br />'], "\n", $html);
        $withBreaks = str_replace(
            ['</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</p>', '</li>'],
            "\n\n",
            $withBreaks
        );
        $plainText = trim(preg_replace('/\n{3,}/', "\n\n", strip_tags($withBreaks)));

        return compact('innerStyles', 'html', 'plainText', 'textAlign', 'leadingTrim', 'listSpacing', 'hangingPunctuation', 'hangingList', 'hyperlink');
    }

    /**
     * Returns true if two tokens both have no hyperlink, or both have
     * the same hyperlink — allowing adjacent same-style segments to merge.
     */
    private function hyperlinkMatch(array $a, array $b): bool {
        $ha = $a['seg']['hyperlink'] ?? null;
        $hb = $b['seg']['hyperlink'] ?? null;
        if($ha === null && $hb === null) return true;
        if($ha === null || $hb === null) return false;
        return ($ha['type'] ?? '') === ($hb['type'] ?? '')
            && ($ha['value'] ?? '') === ($hb['value'] ?? '');
    }

    /**
     * Returns CSS styles for the <ul> or <ol> wrapper element.
     * Extracts list-style-type, list-style-position, and padding-left
     * (from indentation level) from a list segment.
     */
    private function listWrapperStyles(array $seg): array {
        $styles = ['margin-top' => '0', 'margin-bottom' => '0'];
        $lo = $seg['listOptions'] ?? null;
        if($lo && !empty($lo['type']) && strtoupper($lo['type']) !== 'NONE') {
            $styles['list-style-type']     = strtoupper($lo['type']) === 'ORDERED' ? 'decimal' : 'disc';
            $styles['list-style-position'] = 'outside';
        }
        $indent = (float)($seg['indentation'] ?? 0);
        if($indent > 0) $styles['padding-left'] = round($indent * 20) . 'px';
        return $styles;
    }

    /**
     * Returns the HTML element to wrap a style-diff span.
     * - <strong> when the primary diff is font-weight: 700
     * - <em>     when the primary diff is font-style: italic
     * - <u>      for text-decoration:underline
     * - <s>      for text-decoration:line-through
     * - <span>   for all other diffs
     *
     * font-family is allowed as a secondary diff on any semantic element.
     */
    private function spanWrapper(array $diff): string {
        $primary = $diff;
        unset($primary['font-family']);
        if(count($primary) === 1) {
            if(isset($primary['font-weight'])     && $primary['font-weight']     === '700')   return 'strong';
            if(isset($primary['font-style'])      && $primary['font-style']      === 'italic') return 'em';
            if(isset($primary['text-decoration']) && $primary['text-decoration'] === 'underline') return 'u';
            if(isset($primary['text-decoration']) && $primary['text-decoration'] === 'line-through') return 's';
        }
        return 'span';
    }

    /**
     * Returns the style properties that differ between a span and the parent's
     * base styles. Also handles inverse diffs: when the parent has a prop that
     * the span lacks, the span needs an explicit default (e.g. parent has
     * font-weight:700, span is Regular → diff adds font-weight:400).
     *
     * Returns null when there are no meaningful differences.
     */
    private function computeSpanDiff(array $spanStyles, array $baseStyles): ?array {
        $diff = [];
        $skip = ['list-style-type', 'list-style-position'];

        foreach($spanStyles as $k => $v) {
            if(in_array($k, $skip, true)) continue;
            if(!isset($baseStyles[$k]) || $baseStyles[$k] !== $v) {
                $diff[$k] = $v;
            }
        }
        foreach($baseStyles as $k => $v) {
            if(in_array($k, $skip, true)) continue;
            if(isset($spanStyles[$k])) continue;
            $default = match($k) {
                'font-weight'     => '400',
                'font-style'      => 'normal',
                'text-decoration' => 'none',
                default           => null,
            };
            if($default !== null) {
                $diff[$k] = $default;
            }
        }

        unset($diff['margin-top']);

        return !empty($diff) ? $diff : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Style extraction
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Extracts visual styles from a decorator (non-pg_) shape node.
     * Applies fills as background-color, cornerRadius, strokes, opacity.
     */
    private function extractDecoratorStyles(array $node): array {
        $styles = [];

        // Background color (non-text fills)
        $bg = $this->fillsToColor($node['fills'] ?? [], false);
        if($bg !== null) $styles['background-color'] = $bg;

        // Border radius
        $br = $this->borderRadius($node);
        if($br !== null) $styles['border-radius'] = $br;

        // Border/stroke
        $border = $this->border($node);
        if($border !== null) $styles['border'] = $border;

        // Opacity
        if(isset($node['opacity']) && (float)$node['opacity'] < 1.0) {
            $styles['opacity'] = (string)round((float)$node['opacity'], 2);
        }

        // Blend mode
        if(isset($node['blendMode']) && $node['blendMode'] !== 'NORMAL' && $node['blendMode'] !== 'PASS_THROUGH') {
            $styles['mix-blend-mode'] = strtolower(str_replace('_', '-', $node['blendMode']));
        }

        // Effects (drop shadow, blur)
        foreach($this->effectStyles($node) as $prop => $val) {
            $styles[$prop] = $val;
        }

        return $styles;
    }

    /**
     * Extracts block-level visual styles for a pg_* node.
     * TEXT fills are treated as text color (not background).
     */
    private function extractBlockStyles(array $node, bool $isText): array {
        $styles = [];

        if(!$isText) {
            // Non-text: fills → background-color
            $bg = $this->fillsToColor($node['fills'] ?? [], false);
            if($bg !== null) $styles['background-color'] = $bg;
        }

        $br = $this->borderRadius($node);
        if($br !== null) $styles['border-radius'] = $br;

        $border = $this->border($node);
        if($border !== null) $styles['border'] = $border;

        if(isset($node['opacity']) && (float)$node['opacity'] < 1.0) {
            $styles['opacity'] = (string)round((float)$node['opacity'], 2);
        }

        if(isset($node['blendMode']) && $node['blendMode'] !== 'NORMAL' && $node['blendMode'] !== 'PASS_THROUGH') {
            $styles['mix-blend-mode'] = strtolower(str_replace('_', '-', $node['blendMode']));
        }

        foreach($this->effectStyles($node) as $prop => $val) {
            $styles[$prop] = $val;
        }

        return $styles;
    }

    private function borderRadius(array $node): ?string {
        $radii = $node['cornerRadii'] ?? null;
        $uniform = isset($node['cornerRadius']) ? (float)$node['cornerRadius'] : null;

        if($radii !== null) {
            $tl = (float)($radii['topLeft']     ?? 0);
            $tr = (float)($radii['topRight']    ?? 0);
            $bl = (float)($radii['bottomLeft']  ?? 0);
            $br = (float)($radii['bottomRight'] ?? 0);

            if($tl === $tr && $tr === $bl && $bl === $br) {
                return $tl > 0 ? round($tl) . 'px' : null;
            }
            // CSS order: TL TR BR BL (Figma is TL TR BL BR — swap last two!)
            return round($tl) . 'px ' . round($tr) . 'px ' . round($br) . 'px ' . round($bl) . 'px';
        }

        if($uniform !== null && $uniform > 0) {
            return round($uniform) . 'px';
        }

        return null;
    }

    private function border(array $node): ?string {
        if(empty($node['strokes'])) return null;
        $strokeWeight = (float)($node['strokeWeight'] ?? 1);
        foreach($node['strokes'] as $stroke) {
            if(($stroke['type'] ?? '') === 'SOLID' && isset($stroke['color'])) {
                $color = $this->rgba($stroke['color'], $stroke['opacity'] ?? 1.0);
                return round($strokeWeight) . 'px solid ' . $color;
            }
        }
        return null;
    }

    /**
     * Extracts a CSS border-{side} declaration from a Figma LINE node.
     * LINE nodes are 1D — they represent a single border on the parent group.
     * Side is determined by the line's rotation and position relative to parent.
     */
    private function extractLineBorder(array $line, float $px, float $py, float $pw, float $ph): array {
        $bb     = $line['absoluteBoundingBox'] ?? [];
        $lx     = (float)($bb['x'] ?? 0);
        $ly     = (float)($bb['y'] ?? 0);
        $rot    = (float)($line['rotation'] ?? 0);
        $weight = (float)($line['strokeWeight'] ?? 1);

        $color = null;
        foreach($line['strokes'] ?? [] as $stroke) {
            if(($stroke['type'] ?? '') === 'SOLID' && isset($stroke['color'])) {
                $color = $this->rgba($stroke['color'], $stroke['opacity'] ?? 1.0);
                break;
            }
        }
        if(!$color) return [];

        // ±90° rotation → vertical line → left or right border; else → top or bottom
        if(abs(abs($rot) - 90) < 10) {
            $side = abs($lx - $px) <= abs($lx - ($px + $pw)) ? 'left' : 'right';
        } else {
            $side = abs($ly - $py) <= abs($ly - ($py + $ph)) ? 'top' : 'bottom';
        }

        return ["border-{$side}" => round($weight) . 'px solid ' . $color];
    }

    private function effectStyles(array $node): array {
        $result = [];
        $shadows = [];
        foreach($node['effects'] ?? [] as $effect) {
            if(!($effect['visible'] ?? true)) continue;
            switch($effect['type'] ?? '') {
                case 'DROP_SHADOW':
                case 'INNER_SHADOW':
                    $c  = $effect['color']   ?? ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 0.5];
                    $ox = (float)($effect['offset']['x'] ?? 0);
                    $oy = (float)($effect['offset']['y'] ?? 0);
                    $bl = (float)($effect['radius']      ?? 0);
                    $sp = (float)($effect['spread']      ?? 0);
                    $inset = ($effect['type'] === 'INNER_SHADOW') ? ' inset' : '';
                    $shadows[] = round($ox) . 'px ' . round($oy) . 'px ' . round($bl) . 'px ' . round($sp) . 'px ' . $this->rgba($c) . $inset;
                    break;
                case 'LAYER_BLUR':
                    $result['filter'] = 'blur(' . round((float)($effect['radius'] ?? 0)) . 'px)';
                    break;
                case 'BACKGROUND_BLUR':
                    $result['backdrop-filter'] = 'blur(' . round((float)($effect['radius'] ?? 0)) . 'px)';
                    break;
            }
        }
        if(!empty($shadows)) $result['box-shadow'] = implode(', ', $shadows);
        return $result;
    }

    private function extractTextAlign(array $style): ?string {
        $align = strtoupper($style['textAlign'] ?? 'LEFT');
        if($align === 'CENTER') return 'center';
        if($align === 'RIGHT')  return 'right';
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Typography helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function segmentToTag(array $seg): string {
        $size = (int)round((float)($seg['fontSize'] ?? 16));
        return $this->sizeToTag[$size] ?? 'p';
    }

    private function segmentToStyles(array $seg): array {
        $styles = [];

        $ff = $seg['fontFamily'] ?? null;
        if($ff) $styles['font-family'] = $ff;

        $fs = (float)($seg['fontSize'] ?? 0);
        if($fs > 0) $styles['font-size'] = round($fs) . 'px';

        $rawWeight = $seg['fontWeight'] ?? 'Regular';
        if(stripos($rawWeight, 'italic') !== false) {
            $styles['font-style'] = 'italic';
            $rawWeight = trim(str_ireplace('italic', '', $rawWeight));
            if($rawWeight === '') $rawWeight = 'Regular';
        }
        $fw = $this->fontWeightNumeric($rawWeight);
        if($fw !== 400) $styles['font-weight'] = (string)$fw;

        // Text color (TEXT node fills)
        $color = $this->fillsToColor($seg['fills'] ?? [], true);
        if($color) $styles['color'] = $color;

        // Line height — normalized to unitless ratio
        $lh = $seg['lineHeight'] ?? [];
        if(isset($lh['unit']) && $lh['unit'] !== 'AUTO' && isset($lh['value'])) {
            $lhVal = (float)$lh['value'];
            $fs    = (float)($seg['fontSize'] ?? 16);
            if($lh['unit'] === 'PERCENT') {
                $styles['line-height'] = round($lhVal / 100, 2);
            } else {
                $styles['line-height'] = $fs > 0 ? round($lhVal / $fs, 2) : round($lhVal, 2);
            }
        }

        // Letter spacing
        $ls = $seg['letterSpacing'] ?? [];
        if(isset($ls['value']) && (float)$ls['value'] !== 0) {
            $unit = strtolower($ls['unit'] ?? 'PIXELS');
            if($unit === 'percent') {
                $styles['letter-spacing'] = round((float)$ls['value'] / 100, 3) . 'em';
            } else {
                $lsPx = (float)$ls['value'];
                $fs   = (float)($seg['fontSize'] ?? 16);
                $styles['letter-spacing'] = $fs > 0 ? round($lsPx / $fs, 4) . 'em' : '0';
            }
        }

        // Text decoration
        $td = strtolower($seg['textDecoration'] ?? 'NONE');
        $td = str_replace('strikethrough', 'line-through', $td);
        if($td !== 'none') $styles['text-decoration'] = $td;

        // Text decoration style (SOLID, DOTTED, DASHED, WAVY, DOUBLE)
        $tds = $seg['textDecorationStyle'] ?? null;
        if($tds && $td !== 'none' && !empty($tds['value'])) {
            $styles['text-decoration-style'] = strtolower($tds['value']);
        }

        // Text transform
        $tc = strtoupper($seg['textCase'] ?? 'ORIGINAL');
        if($tc === 'UPPER')    $styles['text-transform'] = 'uppercase';
        if($tc === 'LOWER')    $styles['text-transform'] = 'lowercase';
        if($tc === 'TITLE')    $styles['text-transform'] = 'capitalize';

        // List options (ordered / unordered / none)
        $lo = $seg['listOptions'] ?? null;
        if($lo && !empty($lo['type']) && strtoupper($lo['type']) !== 'NONE') {
            $styles['list-style-type'] = strtolower($lo['type']) === 'ordered' ? 'decimal' : 'disc';
            $styles['list-style-position'] = 'outside';
        }

        // Text indentation
        $indent = (float)($seg['indentation'] ?? 0);
        if($indent > 0) $styles['text-indent'] = round($indent) . 'px';

        // List item spacing (vertical gap between list items)
        $ls = (float)($seg['listSpacing'] ?? 0);
        if($ls > 0) $styles['--list-spacing'] = round($ls) . 'px';

        // Font style fallback (more reliable than parsing fontWeight string for "italic")
        if(!isset($styles['font-style']) && isset($seg['fontStyle'])) {
            if(strtoupper($seg['fontStyle']) === 'ITALIC') {
                $styles['font-style'] = 'italic';
            }
        }

        // Leading trim (TextStyle-level, removes vertical padding above/below text)
        $lt = $seg['leadingTrim'] ?? null;
        if($lt && isset($lt['type']) && $lt['type'] !== 'NONE') {
            $styles['leading-trim'] = strtolower(str_replace('_', '-', $lt['type'])) === 'cap-height'
                ? 'cap-height' : 'normal';
            $styles['text-edge'] = 'cap alphabetic';
        }

        // Hanging punctuation (TextStyle-level)
        if(!empty($seg['hangingPunctuation'])) {
            $styles['hanging-punctuation'] = 'allow-end';
        }

        // Hanging list (TextStyle-level)
        if(!empty($seg['hangingList'])) {
            $styles['hanging-list'] = 'allow-end';
        }

        // Paragraph spacing (when segment-level, not just node-level)
        $ps = (float)($seg['paragraphSpacing'] ?? 0);
        if($ps > 0 && !isset($styles['margin-bottom'])) {
            $styles['margin-bottom'] = round($ps) . 'px';
        }

        // Paragraph indent (when segment-level)
        $pi = (float)($seg['paragraphIndent'] ?? 0);
        if($pi > 0) $styles['text-indent'] = round($pi) . 'px';

        return $styles;
    }

    private function fontWeightNumeric(string $name): int {
        static $map = [
            'Thin'        => 100,
            'ExtraLight'  => 200, 'Extra Light'  => 200,
            'Light'       => 300,
            'Regular'     => 400,
            'Medium'      => 500,
            'SemiBold'    => 600, 'Semi Bold'    => 600, 'Semibold' => 600,
            'Bold'        => 700, 'Fett'          => 700,
            'ExtraBold'   => 800, 'Extra Bold'   => 800,
            'Heavy'       => 900, 'Black'        => 900,
            'ExtraBlack'  => 900, 'Extra Black'  => 900, // CSS max; Figma 950 → 900
        ];
        return $map[$name] ?? 400;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Color / fill helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns rgba() from the first SOLID fill, or null.
     * @param bool $isText  If true, fills represent text color.
     *                      IMAGE fills are skipped (handled elsewhere).
     */
    private function fillsToColor(array $fills, bool $isText): ?string {
        foreach($fills as $fill) {
            if(!($fill['visible'] ?? true))       continue;
            if(($fill['type'] ?? '') !== 'SOLID') continue;
            if(!isset($fill['color']))             continue;
            return $this->rgba($fill['color'], $fill['opacity'] ?? 1.0);
        }
        return null;
    }

    private function rgba(array $color, float $opacity = 1.0): string {
        $r = (int)round(($color['r'] ?? 0) * 255);
        $g = (int)round(($color['g'] ?? 0) * 255);
        $b = (int)round(($color['b'] ?? 0) * 255);
        $a = isset($color['a']) ? (float)$color['a'] * $opacity : $opacity;
        $a = round($a, 2);
        return "rgba({$r}, {$g}, {$b}, {$a})";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Asset helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function resolveAssetPath(array $node): ?string {
        // Prefer SVG over raster
        $rel = $node['mcp_svg_url'] ?? $node['mcp_image_url'] ?? null;
        if(!$rel) return null;

        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $parts = explode('/', $rel);
        foreach($parts as $part) {
            if($part === '..') return null;
        }
        $rel = implode('/', $parts);

        $abs = $this->extractDir . $rel;
        $absReal = realpath($abs);
        $baseReal = realpath($this->extractDir);
        if($absReal === false || $baseReal === false
            || strpos($absReal, $baseReal) !== 0) {
            return null;
        }
        return is_file($absReal) ? $absReal : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Template name helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Maps a Figma node type to a PageGrid template name.
     * Used as a fallback when the layer name does not start with "pg_".
     */
    private function typeToTemplate(string $type): string {
        return match(strtoupper($type)) {
            'TEXT'                                                              => 'pg_editor',
            'IMAGE', 'RECTANGLE', 'ELLIPSE', 'REGULAR_POLYGON',
            'POLYGON', 'VECTOR', 'STAR', 'LINE', 'BOOLEAN_OPERATION'           => 'pg_image',
            default                                                             => 'pg_group',
        };
    }

    /**
     * Extracts the template hint from a Figma layer name.
     * Falls back to typeToTemplate() when name does not start with "pg_".
     * "pg_image (svg)"  → "pg_image"
     * "Rectangle"       → "pg_image"  (RECTANGLE type fallback)
     * "Group 3"         → "pg_group"  (GROUP type fallback)
     */
    private function templateHint(string $layerName, string $nodeType = ''): string {
        if(strpos($layerName, 'pg_') !== 0) return $this->typeToTemplate($nodeType);
        $word = strtok($layerName, ' '); // first word before any space
        return $word !== false ? $word : $layerName;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Row gap helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function calcRowGaps(array $groups): array {
        $gaps = [];
        for($i = 0; $i < count($groups) - 1; $i++) {
            $cur  = $groups[$i];
            $next = $groups[$i + 1];
            $gap  = ($next['y'] ?? 0) - (($cur['y'] ?? 0) + ($cur['height'] ?? 0));
            $gaps[] = max(0, (int)round($gap));
        }
        return $gaps;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Misc
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns true when two parsed items share both column and row ranges
     * in the CSS grid — i.e. they would overlap visually.
     */
    private function gridRangesOverlap(array $a, array $b): bool {
        $ags = $a['gridStyles'] ?? [];
        $bgs = $b['gridStyles'] ?? [];

        $ac1 = (int)($ags['grid-column-start'] ?? 1);
        $bc1 = (int)($bgs['grid-column-start'] ?? 1);
        $ac2 = $this->gridEnd($ags, 'column');
        $bc2 = $this->gridEnd($bgs, 'column');
        if($ac2 < $bc1 || $bc2 < $ac1) return false;

        // Items without explicit row-start cannot overlap (flex-wrap layout)
        if(!isset($ags['grid-row-start']) || !isset($bgs['grid-row-start'])) return false;

        $ar1 = (int)$ags['grid-row-start'];
        $br1 = (int)$bgs['grid-row-start'];
        $ar2 = $this->gridEnd($ags, 'row');
        $br2 = $this->gridEnd($bgs, 'row');
        return !($ar2 < $br1 || $br2 < $ar1);
    }

    /**
     * Returns the inclusive end coordinate of a grid range.
     * Handles 'span N', '-1' (full span), and plain integer values.
     */
    private function gridEnd(array $styles, string $dim = 'column'): int {
        $prefix = $dim === 'column' ? 'grid-column' : 'grid-row';
        $start  = (int)($styles[$prefix . '-start'] ?? 1);
        $end    = $styles[$prefix . '-end'] ?? '';
        if($end === '-1') return 999;
        if(preg_match('/^span (\d+)$/', $end, $m)) {
            return $start + (int)$m[1] - 1;
        }
        if($end !== '') return (int)$end;
        return $start;
    }

    /**
     * Strip z-index from all children of a group that itself has z-index.
     * The group's z-index cascades to descendants via CSS, so individual
     * child z-indexes are redundant.
     */
    private function stripChildZIndex(array &$children): void {
        foreach($children as &$child) {
            unset($child['blockStyles']['z-index']);
            if(($child['templateHint'] ?? '') === 'pg_group' && !empty($child['children'])) {
                $this->stripChildZIndex($child['children']);
            }
        }
        unset($child);
    }

    private function fullWidthStyles(): array {
        return [
            'grid-column-start' => '1',
            'grid-column-end'   => '-1',
        ];
    }

    /**
     * Recursively removes pg_group nodes that have no children and no visual
     * styles. Applied depth-first so that parent groups whose only children
     * were empty sub-groups are also dropped.
     */
    private function pruneEmptyGroups(array $items): array {
        $out = [];
        foreach($items as $item) {
            if(isset($item['children'])) {
                $item['children'] = $this->pruneEmptyGroups($item['children']);
            }
            $isGroup  = ($item['templateHint'] ?? '') === 'pg_group';
            $hasKids  = !empty($item['children']);
            $hasStyle = !empty($item['groupStyles']) || !empty($item['blockStyles']);
            if($isGroup && !$hasKids && !$hasStyle) continue;
            $out[] = $item;
        }
        return $out;
    }
}
