<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final readonly class HtmlImportDiagnostic
{
    public function __construct(
        public string $code,
        public string $message,
        public string $severity,
        public ?string $path = null,
    ) {
    }

    /**
     * How much of the source survived this decision.
     *
     * A property of the CODE, so it is answered here rather than by each
     * consumer: the migration report used to derive it on its way out, which
     * left the plain import report - the one the shared fixtures compare -
     * without a classification the format defines.
     *
     * Ordered `preserved < normalized < degraded < dropped`, and the
     * producer's answer is final: a binding must not reclassify it, and it must
     * never be inferred from the message text.
     *
     * @return string
     */
    public function fidelity(): string
    {
        return match ($this->code) {
            // Nothing was lost: the attribute reached the output inside the
            // bytes of an element kept whole.
            'attribute-preserved' => 'preserved',
            // The bytes survive, structured editing does not.
            'raw-preserved', 'element-unwrapped', 'style-unmapped',
            'table-degraded', 'encoding-assumed' => 'degraded',
            // A cap hides findings that may include irreversible loss, so it
            // reports the worst case rather than the state it could see.
            default => 'dropped',
        };
    }

    /**
     * How sure the importer is that the decision was the right one.
     *
     * @return string
     */
    public function confidence(): string
    {
        return match ($this->code) {
            // The importer assumed an encoding the source never declared.
            'encoding-assumed' => 'inferred',
            // Every code whose decision the importer can see for itself.
            'element-dropped', 'attribute-dropped', 'attribute-preserved',
            'element-unwrapped', 'style-unmapped', 'table-degraded',
            'raw-preserved', 'structure-unspellable' => 'exact',
            // FAILS CLOSED, and that is what the default is for: a code this
            // version does not know says nothing about how sure anyone can be,
            // so it claims the weakest confidence rather than the strongest.
            // `diagnostics-truncated` lands here for the same reason - the
            // report is a sample and the omitted findings are unknown.
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
            'fidelity' => $this->fidelity(),
            'confidence' => $this->confidence(),
        ];
        if ($this->path !== null) {
            $result['path'] = $this->path;
        }

        return $result;
    }
}
