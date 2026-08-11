<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final readonly class HtmlImportResult
{
    /**
     * @param string $value
     * @param string $mode
     * @param string $adapter
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     */
    public function __construct(
        public string $value,
        public string $mode,
        public string $adapter,
        public array $diagnostics,
    ) {
    }

    /**
     * @return array{mode: string, adapter: string, diagnostics: list<array{code: string, message: string, severity: string, path?: string}>}
     */
    public function report(): array
    {
        return [
            'mode' => $this->mode,
            'adapter' => $this->adapter,
            'diagnostics' => array_map(
                static fn (HtmlImportDiagnostic $diagnostic): array => $diagnostic->toArray(),
                $this->diagnostics,
            ),
        ];
    }
}
