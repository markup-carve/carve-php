<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Node;

/**
 * C2-M-001: the tree of an import, its canonical Carve source and the diagnostics record.
 */
final class ImportResult
{
    /**
     * The nesting the importer encodes the document with.
     *
     * @var int
     */
    private const JSON_DEPTH = 100000;

    /**
     * The document, read back by the tree reader (C2-A-188).
     */
    public readonly Node $tree;

    /**
     * The document in JSON form, without positions. An importer result decodes it on its first read, so the import
     * never holds it next to `tree` unasked.
     *
     * @var array<string, mixed>
     */
    public readonly array $document;

    /**
     * The JSON text `document` is decoded from, until it is.
     */
    private ?string $documentJson = null;

    /**
     * @param \MarkupCarve\Carve\Node $tree The document, read back by the tree reader (C2-A-188).
     * @param array<string, mixed> $document The document in JSON form, without positions.
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics The importer's entries, then the writer's.
     * @param string $source The canonical source (C2-M-074).
     * @param int $suppressedDiagnostics The losses `maxLosses` held back (C2-H-205).
     * @param string|null $documentJson Internal: the JSON text of the document, decoded in place of `$document` when
     *   `document` is first read (carve-php#5627).
     */
    public function __construct(
        Node $tree,
        array $document,
        public readonly array $diagnostics,
        public readonly string $source,
        public readonly int $suppressedDiagnostics = 0,
        ?string $documentJson = null,
    ) {
        $this->tree = $tree;
        if ($documentJson === null) {
            $this->document = $document;

            return;
        }
        $this->documentJson = $documentJson;
        // Unset while uninitialized, the property sends its first read to `__get()`.
        unset($this->document);
    }

    /**
     * @return array<string>
     */
    public function codes(): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->code, $this->diagnostics);
    }

    /**
     * @param string $name
     *
     * @return array<string, mixed>|null
     */
    public function __get(string $name): ?array
    {
        if ($name !== 'document' || $this->documentJson === null) {
            // As PHP reports a read of an undeclared property.
            trigger_error('Undefined property: ' . self::class . '::$' . $name, E_USER_WARNING);

            return null;
        }
        $document = json_decode($this->documentJson, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        $this->document = is_array($document) ? $document : [];
        $this->documentJson = null;

        return $this->document;
    }

    public function __isset(string $name): bool
    {
        return $name === 'document' && $this->documentJson !== null;
    }

    /**
     * The fields as before the lazy `document`, decoded: `unserialize()` restores no unset property.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'tree' => $this->tree,
            'document' => $this->document,
            'diagnostics' => $this->diagnostics,
            'source' => $this->source,
            'suppressedDiagnostics' => $this->suppressedDiagnostics,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $tree = $data['tree'] ?? null;
        $this->tree = $tree instanceof Node ? $tree : new Node('document', []);
        $document = $data['document'] ?? [];
        $this->document = is_array($document) ? $document : [];
        $diagnostics = $data['diagnostics'] ?? [];
        $this->diagnostics = is_array($diagnostics)
            ? array_values(array_filter($diagnostics, static fn (mixed $d): bool => $d instanceof Diagnostic))
            : [];
        $source = $data['source'] ?? '';
        $this->source = is_string($source) ? $source : '';
        $suppressed = $data['suppressedDiagnostics'] ?? 0;
        $this->suppressedDiagnostics = is_int($suppressed) ? $suppressed : 0;
    }
}
