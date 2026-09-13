<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final readonly class MigrationResult
{
    /**
     * @param string $value
     * @param string $sourceFormat
@param list<\MarkupCarve\Carve\Converter\MigrationDiagnostic> $diagnostics
     */
    public function __construct(
        public string $value,
        public string $sourceFormat,
        public array $diagnostics,
    ) {
    }

    /**
     * @return array{schemaVersion: 2, sourceFormat: string, diagnostics: list<array<string, mixed>>}
     */
    public function report(): array
    {
        return [
            'schemaVersion' => 2,
            'sourceFormat' => $this->sourceFormat,
            'diagnostics' => array_map(
                static fn (MigrationDiagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->diagnostics,
            ),
        ];
    }
}
