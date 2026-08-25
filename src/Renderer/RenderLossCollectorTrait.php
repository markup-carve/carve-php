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
     * @var list<array<string, mixed>>
     */
    private array $renderLosses = [];

    public function beginRenderLossCollection(string $target, int $maximum): void
    {
        if ($maximum < 0) {
            throw new InvalidArgumentException('Maximum render losses must be non-negative.');
        }
        $this->renderLossTarget = $target;
        $this->renderLossMaximum = $maximum;
        $this->renderLossTotal = 0;
        $this->renderLosses = [];
    }

    public function finishRenderLossCollection(): array
    {
        $result = [
            'losses' => $this->renderLosses,
            'totalLosses' => $this->renderLossTotal,
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
}
