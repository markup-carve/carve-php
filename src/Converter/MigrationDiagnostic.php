<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final readonly class MigrationDiagnostic
{
    public function __construct(
        public string $code,
        public string $message,
        public string $severity,
        public string $fidelity,
        public string $confidence = 'exact',
        public ?string $path = null,
    ) {
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
