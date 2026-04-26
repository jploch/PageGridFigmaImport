<?php namespace ProcessWire;

/**
 * Collects per-block CSS rules for styling mode B and renders them as a
 * copyable CSS string.  Block names come from the created page names
 * (e.g. "pg-group-1042") so they must be added after page creation.
 */
class FigmaCssGenerator {

    private $blocks = [];

    /**
     * @param string $blockName   The ProcessWire page name of the block (e.g. "pg-group-1042").
     * @param array  $styles      CSS property => value pairs for the block wrapper.
     * @param array  $innerStyles Keyed by tag name (e.g. 'h2', 'p') → CSS property => value pairs.
     */
    public function addBlock(string $blockName, array $styles, array $innerStyles = []): void {
        $this->blocks[] = compact('blockName', 'styles', 'innerStyles');
    }

    /** Renders all collected rules as a formatted CSS string. */
    public function render(): string {
        $parts = [];

        foreach($this->blocks as $block) {
            $name = $block['blockName'];

            if(!empty($block['styles'])) {
                $parts[] = $this->renderRule('.' . $name, $block['styles']);
            }

            foreach($block['innerStyles'] as $tag => $props) {
                if(!empty($props)) {
                    $parts[] = $this->renderRule('.' . $name . ' ' . $tag, $props);
                }
            }
        }

        return implode("\n", $parts);
    }

    private function renderRule(string $selector, array $props): string {
        $lines = [$selector . ' {'];
        foreach($props as $prop => $value) {
            $lines[] = '  ' . $prop . ': ' . $value . ';';
        }
        $lines[] = '}';
        return implode("\n", $lines);
    }
}
