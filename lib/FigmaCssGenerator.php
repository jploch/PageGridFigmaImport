<?php namespace ProcessWire;

/**
 * Collects per-block CSS rules for styling mode B and renders them as a
 * copyable CSS string.  Block names come from the created page names
 * (e.g. "pg-group-1042") so they must be added after page creation.
 */
class FigmaCssGenerator {

    private $blocks = [];

    /**
     * @param string $blockName        The ProcessWire page name of the block (e.g. "pg-group-1042").
     * @param array  $styles           CSS property => value pairs for the block wrapper.
     * @param array  $innerStyles      Keyed by tag name (e.g. 'h2', 'p') → CSS property => value pairs.
     * @param array  $mobileStyles     Wrapper CSS props for @media (max-width: 640px).
     * @param array  $mobileInnerStyles Keyed by tag name for @media (max-width: 640px).
     */
    public function addBlock(string $blockName, array $styles, array $innerStyles = [], array $mobileStyles = [], array $mobileInnerStyles = []): void {
        $this->blocks[] = compact('blockName', 'styles', 'innerStyles', 'mobileStyles', 'mobileInnerStyles');
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

            $mobileCss = [];
            if(!empty($block['mobileStyles'])) {
                $mobileCss[] = $this->renderRule('.' . $name, $block['mobileStyles']);
            }
            foreach($block['mobileInnerStyles'] as $tag => $props) {
                if(!empty($props)) {
                    $mobileCss[] = $this->renderRule('.' . $name . ' ' . $tag, $props);
                }
            }
            if(!empty($mobileCss)) {
                $parts[] = "@media (max-width: 640px) {\n" . implode("\n", $mobileCss) . "\n}";
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
