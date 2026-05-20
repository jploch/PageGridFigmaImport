# PageGridFigmaImport

A ProcessWire module that converts Figma design exports into responsive [PageGrid](https://page-grid.com/) layouts powered by a clean CSS Grid structure.

## What it does

Export your Figma design as a ZIP file using the [Figma to PageGrid Exporter Plugin](https://www.figma.com/community/plugin/1630663953586135342), then upload it through the **Setup → Figma to PageGrid** admin page. The module:

- Parses the Figma layout grid and maps elements to CSS grid column/row positions
- Converts Figma groups into nested PageGrid blocks (`pg_group`, `pg_image`, `pg_text`, `pg_editor`, …)
- Extracts typography, colours, borders and spacing as PageGrid metadata or copyable CSS
- Imports Figma Text Styles (reusable Figma typography definitions) as shared global CSS classes
- Creates a new ProcessWire page with all blocks pre-populated, ready to edit in the visual editor

## Documentation

Full documentation and usage guide: **<https://page-grid.com/docs/figmaimport/>**
