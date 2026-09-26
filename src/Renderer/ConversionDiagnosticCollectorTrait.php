<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use InvalidArgumentException;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Node;

trait ConversionDiagnosticCollectorTrait
{
    private ?int $conversionDiagnosticMaximum = null;

    private int $conversionDiagnosticTotal = 0;

    /**
     * @var list<array<string, mixed>>
     */
    private array $conversionDiagnostics = [];

    /**
     * @var array<string, true>
     */
    private array $seenConversionDiagnostics = [];

    public function beginConversionDiagnosticCollection(int $maximum = 100): void
    {
        if ($maximum < 0) {
            throw new InvalidArgumentException('Maximum conversion diagnostics must be non-negative.');
        }
        $this->conversionDiagnosticMaximum = $maximum;
        $this->conversionDiagnosticTotal = 0;
        $this->conversionDiagnostics = [];
        $this->seenConversionDiagnostics = [];
    }

    /**
     * @return array{diagnostics: list<array<string, mixed>>, totalDiagnostics: int, truncated: bool}
     */
    public function finishConversionDiagnosticCollection(): array
    {
        $report = [
            'diagnostics' => $this->conversionDiagnostics,
            'totalDiagnostics' => $this->conversionDiagnosticTotal,
            'truncated' => $this->conversionDiagnosticTotal > count($this->conversionDiagnostics),
        ];
        $this->conversionDiagnosticMaximum = null;
        $this->conversionDiagnosticTotal = 0;
        $this->conversionDiagnostics = [];
        $this->seenConversionDiagnostics = [];

        return $report;
    }

    protected function recordUnspellableStructure(Node $node, string $message): void
    {
        $this->recordConversionDiagnostic($node, 'structure-unspellable', null, $message);
    }

    protected function recordUnspellableField(Node $node, string $field, string $message): void
    {
        $this->recordConversionDiagnostic($node, 'field-unspellable', $field, $message);
    }

    /**
     * One entry per discarded nonempty section-attributes field (CARVE-P12-034).
     * An empty attributes object holds nothing to lose and is reported nowhere.
     */
    protected function recordUnspellableTableSectionAttributes(Table $node): void
    {
        $groups = $node->getRowGroups();
        if ($groups === null) {
            return;
        }
        $fields = [
            'rowGroups.headAttrs' => $groups['headAttrs'] ?? [],
            'rowGroups.footAttrs' => $groups['footAttrs'] ?? [],
        ];
        foreach ($groups['bodies'] as $index => $body) {
            $fields['rowGroups.bodies[' . $index . '].attrs'] = $body['attrs'] ?? [];
        }
        foreach ($fields as $field => $attrs) {
            if (array_filter($attrs, static fn (mixed $value): bool => is_string($value) || $value !== []) === []) {
                continue;
            }
            $this->recordUnspellableField($node, $field, 'Carve source cannot spell table section attributes');
        }
    }

    private function recordConversionDiagnostic(Node $node, string $code, ?string $field, string $message): void
    {
        if ($this->conversionDiagnosticMaximum === null) {
            return;
        }
        $origin = $node->getRenderHint("\0carve-conversion-origin") ?? (string)spl_object_id($node);
        $key = $origin . ':' . $code . ':' . ($field ?? '');
        if (isset($this->seenConversionDiagnostics[$key])) {
            return;
        }
        $this->seenConversionDiagnostics[$key] = true;
        $this->conversionDiagnosticTotal++;
        if (count($this->conversionDiagnostics) >= $this->conversionDiagnosticMaximum) {
            return;
        }
        $entry = ['code' => $code, 'node' => $node->getType(), 'message' => $message];
        if ($field !== null) {
            $entry['field'] = $field;
        }
        if ($node->getPos() !== null) {
            $entry['pos'] = $node->getPos()->toWireArray();
        }
        $this->conversionDiagnostics[] = $entry;
    }
}
