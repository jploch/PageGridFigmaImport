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
            if($type === 'GROUP' || $type === 'FRAME' || $type === 'COMPONENT' || $type === 'INSTANCE') {
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

        // Determine calc for THIS group's children.
        // Sub-frames with their own layoutGrids use those; otherwise inherit the frame calc.
        $childCalc    = $this->hasGrid ? $this->calc : null;
        $childXOffset = 0.0; // x coords of children are frame-relative; sub-frames need an offset
        $hasOwnGrid   = false; // true only when this node defines its own layoutGrids
        if(!empty($node['layoutGrids'])) {
            $childCalc = new FigmaGridCalculator($node['layoutGrids'][0], $w);
            // FRAME, COMPONENT, INSTANCE nodes create their own coordinate space → children
            // already use local x. GROUP nodes share the root coordinate space → subtract group x.
            $childXOffset = ($node['type'] ?? '') !== 'GROUP' ? 0.0 : $x;
            $hasOwnGrid   = true;
        } elseif($isNested && $this->hasGrid) {
            // Nested group with no own layoutGrids: build a local calc using this group's width
            // so children are positioned relative to the group's own coordinate origin.
            // Same column count and gutter as the frame; no padding offset (the group has no margins).
            $localGrid = [
                'pattern'    => 'COLUMNS',
                'alignment'  => 'STRETCH',
                'gutterSize' => $this->calc->getGutterSize(),
                'offset'     => 0,
                'count'      => $this->calc->getCount(),
            ];
            $childCalc    = new FigmaGridCalculator($localGrid, $w);
            $childXOffset = $x; // children's x are frame-relative; subtract group x to normalise
        }

        // ── Collect visual decorator styles ────────────────────────────────
        $groupVisualStyles = [];
        $pgChildNodes      = [];

        // Pre-count decorator-eligible rectangles. A single rect can be lifted as a visual
        // decorator (background/border) onto the group. When there are 2+ such rects they
        // are independent content blocks, so none of them should be treated as a decorator.
        $decoratorRectCount = 0;
        foreach($node['children'] ?? [] as $child) {
            $cn = $child['name'] ?? '';
            $ct = $child['type'] ?? '';
            if($ct === 'RECTANGLE' && strpos($cn, 'pg_') !== 0 && empty($child['children'])
                && !(!empty($child['mcp_image_url']) || !empty($child['mcp_svg_url'])
                    || in_array('IMAGE', array_column($child['fills'] ?? [], 'type'), true))) {
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

            if($isDecoratorLine) {
                $lineStyles        = $this->extractLineBorder($child, $x, $y, $w, $h);
                $groupVisualStyles = array_merge($groupVisualStyles, $lineStyles);
            } elseif(!$isDecoratorRect && ($isPgNamed || $childType !== '')) {
                $pgChildNodes[] = $child;
            } else {
                $decoratorStyles   = $this->extractDecoratorStyles($child);
                $groupVisualStyles = array_merge($groupVisualStyles, $decoratorStyles);
            }
        }

        // Own fills (e.g. background on a FRAME-type pg_group)
        if(!empty($node['fills'])) {
            $ownStyles         = $this->extractDecoratorStyles($node);
            $groupVisualStyles = array_merge($ownStyles, $groupVisualStyles);
        }

        // ── Layout styles (always applied to metadata, not CSS output) ─────
        $groupLayoutStyles = [];
        if($isPgGroup && $childCalc) {
            $gutter        = (float)$childCalc->getGutterSize();
            $offset        = (float)$childCalc->getOffset();
            $unitRowHeight = $this->computeUnitRowHeight($pgChildNodes, $gutter);

            if($w < 250) {
                // Narrow containers (buttons, tags, badges) use block layout.
                // Padding is derived from content children's offsets inside the group.
                // FRAME, COMPONENT, INSTANCE nodes use local coordinates (lx=0, ly=0);
                // GROUP nodes share the root coordinate space (lx=$x, ly=$y).
                $isFrameNode = ($node['type'] ?? '') !== 'GROUP';
                $lx = $isFrameNode ? 0.0 : $x;
                $ly = $isFrameNode ? 0.0 : $y;
                $groupLayoutStyles = array_merge(
                    ['display' => 'block'],
                    $this->computeGroupPadding($pgChildNodes, $lx, $ly, $w, $h)
                );
            } else {
                $groupLayoutStyles = [
                    'display'               => 'grid',
                    'grid-template-columns' => 'repeat(' . $childCalc->getCount() . ', 1fr)',
                    'gap'                   => (int)$gutter . 'px',
                    'align-items'           => 'start',
                ];
                if($hasOwnGrid && $offset > 0) {
                    $groupLayoutStyles['padding-left']  = (int)$offset . 'px';
                    $groupLayoutStyles['padding-right'] = (int)$offset . 'px';
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
            }
        } else {
            $gutter        = $this->hasGrid ? (float)$this->calc->getGutterSize() : 20.0;
            $unitRowHeight = 0.0;
            if(!$isPgGroup) {
                $groupLayoutStyles = ['display' => 'block'];
            }
        }

        // ── Parse pg_* children with row-span assignment ───────────────────
        $pgChildren = $this->parseGroupChildren($pgChildNodes, $childCalc, $childXOffset, $unitRowHeight, $gutter);

        // For narrow groups (e.g. buttons: background rect + single label), force the one
        // content child to fill the full group width. Wide groups (≥ 250 px) keep the
        // child's naturally calculated column position — overriding it would be wrong.
        if($w < 250 && count($pgChildNodes) === 1 && !empty($pgChildren)) {
            $pgChildren[0]['gridStyles']['grid-column-start'] = '1';
            $pgChildren[0]['gridStyles']['grid-column-end']   = '-1';
        }

        return [
            'id'               => $node['id'] ?? '',
            'name'             => $groupName,
            'y'                => $y,
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

        // Build sorted list of representative y-values (cluster centres)
        $clusters = [];
        foreach($childNodes as $node) {
            $y = (float)($node['absoluteBoundingBox']['y'] ?? 0);
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

        // Assign row-start (1-based cluster index) and span for each node
        $rowData = [];
        foreach($childNodes as $idx => $node) {
            $y    = (float)($node['absoluteBoundingBox']['y']      ?? 0);
            $h    = (float)($node['absoluteBoundingBox']['height'] ?? 0);
            $yEnd = $y + $h;

            $rowStartIdx = 0;
            foreach($clusters as $ci => $cy) {
                if(abs($y - $cy) <= $tolerance) {
                    $rowStartIdx = $ci;
                    break;
                }
            }

            $lastClusterIdx = $rowStartIdx;
            foreach($clusters as $ci => $cy) {
                if($ci > $rowStartIdx && $cy < $yEnd) {
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
     */
    private function parseGroupChildren(array $childNodes, ?FigmaGridCalculator $calc, float $xOffset = 0.0, float $unitRowHeight = 0.0, float $gutter = 20.0): array {
        if(empty($childNodes)) return [];

        // Sort globally top-to-bottom, then left-to-right
        usort($childNodes, static function($a, $b) {
            $ay = $a['absoluteBoundingBox']['y'] ?? 0;
            $by = $b['absoluteBoundingBox']['y'] ?? 0;
            if($ay !== $by) return $ay <=> $by;
            $ax = $a['absoluteBoundingBox']['x'] ?? 0;
            $bx = $b['absoluteBoundingBox']['x'] ?? 0;
            return $ax <=> $bx;
        });

        // Compute row positions via y-position clustering.
        // Items with similar y-start share a CSS grid row; a tall item whose
        // bottom edge crosses subsequent clusters gets grid-row-end: span N.
        $rowData = $this->computeYClusterRowData($childNodes, $gutter);

        // ── Build result ────────────────────────────────────────────────────
        $result = [];
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
            if($hint === 'pg_group' && !empty($child['children'])) {
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
            } else {
                $parsed = $this->parseChildNode($child, $calc, $xOffset);
            }

            if(isset($rowData[$idx])) {
                $rs = $rowData[$idx]['start'];
                $sp = $rowData[$idx]['span'];
                // Emit explicit row-start so placement is independent of DOM order
                $parsed['gridStyles']['grid-row-start'] = (string)$rs;
                if($sp > 1) {
                    $parsed['gridStyles']['grid-row-end'] = 'span ' . $sp;
                }
            }
            $result[] = $parsed;
        }

        // ── Sort by grid position for accessibility ──────────────────────────
        // CSS grid uses explicit grid-row/column-start, so visual layout is
        // unaffected by DOM order. Sorting here makes screen readers traverse
        // items in the same order a sighted user sees them (row by row, left
        // to right within each row).
        usort($result, static function($a, $b) {
            $ar = (int)($a['gridStyles']['grid-row-start']    ?? 1);
            $br = (int)($b['gridStyles']['grid-row-start']    ?? 1);
            if($ar !== $br) return $ar <=> $br;
            $ac = (int)($a['gridStyles']['grid-column-start'] ?? 1);
            $bc = (int)($b['gridStyles']['grid-column-start'] ?? 1);
            return $ac <=> $bc;
        });

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
        $gridStyles   = $calcToUse ? $calcToUse->getGridStyles($x, $w) : $this->fullWidthStyles();
        $isImage      = ($templateHint === 'pg_image');

        // Block-level visual styles — skipped for image blocks (the image asset
        // already embeds all fills, borders and effects from Figma).
        $blockStyles = $isImage ? [] : $this->extractBlockStyles($node, $isText);

        // Inner element styles and HTML content for text nodes
        $innerStyles = [];
        $html        = null;
        $plainText   = null;
        $textAlign   = null;

        if($isText) {
            // Resolve textStyle class if the node references one (and global classes are enabled)
            $textStyleClass = '';
            $textStyleProps = [];
            if(!$this->skipTextStyles && !empty($node['textStyleId']) && isset($this->textStyleMap[$node['textStyleId']])) {
                $ts = $this->textStyleMap[$node['textStyleId']];
                $textStyleClass = $ts['className'];
                $textStyleProps = $ts['cssProps'];
            }

            $result      = $this->parseTextNode($node, $textStyleClass);
            $innerStyles = $result['innerStyles'];
            $html        = $result['html'];
            $plainText   = $result['plainText'];
            $textAlign   = $result['textAlign'];

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
            'html'           => $html,
            'plainText'      => $plainText,
            'imagePath'      => $imagePath,
            'textStyleClass' => $textStyleClass !== '' ? $textStyleClass : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Text parsing
    // ─────────────────────────────────────────────────────────────────────────

    private function parseTextNode(array $node, string $textStyleClass = ''): array {
        $segments  = $node['textSegments'] ?? [];
        $style     = $node['style']        ?? [];
        $textAlign = $this->extractTextAlign($style);

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
            $chars = $seg['characters'] ?? '';

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

            // Split on internal double-newlines (same-style paragraph breaks)
            $parts = explode("\n\n", $core);
            foreach($parts as $i => $part) {
                if($i > 0) {
                    // \n\n separator = 2 newlines between adjacent same-style paragraphs
                    $addNewlines(2);
                }
                // Replace remaining single \n with <br> (soft line break)
                $partHtml = str_replace("\n", '<br>', htmlspecialchars($part, ENT_QUOTES, 'UTF-8'));

                $tokens[] = [
                    'type' => 'content',
                    'tag'  => $this->segmentToTag($seg),
                    'text' => $partHtml,
                    'seg'  => $seg,
                ];
            }

            $addNewlines($trailing);
        }

        // ── Phase B: render tokens ────────────────────────────────────────────
        $tagStyles = [];
        $tokenCount = count($tokens);

        for($i = 0; $i < $tokenCount; $i++) {
            $tok = $tokens[$i];
            if($tok['type'] !== 'content') continue;

            $tag = $tok['tag'];
            $seg = $tok['seg'];

            $segStyles = $this->segmentToStyles($seg);

            // Always reset margin-top to suppress browser default stacking
            $segStyles['margin-top'] = '0';

            // Derive explicit margin-bottom from the next newlines token.
            // Use the *following* content segment's line height for the gap, not the
            // current one — the blank line height is defined by the style of the
            // paragraph that follows (e.g. body text after a large headline).
            $next = isset($tokens[$i + 1]) ? $tokens[$i + 1] : null;
            if($next && $next['type'] === 'newlines' && $next['count'] >= 2) {
                $blankLines   = $next['count'] - 1;
                $nextContent  = isset($tokens[$i + 2]) ? $tokens[$i + 2] : null;
                $gapSeg       = ($nextContent && $nextContent['type'] === 'content') ? $nextContent['seg'] : $seg;
                $lineHeightPx = (float)($gapSeg['lineHeight']['value'] ?? 0);
                if($lineHeightPx > 0) {
                    $segStyles['margin-bottom'] = round($lineHeightPx * $blankLines) . 'px';
                }
            }

            $classAttr = $textStyleClass !== '' ? ' class="' . htmlspecialchars($textStyleClass, ENT_QUOTES) . '"' : '';
            $html .= '<' . $tag . $classAttr . '>' . $tok['text'] . '</' . $tag . '>';

            // Merge styles per tag (last occurrence wins for same-tag duplicates)
            $tagStyles[$tag] = array_merge($tagStyles[$tag] ?? [], $segStyles);
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
            ['</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</p>'],
            "\n\n",
            $withBreaks
        );
        $plainText = trim(preg_replace('/\n{3,}/', "\n\n", strip_tags($withBreaks)));

        return compact('innerStyles', 'html', 'plainText', 'textAlign');
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

        // Line height
        $lh = $seg['lineHeight'] ?? [];
        if(isset($lh['unit']) && $lh['unit'] !== 'AUTO' && isset($lh['value'])) {
            if($lh['unit'] === 'PERCENT') {
                $styles['line-height'] = round((float)$lh['value']) . '%';
            } else {
                $styles['line-height'] = round((float)$lh['value']) . 'px';
            }
        }

        // Letter spacing
        $ls = $seg['letterSpacing'] ?? [];
        if(isset($ls['value']) && (float)$ls['value'] !== 0) {
            $unit = strtolower($ls['unit'] ?? 'PIXELS');
            if($unit === 'percent') {
                $styles['letter-spacing'] = round((float)$ls['value'] / 100, 3) . 'em';
            } else {
                $styles['letter-spacing'] = round((float)$ls['value'], 2) . 'px';
            }
        }

        // Text decoration
        $td = strtolower($seg['textDecoration'] ?? 'NONE');
        if($td !== 'none') $styles['text-decoration'] = $td;

        // Text transform
        $tc = strtoupper($seg['textCase'] ?? 'ORIGINAL');
        if($tc === 'UPPER')    $styles['text-transform'] = 'uppercase';
        if($tc === 'LOWER')    $styles['text-transform'] = 'lowercase';
        if($tc === 'TITLE')    $styles['text-transform'] = 'capitalize';

        return $styles;
    }

    private function fontWeightNumeric(string $name): int {
        static $map = [
            'Thin'        => 100,
            'ExtraLight'  => 200, 'Extra Light'  => 200,
            'Light'       => 300,
            'Regular'     => 400,
            'Medium'      => 500,
            'SemiBold'    => 600, 'Semi Bold'    => 600,
            'Bold'        => 700,
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
        $abs = $this->extractDir . $rel;
        return is_file($abs) ? $abs : null;
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
            $hasStyle = !empty($item['groupStyles']);
            if($isGroup && !$hasKids && !$hasStyle) continue;
            $out[] = $item;
        }
        return $out;
    }
}
