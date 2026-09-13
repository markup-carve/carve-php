<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final readonly class MigrationResult
{
    /**
     * @param string $value
     * @param string $sourceFormat
     * @param list<\MarkupCarve\Carve\Converter\MigrationDiagnostic> $diagnostics
     * @param string|null $mode
     * @param string|null $adapter
     */
    public function __construct(
        public string $value,
        public string $sourceFormat,
        public array $diagnostics,
        public ?string $mode = null,
        public ?string $adapter = null,
    ) {
    }

    /**
     * @return array{schemaVersion: 2, sourceFormat: string, diagnostics: list<array<string, mixed>>, mode?: string, adapter?: string}
     */
    public function report(): array
    {
        $report = [
            'schemaVersion' => 2,
            'sourceFormat' => $this->sourceFormat,
            'diagnostics' => array_map(
                static fn (MigrationDiagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->diagnostics,
            ),
        ];
        if ($this->mode !== null) {
            $report['mode'] = $this->mode;
        }
        if ($this->adapter !== null) {
            $report['adapter'] = $this->adapter;
        }

        return $report;
    }
}
