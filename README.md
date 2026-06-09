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
[Full documentation and usage guide](https://page-grid.com/docs/figmaimport/)

## Requirements
- Process Wire 3.0.210 or greater
- [PAGEGRID Fieldtype Module](https://github.com/jploch/FieldtypePageGrid/)

## Installation
1. Go to ```Modules > Site > Add New``` in your admin
2. Paste the Module Class Name ```PageGridFigmaImport``` into the field “Add Module From Directory“
3. Click “Get Module Info“
4. On the overview, click “Download And Install“
5. On the following screen, click “Install Now“

### Contribute

**Found a bug?**  
Please post all bug reports in our [issue tracker](https://github.com/jploch/FieldtypePageGrid/issues/).

**Suggest a feature**  
If you have ideas for a feature or enhancement for PAGEGRID, please make a post on the [PAGEGRID forum](https://processwire.com/talk/forum/64-pagegrid/).

## What's PAGEGRID?
- **[page-grid.com](https://page-grid.com)** – Get to know PAGEGRID.
- **[Documentation](https://page-grid.com/docs/)** – Read the official documentation.
- **[Issues](https://github.com/jploch/FieldtypePageGrid/issues/)** – Report bugs and other problems.
- **[Forum](https://processwire.com/talk/forum/64-pagegrid/)** – Whenever you get stuck, don't hesitate to reach out for questions and support.

© 2026 Jan Ploch
[page-grid.com](https://page-grid.com) · [License agreement for PAGEGRID Module](https://github.com/jploch/FieldtypePageGrid/blob/main/LICENSE.md)
