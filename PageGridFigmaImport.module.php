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
            'version'  => '0.1.0',
            'author'   => 'Jan Ploch, Claude AI',
            'summary'  => 'Import Figma ZIP exports and build PageGrid pages from the admin.',
            'icon'     => 'exchange',
            'requires' => ['FieldtypePageGrid'],
            'installs' => ['ProcessPageGridFigmaImport'],
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
        $fcStyles = [
            'display'               => 'grid',
            'grid-template-columns' => 'repeat(' . $parsed['gridCount'] . ', 1fr)',
            'column-gap'            => $parsed['gridGutter'] . 'px',
            'align-items'           => 'start',
        ];
        if($parsed['frameBackground']) {
            $fcStyles['background-color'] = $parsed['frameBackground'];
        }
        if($parsed['framePadding'] > 0) {
            $fcStyles['padding'] = $parsed['framePadding'] . 'px';
        }
        $pagegrid->setStyles($fc, $fcStyles);

        // Row gap for grid mode — set once on the container
        if(!$isBlockMode && !empty($parsed['rowGaps'])) {
            $rowGap = $this->calcRowGap($parsed['rowGaps']);
            if($rowGap > 0) {
                $pagegrid->setStyles($fc, ['row-gap' => $rowGap . 'px']);
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
                    $cssGen->addBlock($style['className'], $style['cssProps']);
                    $textStylesInCss++;
                }
            }
        }

        // ── 7b. Process groups ────────────────────────────────────────────
        foreach($parsed['groups'] as $i => $groupData) {

            // Direct-frame content blocks (TEXT, ELLIPSE, etc. not inside any GROUP):
            // place them straight into the field container without a pg_group wrapper.
            if(!empty($groupData['isDirectBlock'])) {
                $this->createBlock($groupData, $fc, $pagegrid, $mode, $cssGen, $extractDir);
                continue;
            }

            $group = $pagegrid->addItem('pg_group', $fc);
            if(!$group) continue;

            // Grid position (always in metadata)
            $pagegrid->setStyles($group, $groupData['gridStyles']);

            // Layout styles: display:grid/block, grid-template-columns, gap — always metadata
            if(!empty($groupData['groupLayoutStyles'])) {
                $pagegrid->setStyles($group, $groupData['groupLayoutStyles']);
            }

            // Row gap in block mode (not last group)
            if($isBlockMode && $i < $groupCount - 1 && isset($parsed['rowGaps'][$i])) {
                $gap = (int)$parsed['rowGaps'][$i];
                if($gap > 0) {
                    $pagegrid->setStyles($group, ['margin-bottom' => $gap . 'px']);
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

        // Skip image/gallery blocks with no valid asset on disk — don't create an empty block.
        if(in_array($templateName, ['pg_image', 'pg_gallery'], true)) {
            $imagePath = $childData['imagePath'] ?? null;
            if(!$imagePath || !is_file($imagePath)) return;
        }

        $block = $pagegrid->addItem($templateName, $parent);
        if(!$block) {
            $this->warnings[] = "Could not create block '{$childData['templateHint']}'.";
            return;
        }

        // Grid position (always metadata)
        $pagegrid->setStyles($block, $childData['gridStyles']);

        // Nested group — apply group styles and recurse into sub-children.
        // The presence of a 'children' key signals this is a nested pg_group.
        if(!empty($childData['children'])) {
            if(!empty($childData['groupLayoutStyles'])) {
                $pagegrid->setStyles($block, $childData['groupLayoutStyles']);
            }
            if(!empty($childData['groupStyles'])) {
                if($mode === 'A') {
                    $pagegrid->setStyles($block, $childData['groupStyles']);
                } else {
                    $cssGen->addBlock($block->name, $childData['groupStyles']);
                }
            }
            foreach($childData['children'] as $nestedChild) {
                $this->createBlock($nestedChild, $block, $pagegrid, $mode, $cssGen, $extractDir);
            }
            return;
        }

        // Collect visual block styles
        $blockVisual = $childData['blockStyles'];
        if($childData['textAlign']) {
            $blockVisual['text-align'] = $childData['textAlign'];
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
                } else {
                    $pagegrid->setStyles($block, $styles, 'base', $tag, ['tagName' => $tag, 'cssClass' => '']);
                }
            }
        } else {
            // Mode B: visual styles → CSS output
            $innerForCss = [];
            foreach($childData['innerStyles'] as $tag => $styles) {
                if(empty($styles)) continue;
                if($isRootStyleBlock) {
                    // Merge typography into block-wrapper styles so CSS is .pg-text-123 { ... },
                    // but strip margin-* (meaningless on a block wrapper)
                    $filtered = array_filter($styles, fn($k) => strpos($k, 'margin') !== 0, ARRAY_FILTER_USE_KEY);
                    $blockVisual = array_merge($blockVisual, $filtered);
                } else {
                    $innerForCss[$tag] = $styles;
                }
            }
            $cssGen->addBlock($block->name, $blockVisual, $innerForCss);
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

        $zip = new \ZipArchive();
        $res = $zip->open($zipPath);
        if($res !== true) {
            throw new \RuntimeException("Cannot open ZIP file (ZipArchive error {$res}).");
        }
        $zip->extractTo($extractDir);
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
            if($cssProps === $stored) return 'unchanged';
            $pagegrid->setStyles($existing, $cssProps, 'base', 'pgitem', ['cssClass' => $className]);
            return 'updated';
        }

        $page           = new Page($this->templates->get('pg_container'));
        $page->parent   = $pgClasses;
        $page->name     = $className;
        $page->title    = $className;
        $page->save();

        $pagegrid->setStyles($page, $cssProps, 'base', 'pgitem', ['cssClass' => $className]);
        return 'created';
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
