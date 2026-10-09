<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

/**
 * A writer that exposes the PART 11 §1d conversion-diagnostics channel.
 *
 * The channel answers what the output model cannot say, so every writer for a
 * target with no spelling for a shape implements it: Carve, Markdown, plain and
 * ANSI. It is separate from the `CARVE-P2-024` render-loss report, whose code
 * enum holds `raw-format-dropped`, `ruby-flattened`, `editorial-comment-flattened`
 * and `destination-denied`.
 */
interface ConversionDiagnosticCollector
{
    public function beginConversionDiagnosticCollection(int $maximum = 100): void;

    /**
     * @return array{diagnostics: list<array<string, mixed>>, totalDiagnostics: int, truncated: bool}
     */
    public function finishConversionDiagnosticCollection(): array;
}
