<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use InvalidArgumentException;
use MarkupCarve\Carve\Node\Node;

trait RenderLossCollectorTrait
{
    private ?string $renderLossTarget = null;

    private int $renderLossMaximum = 100;

    private int $renderLossTotal = 0;

    /**
     * @var array<string, int>
     */
    private array $renderLossCounts = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $renderLosses = [];

    /**
     * @var array<int, true>
     */
    private array $seenRubyLosses = [];

    public function beginRenderLossCollection(string $target, int $maximum): void
    {
        if ($maximum < 0) {
            throw new InvalidArgumentException('Maximum render losses must be non-negative.');
        }
        $this->renderLossTarget = $target;
        $this->renderLossMaximum = $maximum;
        $this->renderLossTotal = 0;
        $this->renderLossCounts = [];
        $this->renderLosses = [];
        $this->seenRubyLosses = [];
    }

    public function finishRenderLossCollection(): array
    {
        $result = [
            'losses' => $this->renderLosses,
            'totalLosses' => $this->renderLossTotal,
            'lossCounts' => $this->renderLossCounts,
            'truncated' => $this->renderLossTotal > count($this->renderLosses),
        ];
        $this->renderLossTarget = null;

        return $result;
    }

    protected function recordRawFormatDropped(Node $node, string $format, string $nodeType): void
    {
        if ($this->renderLossTarget === null) {
            return;
        }
        $this->renderLossTotal++;
        $this->renderLossCounts['raw-format-dropped'] = ($this->renderLossCounts['raw-format-dropped'] ?? 0) + 1;
        if (count($this->renderLosses) >= $this->renderLossMaximum) {
            return;
        }
        $loss = [
            'code' => 'raw-format-dropped',
            'format' => $format,
            'target' => $this->renderLossTarget,
            'nodeType' => $nodeType,
            'message' => sprintf('Dropped %s raw format "%s" while rendering %s', $nodeType, $format, $this->renderLossTarget),
        ];
        if ($node->getPos() !== null) {
            $loss['pos'] = $node->getPos()->toArray();
        }
        $this->renderLosses[] = $loss;
    }

    /**
     * Blank a destination whose scheme the PART 9 section 25 sink denylist
     * denies, and owe the render-loss report one row for it.
     *
     * THE ONE PLACE A BLANKED DESTINATION IS REPORTED. Seven sink expressions
     * across the HTML, Markdown and ANSI targets blank a destination, and a
     * row emitted per site is how three copies of one rule drift apart
     * (markup-carve/carve#2679). Each target routes its own wrapper here
     * instead. The emitted value is unchanged: a denied scheme still blanks,
     * in both safe modes, because the flag gates neither the hardening nor the
     * reporting.
     */
    protected function blankDeniedDestination(string $url, ?Node $node = null): string
    {
        $blanked = HtmlRenderer::blankDangerousScheme($url);
        if ($blanked === $url) {
            return $url;
        }
        $this->recordDestinationDenied($node);

        return $blanked;
    }

    protected function recordDestinationDenied(?Node $node): void
    {
        if ($this->renderLossTarget === null) {
            return;
        }
        $this->renderLossTotal++;
        $this->renderLossCounts['destination-denied'] = ($this->renderLossCounts['destination-denied'] ?? 0) + 1;
        if (count($this->renderLosses) >= $this->renderLossMaximum) {
            return;
        }
        $loss = [
            'code' => 'destination-denied',
            'target' => $this->renderLossTarget,
            'nodeType' => 'inline',
            'message' => 'Blanked a destination with a denied URL scheme while rendering ' . $this->renderLossTarget,
        ];
        if ($node !== null && $node->getPos() !== null) {
            $loss['pos'] = $node->getPos()->toArray();
        }
        $this->renderLosses[] = $loss;
    }

    protected function recordRubyFlattened(Node $node): void
    {
        if ($this->renderLossTarget === null || isset($this->seenRubyLosses[spl_object_id($node)])) {
            return;
        }
        $this->seenRubyLosses[spl_object_id($node)] = true;
        $this->renderLossTotal++;
        $this->renderLossCounts['ruby-flattened'] = ($this->renderLossCounts['ruby-flattened'] ?? 0) + 1;
        if (count($this->renderLosses) >= $this->renderLossMaximum) {
            return;
        }
        $loss = [
            'code' => 'ruby-flattened',
            'target' => $this->renderLossTarget,
            'nodeType' => 'inline',
            'message' => 'Flattened ruby annotations while rendering ' . $this->renderLossTarget,
        ];
        if ($node->getPos() !== null) {
            $loss['pos'] = $node->getPos()->toArray();
        }
        $this->renderLosses[] = $loss;
    }
}
