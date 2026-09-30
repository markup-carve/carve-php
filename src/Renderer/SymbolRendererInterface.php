<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

interface SymbolRendererInterface
{
    /**
     * @return array<string, string>
     */
    public function getSymbols(): array;
}
