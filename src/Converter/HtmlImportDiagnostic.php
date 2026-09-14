<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final readonly class HtmlImportDiagnostic
{
    public string $fidelity;
    public string $confidence;

    public function __construct(
        public string $code,
        public string $message,
        public string $severity,
        public ?string $path = null,
    ) {
        $this->fidelity = match ($code) {
            'attribute-preserved' => 'preserved',
            'element-dropped', 'attribute-dropped', 'structure-unspellable', 'diagnostics-truncated' => 'dropped',
            'element-unwrapped', 'style-unmapped', 'table-degraded', 'encoding-assumed', 'raw-preserved' => 'degraded',
            default => 'dropped',
        };
        $this->confidence = match ($code) {
            'encoding-assumed' => 'inferred',
            'diagnostics-truncated' => 'fallback',
            'element-dropped', 'attribute-dropped', 'structure-unspellable', 'element-unwrapped',
            'style-unmapped', 'table-degraded', 'attribute-preserved', 'raw-preserved' => 'exact',
            default => 'fallback',
        };
    }

    /**
     * @return array{code: string, message: string, severity: string, fidelity: string, confidence: string, path?: string}
     */
    public function toArray(): array
    {
        $result = [
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity,
            'fidelity' => $this->fidelity,
            'confidence' => $this->confidence,
        ];
        if ($this->path !== null) {
            $result['path'] = $this->path;
        }

        return $result;
    }
}
