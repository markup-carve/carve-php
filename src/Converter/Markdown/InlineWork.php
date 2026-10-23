<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

/**
 * Protected spans and diagnostics for one Markdown inline conversion.
 *
 * @internal
 */
final class InlineWork
{
    /**
     * @var array<string>
     */
    public array $protected = [];

    /**
     * @var array<string>
     */
    public array $protectedSources = [];

    /**
     * @var array<string, \MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    public array $codeFallbacks = [];

    public function __construct(public readonly string $sourceInput)
    {
    }
}
