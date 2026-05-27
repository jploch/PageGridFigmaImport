<?php namespace ProcessWire;

/**
 * Converts Figma absolute coordinates to CSS Grid column positions.
 */
class FigmaGridCalculator {

    private $offset;
    private $gutterSize;
    private $count;
    private $sectionSize;
    private $columnUnit;
    private $frameWidth;
    private $snapTolerance;

    public function __construct(array $grid, float $frameWidth) {
        $this->frameWidth  = $frameWidth;
        $this->offset      = (float)($grid['offset']      ?? 0);
        $this->gutterSize  = (float)($grid['gutterSize']  ?? 0);
        $this->count       = (int)  ($grid['count']       ?? 12);

        // sectionSize may be absent — calculate from frame geometry
        if(!empty($grid['sectionSize']) && $grid['sectionSize'] > 0) {
            $this->sectionSize = (float)$grid['sectionSize'];
        } else {
            $totalGutters      = ($this->count - 1) * $this->gutterSize;
            $this->sectionSize = ($frameWidth - 2 * $this->offset - $totalGutters) / $this->count;
        }

        $this->columnUnit = $this->sectionSize + $this->gutterSize;

        // Snap tolerance: at least 5px, at least half the gutter, never more than 20px
        $this->snapTolerance = max(5.0, min(20.0, $this->gutterSize / 2.0));
    }

    public function getOffset(): float     { return $this->offset; }
    public function getGutterSize(): float  { return $this->gutterSize; }
    public function getCount(): int         { return $this->count; }
    public function getSectionSize(): float { return $this->sectionSize; }

    /** 1-based column start from an absolute x coordinate. */
    public function colStart(float $x): int {
        // Add a snap tolerance (dynamic, based on gutter size) to absorb Figma
        // sub-pixel rounding so that coordinates like 575.51 (intended col 7 at
        // x=578.58) resolve correctly.
        return max(1, (int)floor(($x - $this->offset) / $this->columnUnit + $this->snapTolerance / $this->columnUnit) + 1);
    }

    /** Number of columns spanned by a given pixel width. */
    public function colSpan(float $width): int {
        // A span-N element has visual width: N*sectionSize + (N-1)*gutterSize
        //   = N*(sectionSize + gutterSize) - gutterSize
        //   = N*columnUnit - gutterSize
        // Solving for N: N = (width + gutterSize) / columnUnit
        return max(1, (int)round(($width + $this->gutterSize) / $this->columnUnit));
    }

    /** True when the element width fills the whole frame content area (±5 px tolerance). */
    public function isFullWidth(float $width): bool {
        $contentWidth = $this->frameWidth - 2 * $this->offset;
        return abs($width - $contentWidth) < 5;
    }

    /**
     * Returns the grid-column-start and grid-column-end CSS values for a node.
     * Full-width elements always span all columns.
     */
    public function getGridStyles(float $x, float $width): array {
        if($this->isFullWidth($width)) {
            return [
                'grid-column-start' => '1',
                'grid-column-end'   => '-1',
            ];
        }
        $span  = $this->colSpan($width);
        $start = $this->colStart($x);

        // Right-boundary anchor: if the item's right edge is flush with the
        // content's right boundary (within 5 px), derive colStart from the
        // right side rather than the left. This fixes wide items (e.g. SVGs)
        // whose left edge lies just before the intended column boundary but
        // whose right edge lands exactly on the layout's right edge.
        $relRight    = ($x - $this->offset) + $width;
        $contentRight = $this->frameWidth - 2 * $this->offset;
        if(abs($relRight - $contentRight) < 5.0) {
            $start = max(1, $this->count - $span + 1);
        }

        $span = min($span, $this->count - $start + 1);
        // Use -1 when the item starts at col 1 and spans all columns so
        // the layout stays correct if the user later changes the column count.
        $end = ($start === 1 && $span === $this->count) ? '-1' : 'span ' . $span;
        return [
            'grid-column-start' => (string)$start,
            'grid-column-end'   => $end,
        ];
    }
}
