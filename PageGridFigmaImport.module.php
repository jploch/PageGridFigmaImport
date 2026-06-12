<?php namespace ProcessWire;

/**
 * PageGridFigmaImport — engine module.
 *
 * Imports a Figma ZIP export and creates a PageGrid page via the
 * ProcessWire API.  Works on shared hosting — no shell commands used.
 *
 * Auto-installs ProcessPageGridFigmaImport (the UI layer).
 *
 * @property InputfieldPageGrid $pagegrid
 */
class PageGridFigmaImport extends WireData implements Module {

    private $warnings = [];

    /**
     * Templates whose block wrapper IS the rendered element (no sub-elements).
     * For these, innerStyles are applied directly to the pgitem level instead
     * of as child-element rules.  Extend this list as new leaf-only templates
     * are added.
     */
    private static $rootStyleTemplates = ['pg_text'];

    // ─────────────────────────────────────────────────────────────────────────
    // Module info
    // ─────────────────────────────────────────────────────────────────────────

    public static function getModuleInfo(): array {
        return [
            'title'    => 'PageGrid Figma Import',
            'version'  => '0.1.9',
            'author'   => 'Jan Ploch, Claude AI',
            'summary'  => 'Import Figma ZIP exports and build PageGrid pages from the admin.',
            'icon'     => 'exchange',
            'requires' => ['FieldtypePageGrid>=2.2.156', 'PageGridBlocks'],
            'installs' => ['ProcessPageGridFigmaImport', 'FileValidatorZip'],
            'singular' => true,
            'autoload' => false,
        ];
    }

