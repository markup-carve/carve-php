<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

interface RenderLossAwareRendererInterface
{
    public function beginRenderLossCollection(string $target, int $maximum): void;

    /**
     * @return array{losses: list<array<string, mixed>>, totalLosses: int, truncated: bool}
     */
    public function finishRenderLossCollection(): array;
}
