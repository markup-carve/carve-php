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
