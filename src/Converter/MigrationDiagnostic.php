<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use InvalidArgumentException;

final readonly class MigrationDiagnostic
{
    public function __construct(
        public string $code,
        public string $message,
        public string $severity,
        public string $fidelity,
        public string $confidence,
        public ?string $path = null,
    ) {
        if (!in_array($severity, ['info', 'warning', 'error'], true)) {
            throw new InvalidArgumentException('Unknown migration diagnostic severity: ' . $severity);
        }
        if (!in_array($fidelity, ['preserved', 'normalized', 'degraded', 'dropped'], true)) {
            throw new InvalidArgumentException('Unknown migration diagnostic fidelity: ' . $fidelity);
        }
        if (!in_array($confidence, ['exact', 'inferred', 'fallback'], true)) {
            throw new InvalidArgumentException('Unknown migration diagnostic confidence: ' . $confidence);
        }
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
