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
    }

    public function getOffset(): float    { return $this->offset; }
    public function getGutterSize(): float { return $this->gutterSize; }
    public function getCount(): int        { return $this->count; }

    /** 1-based column start from an absolute x coordinate. */
    public function colStart(float $x): int {
        // Add a small epsilon (0.01) to absorb Figma sub-pixel rounding so that
        // coordinates like 649.9998 snap to the same column as 650.0.
        return max(1, (int)floor(($x - $this->offset) / $this->columnUnit + 0.01) + 1);
    }

    /** Number of columns spanned by a given pixel width. */
    public function colSpan(float $width): int {
        return max(1, (int)round($width / $this->columnUnit));
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
        $start = $this->colStart($x);
        $span  = min($this->colSpan($width), $this->count - $start + 1);
        // Use -1 when the item starts at col 1 and spans all columns so
        // the layout stays correct if the user later changes the column count.
        $end = ($start === 1 && $span === $this->count) ? '-1' : 'span ' . $span;
        return [
            'grid-column-start' => (string)$start,
            'grid-column-end'   => $end,
        ];
    }
}
