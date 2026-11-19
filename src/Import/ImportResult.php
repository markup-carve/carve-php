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
     * @param \MarkupCarve\Carve\Node $tree The document, read back by the tree reader (C2-A-188).
     * @param array<string, mixed> $document The document in JSON form, without positions.
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics The importer's entries, then the writer's.
     * @param string $source The canonical source (C2-M-074).
     */
    public function __construct(
        public readonly Node $tree,
        public readonly array $document,
        public readonly array $diagnostics,
        public readonly string $source,
    ) {
    }

    /**
     * @return array<string>
     */
    public function codes(): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->code, $this->diagnostics);
    }
}