    public function init(): void {
        require_once __DIR__ . '/lib/FigmaGridCalculator.php';
        require_once __DIR__ . '/lib/FigmaCssGenerator.php';
        require_once __DIR__ . '/lib/FigmaParser.php';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Main import entry point.
     *
     * @param array $options {
     *   string  zipPath      Absolute path to the uploaded ZIP file.
     *   string  pageName     Title for the new page (also used as URL name).
     *   int     parentId     ID of the parent page.
     *   string  templateName Template name (must have a PageGrid field).
     *   string  stylingMode      'A' = all styles in metadata; 'B' = visual styles as CSS output.
     *   bool    skipTextStyles   When true, skip creating/updating global text style classes.
     * }
     * @return array {
     *   int    pageId    ID of the created page (0 on failure).
     *   string pageUrl   Editable URL in the admin.
     *   string cssOutput Generated CSS (mode B only).
     *   array  warnings  Non-fatal issues encountered.
     * }
     */
    public function import(array $options): array {
        $this->warnings = [];

        // ── 1. Extract ZIP ────────────────────────────────────────────────
        try {
            $extractDir = $this->extractZip($options['zipPath']);
        } catch(\Exception $e) {
            return $this->fail($e->getMessage());
        }

        // ── 2. Parse data.json ────────────────────────────────────────────
        $dataJsonPath = $extractDir . 'data.json';
        if(!is_file($dataJsonPath)) {
            return $this->fail('data.json not found in ZIP root.');
        }

        $data = json_decode(file_get_contents($dataJsonPath), true);
        if(!is_array($data)) {
            return $this->fail('data.json is not valid JSON.');
        }

        $skipTextStyles = !empty($options['skipTextStyles']);
        $parser = new FigmaParser($data, $extractDir, $skipTextStyles);
        $parsed = $parser->parse();

        // Collect any parser-level warnings (e.g. no layout grid found)
        if(!empty($parsed['warnings'])) {
            $this->warnings = array_merge($this->warnings, $parsed['warnings']);
        }

        // ── 3. Row placement ─────────────────────────────────────────────────
        // Sort direct blocks by (original row, column) before stripping row-start.
        // The parser assigns correct grid-row-start values via assignUnifiedRows().
        // Sorting by that pair before stripping ensures items from the same visual
        // row (same y-range) are adjacent in DOM order, so CSS Grid auto-placement
        // puts them together even when earlier items occupy overlapping columns.
        $rows = [];
        foreach($parsed['groups'] as $i => $g) {
            $r = (int)($g['gridStyles']['grid-row-start'] ?? 1);
            $c = (int)($g['gridStyles']['grid-column-start'] ?? 1);
            $rows[] = ['idx' => $i, 'row' => $r, 'col' => $c];
        }
        usort($rows, fn($a, $b) => $a['row'] <=> $b['row'] ?: $a['col'] <=> $b['col']);
        $sorted = [];
        foreach($rows as $r) {
            $sorted[] = $parsed['groups'][$r['idx']];
        }
        $parsed['groups'] = $sorted;

        // Top-level items (direct frame children) always use CSS grid auto-placement.
        foreach($parsed['groups'] as &$g) {
            unset($g['gridStyles']['grid-row-start']);
        }
        unset($g);
        // Auto Row Mode extends this to all nested items inside groups.
        if(!empty($options['autoRowMode'])) {
            $parsed['groups'] = $this->stripRowStart($parsed['groups']);
        }

        // ── 4. Detect pg-main display mode ───────────────────────────────
        $isBlockMode = $this->detectBlockMode();

        // ── 4. Create the target page ────────────────────────────────────
        $parent   = $this->pages->get((int)$options['parentId']);
        $template = $this->templates->get($options['templateName']);

        if(!$parent->id || !$template) {
            return $this->fail('Invalid parent page or template.');
        }

        $pageName = $this->sanitizer->pageName($options['pageName'], Sanitizer::translate);
        $newPage  = new Page($template);
        $newPage->parent = $parent;
        $newPage->name   = $pageName;
        $newPage->title  = $options['pageName'];
        $newPage->save();

        // ── 5. Get field container (auto-created on save) ─────────────────
        $pagegrid = $this->modules->get('InputfieldPageGrid');
        $fc       = $pagegrid->getFieldContainer($newPage);

        if(!$fc || !$fc->id) {
            $newPage->delete(true);
            return $this->fail('Could not get PageGrid field container. Does the template have a PageGrid field?');
        }

        // ── 6. Frame-level styles on the field container ──────────────────
        // Always set the full CSS grid spec on the wrapper so blocks placed
        // directly in the fc (direct-frame children) are positioned correctly.
        // $baseGap drives both row-gap on the container and the margin-bottom
        // subtraction per group: grid mode uses gridGutter so the gap is handled
        // by CSS; block mode uses 0 (neutralises the PageGrid 30px default).
        $baseGap = $isBlockMode ? 0 : (int)$parsed['gridGutter'];
        $fcStyles = [
            'display'               => 'grid',
            'grid-template-columns' => 'repeat(' . $parsed['gridCount'] . ', 1fr)',
            'column-gap'            => $parsed['gridGutter'] . 'px',
            'row-gap'               => $baseGap . 'px',
            'align-items'           => 'start',
        ];
        if($parsed['frameBackground']) {
            $fcStyles['background-color'] = $parsed['frameBackground'];
        }
        if($parsed['framePadding'] > 0) {
            $fcStyles['padding'] = $parsed['framePadding'] . 'px';
        }
        $pagegrid->setStyles($fc, $fcStyles);

        // Mobile breakpoint: cap padding to 20px if larger
        if($parsed['framePadding'] > 20) {
            $pagegrid->setStyles($fc, ['padding' => '20px'], 's');
        }

        // ── 6b. Detect flush children and apply negative margins ────────────
        $framePadding = (int)$parsed['framePadding'];
        $frameWidth   = (float)($data['absoluteBoundingBox']['width'] ?? 1280);
        $frameHeight  = (float)($data['absoluteBoundingBox']['height'] ?? 0);
        // Edge detection tolerance: fixed 5px (same as grid calculator's right-boundary anchor)
        $snapTolerance = 5.0;

        if($framePadding > 0) {
            foreach($parsed['groups'] as $i => $groupData) {
                $x = (float)($groupData['x'] ?? 0);
                $y = (float)($groupData['y'] ?? 0);
                $w = (float)($groupData['width'] ?? 0);
                $h = (float)($groupData['height'] ?? 0);

                // Full-bleed items (flush with both left and right) already have
                // their own horizontal edge-to-edge treatment — skip only the
                // left/right margins so the full-bleed technique takes precedence.
                $isFullBleed = (abs($x) < $snapTolerance && abs(($x + $w) - $frameWidth) < $snapTolerance);

                if(!$isFullBleed && abs($x) < $snapTolerance) {
                    $parsed['groups'][$i]['blockStyles']['margin-left'] = '-' . $framePadding . 'px';
                }
                if(abs($y) < $snapTolerance) {
                    $parsed['groups'][$i]['blockStyles']['margin-top'] = '-' . $framePadding . 'px';
                }
                if(!$isFullBleed && abs(($x + $w) - $frameWidth) < $snapTolerance) {
                    $parsed['groups'][$i]['blockStyles']['margin-right'] = '-' . $framePadding . 'px';
                }
                if($frameHeight > 0 && abs(($y + $h) - $frameHeight) < $snapTolerance) {
                    $parsed['groups'][$i]['blockStyles']['margin-bottom'] = '-' . $framePadding . 'px';
                }

                // When horizontal negative margins pull the item past the frame
                // edges, compensate width so the content area stays intact.
                // Full-bleed uses width:100vw + margin-left:calc(50%-50vw) instead.
                if(!$isFullBleed) {
                    $hComp = 0;
                    if(!empty($parsed['groups'][$i]['blockStyles']['margin-left']))  $hComp += $framePadding;
                    if(!empty($parsed['groups'][$i]['blockStyles']['margin-right'])) $hComp += $framePadding;
                    if($hComp > 0) {
                        $parsed['groups'][$i]['blockStyles']['width'] = 'calc(100% + ' . $hComp . 'px)';
                    }
                }
            }
        }

        // ── 7. Process groups and text styles ────────────────────────────
        $mode       = $options['stylingMode'] ?? 'A';
        $cssGen     = new FigmaCssGenerator();
        $groupCount = count($parsed['groups']);

        // ── 7a. Text styles → global CSS classes ─────────────────────────
        $textStylesCreated = [];
        $textStylesUpdated = [];
        $textStylesInCss   = 0;
        if(empty($options['skipTextStyles'])) {
            foreach($parsed['textStyles'] ?? [] as $style) {
                if($mode === 'A') {
                    $status = $this->createPgClass($style['className'], $style['name'], $style['cssProps']);
                    if($status === 'created') {
                        $textStylesCreated[] = $style['className'];
                    } elseif($status === 'updated') {
                        $textStylesUpdated[] = $style['className'];
                    }
                } else {
                    $capped = $this->capFontSizeOnMobile($style['cssProps']);
                    $cssGen->addBlock($style['className'], $style['cssProps'], [], $capped ? ['font-size' => $capped] : []);
                    $textStylesInCss++;
                }
            }
        }

        // ── 7b. Process groups ────────────────────────────────────────────
        foreach($parsed['groups'] as $i => $groupData) {

            // Direct-frame content blocks (TEXT, ELLIPSE, etc. not inside any GROUP):
            // place them straight into the field container without a pg_group wrapper.
            if(!empty($groupData['isDirectBlock'])) {
                if($i < $groupCount - 1 && isset($parsed['rowGaps'][$i])) {
                    $mb = max(0, (int)$parsed['rowGaps'][$i] - $baseGap);
                    if($mb > 0) {
                        $groupData['blockStyles']['margin-bottom'] = $mb . 'px';
                    }
                }
                $this->createBlock($groupData, $fc, $pagegrid, $mode, $cssGen, $extractDir);
                continue;
            }

            $group = $pagegrid->addItem('pg_group', $fc);
            if(!$group) continue;

            // Grid position (always in metadata)
            $pagegrid->setStyles($group, $groupData['gridStyles']);

            // Apply block-level visual styles (z-index, margins, etc.)
            if(!empty($groupData['blockStyles'])) {
                $pagegrid->setStyles($group, $groupData['blockStyles']);
            }

            // Layout styles: display:grid/block, grid-template-columns, gap — always metadata
            if(!empty($groupData['groupLayoutStyles'])) {
                $pagegrid->setStyles($group, $groupData['groupLayoutStyles']);
                $this->capPaddingOnMobile($pagegrid, $group, $groupData['groupLayoutStyles'], $groupData['blockStyles'] ?? []);
            }

            // Margin-bottom to recreate the Figma gap between groups.
            // Subtract $baseGap so the CSS row-gap (grid mode) or block default isn't doubled.
            if($i < $groupCount - 1 && isset($parsed['rowGaps'][$i])) {
                $mb = max(0, (int)$parsed['rowGaps'][$i] - $baseGap);
                if($mb > 0) {
                    $pagegrid->setStyles($group, ['margin-bottom' => $mb . 'px']);
                }
            }

            // Visual styles from decorator shapes (mode-dependent)
            if(!empty($groupData['groupStyles'])) {
                if($mode === 'A') {
                    $pagegrid->setStyles($group, $groupData['groupStyles']);
                } else {
                    $cssGen->addBlock($group->name, $groupData['groupStyles']);
                }
            }

            // Children
            foreach($groupData['children'] as $childData) {
                $this->createBlock($childData, $group, $pagegrid, $mode, $cssGen, $extractDir);
            }
        }

        // Clean up extracted files — no longer needed after import
        wireRmdir($extractDir, true);

        $adminUrl = $this->config->urls->admin . 'page/edit/?id=' . $newPage->id;

        return [
            'pageId'             => $newPage->id,
            'pageUrl'            => $adminUrl,
            'cssOutput'          => $mode === 'B' ? $cssGen->render() : '',
            'warnings'           => $this->warnings,
            'textStylesCreated'  => $textStylesCreated,
            'textStylesUpdated'  => $textStylesUpdated,
            'textStylesInCss'    => $textStylesInCss,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Block creation
    // ─────────────────────────────────────────────────────────────────────────

    private function createBlock(
        array $childData,
        Page $parent,
        InputfieldPageGrid $pagegrid,
        string $mode,
        FigmaCssGenerator $cssGen,
        string $extractDir
    ): void {
        $templateName = $this->resolveTemplate($childData['templateHint']);

        // Skip image/gallery blocks with no valid asset on disk —
        // unless the block has visual styles to render (e.g. a SOLID-filled RECTANGLE
        // with background-color, border-radius, etc. applied to the pgitem wrapper).
        if(in_array($templateName, ['pg_image', 'pg_gallery'], true)) {
            $imagePath = $childData['imagePath'] ?? null;
            if(!$imagePath || !is_file($imagePath)) {
                if(empty($childData['blockStyles'])) return;
            }
        }

        $block = $pagegrid->addItem($templateName, $parent);
        if(!$block) {
            $this->warnings[] = "Could not create block '{$childData['templateHint']}'.";
            return;
        }

        // Flex child: override core .pg-group { width: 100%; margin: 0 auto; }
        // so pg_group children of display:flex parents shrink to content width.
        if ($templateName === 'pg_group') {
            $parentPgStyles = $parent->meta('pg_styles');
            $parentDisplay = $parentPgStyles['pgitem']['breakpoints']['base']['css']['display'] ?? '';
            if ($parentDisplay === 'flex') {
                $hasOwnMargin = !empty($childData['blockStyles']['margin'])
                    || !empty($childData['groupStyles']['margin']);
                if (!$hasOwnMargin) {
                    $pagegrid->setStyles($block, [
                        'width'  => 'auto',
                        'margin' => '0',
                    ]);
                }
            }
        }

        // Grid position (always metadata)
        $pagegrid->setStyles($block, $childData['gridStyles']);

        // Nested group — apply group styles and recurse into sub-children.
        // The presence of a 'children' key signals this is a nested pg_group.
        if(!empty($childData['children'])) {
            if(!empty($childData['groupLayoutStyles'])) {
                $pagegrid->setStyles($block, $childData['groupLayoutStyles']);
                $this->capPaddingOnMobile($pagegrid, $block, $childData['groupLayoutStyles'], $childData['blockStyles'] ?? []);
            }
            if(!empty($childData['groupStyles'])) {
                if($mode === 'A') {
                    $pagegrid->setStyles($block, $childData['groupStyles']);
                } else {
                    $cssGen->addBlock($block->name, $childData['groupStyles']);
                }
            }
            if(!empty($childData['blockStyles'])) {
                $pagegrid->setStyles($block, $childData['blockStyles']);
            }
            foreach($childData['children'] as $nestedChild) {
                $this->createBlock($nestedChild, $block, $pagegrid, $mode, $cssGen, $extractDir);
            }
            return;
        }

        if($templateName === 'pg_group' && !empty($childData['groupStyles'])) {
            if($mode === 'A') {
                $pagegrid->setStyles($block, $childData['groupStyles']);
            } else {
                $cssGen->addBlock($block->name, $childData['groupStyles']);
            }
        }

        // Collect visual block styles
        $blockVisual = $childData['blockStyles'];
        if($childData['textAlign']) {
            $blockVisual['text-align'] = $childData['textAlign'];
        }
        if(!empty($childData['leadingTrim']) && isset($childData['leadingTrim']['type'])
                && $childData['leadingTrim']['type'] !== 'NONE') {
            $blockVisual['leading-trim'] = 'cap-height';
            $blockVisual['text-edge']    = 'cap alphabetic';
        }
        if(($childData['listSpacing'] ?? 0) > 0) {
            $blockVisual['--list-spacing'] = round($childData['listSpacing']) . 'px';
        }
        if(!empty($childData['hangingPunctuation'])) {
            $blockVisual['hanging-punctuation'] = 'allow-end';
        }
        if(!empty($childData['hangingList'])) {
            $blockVisual['hanging-list'] = 'allow-end';
        }

        // True for templates whose block wrapper IS the rendered element (no sub-elements).
        // innerStyles are merged into the pgitem level instead of emitted as child-tag rules.
        $isRootStyleBlock = in_array($templateName, self::$rootStyleTemplates, true);

        if($mode === 'A') {
            if(!empty($blockVisual)) {
                $pagegrid->setStyles($block, $blockVisual);
            }
            foreach($childData['innerStyles'] as $tag => $styles) {
                if(empty($styles)) continue;
                if($isRootStyleBlock) {
                    // Block IS the element — apply styles directly to the wrapper (pgitem),
                    // but strip margin-* (meaningless on a block wrapper)
                    $filtered = array_filter($styles, fn($k) => strpos($k, 'margin') !== 0, ARRAY_FILTER_USE_KEY);
                    if(!empty($filtered)) $pagegrid->setStyles($block, $filtered);
                    $capped = $this->capFontSizeOnMobile($filtered);
                    if($capped) $pagegrid->setStyles($block, ['font-size' => $capped], 's');
                } else {
                    $pagegrid->setStyles($block, $styles, 'base', $tag, ['tagName' => $tag, 'cssClass' => '']);
                    $capped = $this->capFontSizeOnMobile($styles);
                    if($capped) $pagegrid->setStyles($block, ['font-size' => $capped], 's', $tag, ['tagName' => $tag, 'cssClass' => '']);
                }
            }
        } else {
            // Mode B: visual styles → CSS output
            $innerForCss = [];
            $mobileStyles = [];
            $mobileInnerStyles = [];
            foreach($childData['innerStyles'] as $tag => $styles) {
                if(empty($styles)) continue;
                if($isRootStyleBlock) {
                    // Merge typography into block-wrapper styles so CSS is .pg-text-123 { ... },
                    // but strip margin-* (meaningless on a block wrapper)
                    $filtered = array_filter($styles, fn($k) => strpos($k, 'margin') !== 0, ARRAY_FILTER_USE_KEY);
                    $blockVisual = array_merge($blockVisual, $filtered);
                    $capped = $this->capFontSizeOnMobile($filtered);
                    if($capped) $mobileStyles['font-size'] = $capped;
                } else {
                    $innerForCss[$tag] = $styles;
                    $capped = $this->capFontSizeOnMobile($styles);
                    if($capped) $mobileInnerStyles[$tag]['font-size'] = $capped;
                }
            }
            $cssGen->addBlock($block->name, $blockVisual, $innerForCss, $mobileStyles, $mobileInnerStyles);
        }

        // Content
        $this->applyContent($block, $templateName, $childData);

        // Apply textStyle class to block wrapper — only for root-style blocks (pg_text etc.)
        // where the block IS the rendered element. For rich-text blocks (pg_editor) the class
        // is already injected into the inner HTML tags by parseTextNode(); applying it to the
        // pgitem wrapper too would be redundant and incorrect.
        if(!empty($childData['textStyleClass']) && $isRootStyleBlock) {
            $pagegrid->setStyles($block, [], 'base', 'pgitem', ['cssClasses' => $childData['textStyleClass']]);
        }
    }

    private function applyContent(Page $block, string $templateName, array $childData): void {
        $imagePath = $childData['imagePath'];

        // Image / gallery blocks
        if(in_array($templateName, ['pg_image', 'pg_gallery']) && $imagePath) {
            if(is_file($imagePath)) {
                $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
                $allowedExts = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];
                if(!in_array($ext, $allowedExts, true)) {
                    return;
                }

                $allowedMimes = [
                    'image/png', 'image/jpeg', 'image/gif',
                    'image/svg+xml', 'image/webp',
                ];
                $mime = mime_content_type($imagePath);
                if(!in_array($mime, $allowedMimes, true)) {
                    return;
                }

                if($ext === 'svg') {
                    $svg = file_get_contents($imagePath);
                    $svg = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $svg);
                    $svg = preg_replace('/\bon\w+\s*=\s*"[^"]*"/i', '', $svg);
                    $svg = preg_replace("/\bon\w+\s*=\s*'[^']*'/i", '', $svg);
                    $svg = preg_replace('/javascript\s*:/i', '', $svg);
                    file_put_contents($imagePath, $svg);
                }

                if($ext !== 'svg') {
                    $this->cropImageBorders($imagePath);
                }

                $block->of(false);
                $block->{$templateName}->add($imagePath);
                $block->save();
            } else {
                $this->warnings[] = "Image not found: {$imagePath}";
            }
            return;
        }

        // Editor (rich text) blocks
        if($templateName === 'pg_editor') {
            if($childData['html'] !== null && $childData['html'] !== '') {
                $block->setAndSave('pg_editor', $childData['html']);
            }
            return;
        }

        // Plain text blocks
        if($templateName === 'pg_text') {
            $text = $childData['plainText'] ?? '';
            if($text !== '') {
                // The renderer echoes raw into HTML (no nl2br), so convert newlines to <br>
                $block->setAndSave('pg_text', str_replace("\n", '<br>', $text));
            }
            return;
        }

        // HTML blocks
        if($templateName === 'pg_html') {
            if($childData['html'] !== null && $childData['html'] !== '') {
                $block->setAndSave('pg_html', $childData['html']);
            }
            return;
        }

        // Other templates (pg_video, pg_navigation, custom) — no content to set
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Template resolution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolves a template hint to an actually-installed template name.
     * Falls back gracefully for missing templates.
     */
    public function resolveTemplate(string $hint): string {
        if($this->templates->get($hint)) return $hint;

        // Known fallbacks
        $fallbacks = [
            'pg_navigation' => 'pg_editor',
            'pg_accordion'  => 'pg_group',
            'pg_slider'     => 'pg_group',
        ];

        if(isset($fallbacks[$hint])) {
            $fb = $fallbacks[$hint];
            $this->warnings[] = "Template '{$hint}' not installed — using '{$fb}' instead.";
            return $fb;
        }

        $this->warnings[] = "Unknown template '{$hint}' — using 'pg_editor' as fallback.";
        return 'pg_editor';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Template discovery (used by the UI)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns all templates that contain at least one PageGrid field.
     * @return Template[]
     */
    public function getPageGridTemplates(): array {
        $result = [];
        foreach($this->templates as $tpl) {
            foreach($tpl->fields as $field) {
                if($field->type instanceof FieldtypePageGrid) {
                    $result[] = $tpl;
                    break;
                }
            }
        }
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function detectBlockMode(): bool {
        $pgMain = $this->pages->get('name=pg-main, template=pg_container');
        if(!$pgMain->id) return false;
        $meta    = $pgMain->meta('pg_styles');
        $display = $meta['pgitem']['breakpoints']['base']['css']['display'] ?? 'grid';
        return $display === 'block';
    }

    private function calcRowGap(array $rowGaps): int {
        $rowGaps = array_filter($rowGaps, static fn($v) => $v > 0);
        if(empty($rowGaps)) return 0;
        return (int)round(array_sum($rowGaps) / count($rowGaps));
    }

    /**
     * Recursively removes grid-row-start from every item's gridStyles.
     * grid-row-end (span) is kept so multi-row spans are preserved.
     * Used by Auto Row Mode to let CSS grid auto-place items by DOM order.
     */
    private function stripRowStart(array $items): array {
        foreach($items as &$item) {
            unset($item['gridStyles']['grid-row-start']);
            if(!empty($item['children'])) {
                $item['children'] = $this->stripRowStart($item['children']);
            }
        }
        unset($item);
        return $items;
    }

    /**
     * Extracts the ZIP to a unique timestamped directory under site/assets/figma/.
     * @return string Absolute path with trailing slash.
     * @throws \RuntimeException
     */
    private function extractZip(string $zipPath): string {
        if(!is_file($zipPath)) {
            throw new \RuntimeException("ZIP file not found: {$zipPath}");
        }

        $baseDir = $this->config->paths->assets . 'figma/';
        if(!is_dir($baseDir)) mkdir($baseDir, 0755, true);

        $extractDir = $baseDir . date('YmdHis') . '/';
        mkdir($extractDir, 0755, true);
        $safeExtractDir = rtrim(realpath($extractDir) ?: $extractDir, '/') . '/';

        $zip = new \ZipArchive();
        $res = $zip->open($zipPath);
        if($res !== true) {
            throw new \RuntimeException("Cannot open ZIP file (ZipArchive error {$res}).");
        }

        if($this->modules->isInstalled('FileValidatorZip')) {
            $v = $this->modules->get('FileValidatorZip');
            $v->maxFiles = 500;
            $v->maxFileMegabytes = 20;
            $v->maxTotalMegabytes = 100;
            $v->maxCompRatio = 100;
            $v->maxDepth = 8;
            $v->minFiles = 1;
            $v->maxErrors = 10;
            $v->allowEncrypted = false;
            $v->requireFiles = ['data.json'];
            $v->fatalFiles = ['!\.(php|phtml|pl|py|sh|exe|bat|cmd|com|dll|so|msi)$!'];
            $v->setZipArchive($zip);
            if(!$v->isValid($zipPath)) {
                $errors = $v->errors();
                $zip->close();
                throw new \RuntimeException("Invalid ZIP file: {$errors}");
            }
        }

        $numFiles = $zip->numFiles;
        for($i = 0; $i < $numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if($entryName === false || $entryName === '') continue;

            $entryName = str_replace('\\', '/', $entryName);
            $parts = explode('/', $entryName);
            $safeParts = [];
            foreach($parts as $part) {
                if($part === '' || $part === '.') continue;
                if($part === '..') {
                    $zip->close();
                    throw new \RuntimeException("Path traversal detected in ZIP entry: {$entryName}");
                }
                $safeParts[] = str_replace(' ', '_', $part);
            }
            $safePath = implode('/', $safeParts);
            if($safePath === '') continue;

            $dest = $safeExtractDir . $safePath;

            if(substr($entryName, -1) === '/') {
                @mkdir($dest, 0755, true);
                continue;
            }

            $parentDir = dirname($dest);
            if(!is_dir($parentDir)) @mkdir($parentDir, 0755, true);

            $destReal = realpath($parentDir);
            $baseReal = realpath($safeExtractDir);
            if($destReal === false || $baseReal === false
                || strpos($destReal, $baseReal) !== 0) {
                $zip->close();
                throw new \RuntimeException("Path traversal blocked for: {$entryName}");
            }

            copy("zip://{$zipPath}#{$entryName}", $dest);
        }
        $zip->close();

        return $extractDir;
    }

    /**
     * Creates or updates a pg-classes page for a text style.
     * The CSS properties are stored in meta('pg_styles') — same format as pg-main.
     * Existing pages are updated with the latest CSS props from the import.
     */
    private function createPgClass(string $className, string $styleName, array $cssProps): string {
        $pgClasses = $this->pages->get('name=pg-classes, template=pg_container');
        if(!$pgClasses->id) return 'created';

        $pagegrid = $this->modules->get('InputfieldPageGrid');
        $existing = $pgClasses->child("name=$className, include=all");

        if($existing->id) {
            // Only write if CSS values actually changed
            $stored = $existing->meta('pg_styles')['pgitem']['breakpoints']['base']['css'] ?? [];
            if($cssProps === $stored) {
                $capped = $this->capFontSizeOnMobile($cssProps);
                if($capped) $pagegrid->setStyles($existing, ['font-size' => $capped], 's', 'pgitem', ['cssClass' => $className]);
                return 'unchanged';
            }
            $pagegrid->setStyles($existing, $cssProps, 'base', 'pgitem', ['cssClass' => $className]);
            $capped = $this->capFontSizeOnMobile($cssProps);
            if($capped) $pagegrid->setStyles($existing, ['font-size' => $capped], 's', 'pgitem', ['cssClass' => $className]);
            return 'updated';
        }

        $page           = new Page($this->templates->get('pg_container'));
        $page->parent   = $pgClasses;
        $page->name     = $className;
        $page->title    = $className;
        $page->save();

        $pagegrid->setStyles($page, $cssProps, 'base', 'pgitem', ['cssClass' => $className]);
        $capped = $this->capFontSizeOnMobile($cssProps);
        if($capped) $pagegrid->setStyles($page, ['font-size' => $capped], 's', 'pgitem', ['cssClass' => $className]);
        return 'created';
    }

    private function capFontSizeOnMobile(array $styles): ?string {
        $fs = $styles['font-size'] ?? '';
        if(preg_match('/^(\d+)px$/', $fs, $m) && (int)$m[1] > 100) {
            return '100px';
        }
        return null;
    }

    private function capPaddingOnMobile($pagegrid, Page $block, array $layoutStyles, array $blockStyles = []): void {
        $mobileS = [];
        foreach(['padding', 'padding-left', 'padding-right', 'padding-top', 'padding-bottom'] as $pp) {
            if(isset($layoutStyles[$pp]) && preg_match('/^(\d+)px$/', $layoutStyles[$pp], $m) && (int)$m[1] > 20)
                $mobileS[$pp] = '20px';
        }
        $hasNegLeft = false;
        $hasNegRight = false;
        foreach(['margin-left', 'margin-right', 'margin-top', 'margin-bottom'] as $mp) {
            if(isset($blockStyles[$mp]) && preg_match('/^-\d+/', $blockStyles[$mp])) {
                $mobileS[$mp] = '-20px';
                if($mp === 'margin-left') $hasNegLeft = true;
                if($mp === 'margin-right') $hasNegRight = true;
            }
        }
        if(!empty($mobileS) && isset($blockStyles['width']) && preg_match('/^calc\(100%\s*\+\s*\d+px\)$/', $blockStyles['width'])) {
            $mobileS['width'] = ($hasNegLeft && $hasNegRight) ? 'calc(100% + 40px)' : 'calc(100% + 20px)';
        }
        if(!empty($mobileS)) $pagegrid->setStyles($block, $mobileS, 's');
    }

    /**
     * Figma's node.exportAsync() renders images with a 1px anti-aliased border
     * around all four edges. This crops 1px off each side so the image is a clean
     * rectangle. If Figma ever fixes this rendering artifact, this function can be
     * removed and the call site above can be deleted.
     *
     * Skipped for images ≤ 100px wide (icons, small decorators).
     */
    private function cropImageBorders(string $path): void {
        $info = @getimagesize($path);
        if(!$info) return;
        $w = (int)$info[0];
        $h = (int)$info[1];
        $type = $info[2];
        if($w <= 100 || $h <= 2) return;

        $src = match($type) {
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            default => null,
        };
        if(!$src) return;

        $nw = $w - 2;
        $nh = $h - 2;
        $dst = imagecreatetruecolor($nw, $nh);
        if($type === IMAGETYPE_PNG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);
        }
        imagecopy($dst, $src, 0, 0, 1, 1, $nw, $nh);

        if($type === IMAGETYPE_PNG) {
            imagepng($dst, $path, 9);
        } else {
            imagejpeg($dst, $path, 100);
        }
        imagedestroy($src);
        imagedestroy($dst);
    }

    private function fail(string $message): array {
        return [
            'pageId'    => 0,
            'pageUrl'   => '',
            'cssOutput' => '',
            'warnings'  => [$message],
        ];
    }
}
