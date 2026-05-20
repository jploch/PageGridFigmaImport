<?php namespace ProcessWire;

/**
 * ProcessPageGridFigmaImport — Admin UI for the Figma importer.
 *
 * Creates an admin page under Setup → Figma Import.
 * Renders a form (upload + options) and delegates to PageGridFigmaImport.
 */
class ProcessPageGridFigmaImport extends Process {

    public static function getModuleInfo(): array {
        return [
            'title'       => 'Figma Import',
            'version'     => '0.1.1',
            'author'      => 'Jan Ploch, Claude AI',
            'summary'     => 'Import Figma ZIP exports into PageGrid pages.',
            'icon'        => 'exchange',
            'requires'    => ['PageGridFigmaImport'],
            'permission'  => 'pagegrid-figma-import',
            'permissions' => ['pagegrid-figma-import' => 'Use the Figma Import tool'],
            'singular'    => true,
            'autoload'    => false,
            'page'        => [
                'name'   => 'pagegrid-figma-importer',
                'title'  => 'Figma to PageGrid',
                'parent' => 'setup',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Main execute — show the form or handle submission
    // ─────────────────────────────────────────────────────────────────────────

    public function ___install(): void {
        parent::___install();
        // Place this page immediately after the pagegrid setup page
        $setup  = $this->pages->get('name=setup, template=admin');
        $pgPage = $setup->child('name=pagegrid, include=all');
        $myPage = $setup->child('name=pagegrid-figma-importer, include=all');
        if(!$pgPage->id || !$myPage->id) return;

        // Re-order in memory then re-save sort values
        $siblings = $setup->children('include=all, sort=sort');
        $siblings->insertAfter($myPage, $pgPage);
        $n = 0;
        foreach($siblings as $p) {
            if($p->sort !== $n) {
                $p->sort = $n;
                $p->save(['noHooks' => true, 'quiet' => true]);
            }
            $n++;
        }
    }

    public function ___execute(): string {
        $this->headline('Figma to PageGrid Importer');
        $this->browserTitle('Figma to PageGrid Importer');

        if($this->input->post('submit_import')) {
            return $this->processForm();
        }

        return $this->renderForm();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Form rendering
    // ─────────────────────────────────────────────────────────────────────────

    private function renderForm(array $errors = [], array $values = []): string {
        /** @var PageGridFigmaImport $importer */
        $importer = $this->modules->get('PageGridFigmaImport');

        $form = $this->modules->get('InputfieldForm');
        $form->attr('enctype', 'multipart/form-data');
        $form->attr('method', 'post');

        // ── ZIP file upload ───────────────────────────────────────────────
        $f = $this->modules->get('InputfieldMarkup');
        $f->attr('name', 'figma_zip_wrapper');
        $f->label       = 'Figma ZIP Export';
        $f->description = 'Upload the ZIP file exported from Figma using the "Figma to PageGrid Exporter" Plugin.';
        $f->value = '
            <label id="figma-zip-label" style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;padding:8px 16px;background:#f4f4f4;border:1px solid #ccc;border-radius:3px;font-size:14px;" 
                   onmouseover="this.style.background=\'#e8e8e8\'" onmouseout="this.style.background=\'#f4f4f4\'">
                <i class="fa fa-upload"></i>
                <span id="figma-zip-label-text">Choose ZIP file…</span>
                <input type="file" name="figma_zip" accept=".zip" required
                       style="position:absolute;opacity:0;width:0;height:0;"
                       onchange="document.getElementById(\'figma-zip-label-text\').textContent = this.files[0] ? this.files[0].name : \'Choose ZIP file…\';">
            </label>';
        $form->add($f);

        // ── Page name ─────────────────────────────────────────────────────
        /** @var InputfieldText $f */
        $f = $this->modules->get('InputfieldText');
        $f->attr('name', 'page_name');
        $f->attr('value', $values['page_name'] ?? '');
        $f->label    = 'Page Title';
        $f->required = true;
        $form->add($f);

        // ── Parent page ───────────────────────────────────────────────────
        /** @var InputfieldPageListSelect $f */
        $f = $this->modules->get('InputfieldPageListSelect');
        $f->attr('name', 'parent_id');
        $f->attr('value', (int)($values['parent_id'] ?? $this->config->rootPageID));
        $f->label    = 'Parent Page';
        $f->required = true;
        $form->add($f);

        // ── Template ──────────────────────────────────────────────────────
        $pgTemplates = $importer->getPageGridTemplates();
        if(empty($pgTemplates)) {
            $f = $this->modules->get('InputfieldMarkup');
            $f->attr('name', 'template_notice');
            $f->label = 'Template';
            $f->value = $this->_('<p class="notes">No templates with a PageGrid field found. Please create one first.</p>');
            $form->add($f);
        } else {
            /** @var InputfieldSelect $f */
            $f = $this->modules->get('InputfieldSelect');
            $f->attr('name', 'template_name');
            $f->attr('value', $values['template_name'] ?? 'pagegrid-page');
            $f->label    = 'Template';
            $f->required = true;
            foreach($pgTemplates as $tpl) {
                $f->addOption($tpl->name, $tpl->label ?: $tpl->name);
            }
            $form->add($f);
        }

        // ── Styling mode ──────────────────────────────────────────────────
        /** @var InputfieldRadios $f */
        $f = $this->modules->get('InputfieldRadios');
        $f->attr('name', 'styling_mode');
        $f->attr('value', $values['styling_mode'] ?? 'A');
        $f->label = 'Styling Mode';
        $f->addOption('A', 'Metadata — all styles saved in PageGrid (editable in the visual editor)');
        $f->addOption('B', 'CSS — grid positions in metadata, visual styles as copyable CSS output');
        $form->add($f);

        // ── Auto Row Mode ─────────────────────────────────────────────────
        /** @var InputfieldCheckbox $f */
        $f = $this->modules->get('InputfieldCheckbox');
        $f->attr('name', 'auto_row_mode');
        $f->attr('value', 1);
        if(!empty($values['auto_row_mode'])) $f->attr('checked', 'checked');
        $f->label       = 'Auto Row Mode';
        $f->description = 'Removes fixed row positions so blocks flow automatically. Quicker to re-arrange in the editor, but the imported layout may differ slightly from the Figma design.';
        $f->notes       = 'Row auto positions can also be set in the visual editor or via CSS after the import.';
        $form->add($f);

        // ── Skip Text Styles ──────────────────────────────────────────────
        /** @var InputfieldCheckbox $f */
        $f = $this->modules->get('InputfieldCheckbox');
        $f->attr('name', 'skip_text_styles');
        $f->attr('value', 1);
        if(!empty($values['skip_text_styles'])) $f->attr('checked', 'checked');
        $f->label       = 'Skip Global Text Style Classes';
        $f->description = 'Text Styles are reusable Figma typography definitions. By default, they import as shared global CSS classes to prevent duplicate code. Check this to skip global classes.';
        $form->add($f);

        // ── Submit ────────────────────────────────────────────────────────
        /** @var InputfieldSubmit $f */
        $f = $this->modules->get('InputfieldSubmit');
        $f->attr('name', 'submit_import');
        $f->attr('value', $this->_('Import Figma Design'));
        $form->add($f);

        return $this->alertHtml('danger', $errors) . $form->render();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Form processing
    // ─────────────────────────────────────────────────────────────────────────

    private function processForm(): string {
        // ── Save uploaded ZIP ─────────────────────────────────────────────
        $uploadDir = $this->config->paths->assets . 'figma/uploads/';
        if(!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $uploadedFile = $_FILES['figma_zip'] ?? null;
        if(empty($uploadedFile['tmp_name'])) {
            return $this->renderForm(['No ZIP file was uploaded.'], $this->postedValues());
        }
        if($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return $this->renderForm(['File upload failed (error code ' . $uploadedFile['error'] . ').'], $this->postedValues());
        }

        $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
        if($ext !== 'zip') {
            return $this->renderForm(['Only ZIP files are accepted.'], $this->postedValues());
        }

        $zipPath = $uploadDir . time() . '_figma.zip';
        if(!move_uploaded_file($uploadedFile['tmp_name'], $zipPath)) {
            return $this->renderForm(['Could not save uploaded file.'], $this->postedValues());
        }

        // ── Validate form fields ──────────────────────────────────────────
        $pageName     = $this->sanitizer->text($this->input->post('page_name'));
        $parentId     = (int)$this->input->post('parent_id');
        $templateName = $this->sanitizer->name($this->input->post('template_name'));
        $stylingMode    = strtoupper($this->sanitizer->name($this->input->post('styling_mode')));
        $autoRowMode    = (bool)$this->input->post('auto_row_mode');
        $skipTextStyles = (bool)$this->input->post('skip_text_styles');

        if(!$pageName)     return $this->renderForm(['Page title is required.']);
        if(!$parentId)     return $this->renderForm(['Please select a parent page.']);
        if(!$templateName) return $this->renderForm(['Please select a template.']);
        if(!in_array($stylingMode, ['A', 'B'])) $stylingMode = 'A';

        // ── Run import ────────────────────────────────────────────────────
        /** @var PageGridFigmaImport $importer */
        $importer = $this->modules->get('PageGridFigmaImport');
        $result = $importer->import([
            'zipPath'         => $zipPath,
            'pageName'        => $pageName,
            'parentId'        => $parentId,
            'templateName'    => $templateName,
            'stylingMode'     => $stylingMode,
            'autoRowMode'     => $autoRowMode,
            'skipTextStyles'  => $skipTextStyles,
        ]);

        // Clean up uploaded ZIP after extraction
        if(is_file($zipPath)) @unlink($zipPath);

        // ── Render result ─────────────────────────────────────────────────
        if(!$result['pageId']) {
            return $this->renderForm($result['warnings'], $this->postedValues());
        }

        return $this->renderResult($result, $stylingMode);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Result rendering
    // ─────────────────────────────────────────────────────────────────────────

    private function renderResult(array $result, string $stylingMode): string {
        $pageId      = (int)$result['pageId'];
        $page        = $this->pages->get($pageId);
        $adminUrl    = $this->config->urls->admin;
        $pageEditUrl = $page->id ? $page->editUrl() : ($adminUrl . 'page/edit/?id=' . $pageId);
        $viewUrl     = $page->id ? $page->url : '';

        $out = '<div style="max-width:900px;">';

        // Success banner
        $out .= $this->alertHtml('success', 'Import successful!');

        // Text style class summary (mode A)
        $styleCreated = $result['textStylesCreated'] ?? [];
        $styleUpdated = $result['textStylesUpdated'] ?? [];
        if(!empty($styleCreated)) {
            $names = implode(', ', array_map(fn($n) => '<strong>' . $n . '</strong>', $styleCreated));
            $count = count($styleCreated);
            $out .= $this->alertHtml('success', $count . ' text style ' . ($count === 1 ? 'class' : 'classes') . ' created: ' . $names);
        }
        if(!empty($styleUpdated)) {
            $names = implode(', ', array_map(fn($n) => '<strong>' . $n . '</strong>', $styleUpdated));
            $count = count($styleUpdated);
            $out .= $this->alertHtml('success', $count . ' text style ' . ($count === 1 ? 'class' : 'classes') . ' updated: ' . $names);
        }

        // Text style summary (mode B)
        $styleInCss = $result['textStylesInCss'] ?? 0;
        if($styleInCss > 0) {
            $out .= $this->alertHtml('success', $styleInCss . ' text style ' . ($styleInCss === 1 ? 'class' : 'classes') . ' included in CSS output.');
        }

        // Warnings — UIkit alert banners inside the result HTML
        if(!empty($result['warnings'])) {
            $out .= $this->alertHtml('warning', $result['warnings']);
        }

        // Links
        $out .= '<p>';
        $out .= '<a class="ui-button" href="' . $this->sanitizer->entities($pageEditUrl) . '"><i class="fa fa-edit"></i> Open in PageGrid</a>';
        if($viewUrl) {
            $out .= ' &nbsp;<a class="ui-button" href="' . $this->sanitizer->entities($viewUrl) . '" target="_blank"><i class="fa fa-eye"></i> View Page</a>';
        }
        $out .= '</p>';

        // CSS output (mode B)
        if($stylingMode === 'B' && $result['cssOutput']) {
            $out .= '<h3>Generated CSS</h3>';
            $out .= '<p class="notes">Copy this CSS into your stylesheet (e.g. <code>site/templates/styles/</code>).</p>';
            $out .= '<textarea style="width:100%;height:400px;font-family:monospace;font-size:13px;padding:12px;" readonly>';
            $out .= $this->sanitizer->entities($result['cssOutput']);
            $out .= '</textarea>';
        }

        // "Import another" link
        $out .= '<p style="margin-top:24px;"><a href="./">← Import another design</a></p>';
        $out .= '</div>';

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function postedValues(): array {
        return [
            'page_name'        => $this->sanitizer->text($this->input->post('page_name')),
            'parent_id'        => (int)$this->input->post('parent_id'),
            'template_name'    => $this->sanitizer->name($this->input->post('template_name')),
            'styling_mode'     => $this->sanitizer->name($this->input->post('styling_mode')),
            'auto_row_mode'    => (bool)$this->input->post('auto_row_mode'),
            'skip_text_styles' => (bool)$this->input->post('skip_text_styles'),
        ];
    }

    /**
     * Render one UIkit alert per message.
     *
     * @param string       $type     'success' | 'warning' | 'danger'
     * @param string|array $messages Single message string or array of messages.
     */
    private function alertHtml(string $type, $messages, bool $closeable = true): string {
        if(empty($messages)) return '';
        $messages = (array)$messages;
        $icons = [
            'success' => '<i class="fa fa-check-circle"></i> ',
            'warning' => '<i class="fa fa-exclamation-triangle"></i> ',
            'danger'  => '<i class="fa fa-times-circle"></i> ',
            'primary' => '<i class="fa fa-info-circle"></i> ',
        ];
        $icon  = $icons[$type] ?? '';
        $out = '';
        foreach($messages as $msg) {
            $out .= '<div class="uk-alert-' . $type . '" uk-alert>';
            if($closeable) $out .= '<a href class="uk-alert-close" uk-close></a>';
            $out .= '<p>' . $icon . $msg . '</p>';
            $out .= '</div>';
        }
        return $out;
    }
}
