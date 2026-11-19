<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Include;

/**
 * A host include resolver (C2-N-003, C2-N-012). Pass an instance as the `includes` option, or as its `resolver` field.
 */
interface IncludeResolver
{
    /**
     * @param array{path: string, options: array<mixed>, section?: mixed, from?: string, budget: int} $request `path` as
     *   written, the include `options` and `section`, `from` the id of the including file (absent for the top-level
     *   document), `budget` the bytes left of the size cap (C2-N-065).
     *
     * @return array{text: string, id: string}|array{error: string, id?: string} The text and a stable file id, or an
     *   error: `outside_root`, `not_found`, `unreadable`, `non_text` or `size_exceeded` (with `id`).
     */
    public function resolve(array $request): array;
}
