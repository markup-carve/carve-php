<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

/**
 * Where a node came from in the source.
 *
 * PART 12 §4 requires this on every node except the document root, with all six
 * fields present: lines and columns 1-based, offsets 0-based, `endColumn` and
 * `endOffset` exclusive. §4 also forbids emitting a partial span with invented
 * values, which is why this object cannot be constructed without all six - a
 * "we only know the line" position is not a position.
 *
 * UNITS. Offsets and columns are **bytes**, per §4. PHP strings are byte
 * indexed, so this is what the parser produces for free and what a consumer
 * slicing with `substr()` needs. The unit is currently under discussion
 * upstream (markup-carve/carve#394) because carve-js emits UTF-16 code units
 * for positions while reporting `srcByteLength` in bytes; this follows the spec
 * text rather than the reference's behavior.
 *
 * SOURCE. Offsets are into the source AFTER the parser's own normalization -
 * BOM stripped, CRLF folded to LF, NUL replaced. That matches carve-js, which
 * reports offset 3 for the `*` in both "a\n\n*b*" and "a\r\n\r\n*b*", and it is
 * the only definition that stays meaningful: the raw bytes are not what any
 * later pass, renderer or consumer sees.
 */
final class SourceSpan
{
    public function __construct(
        public readonly int $startLine,
        public readonly int $endLine,
        public readonly int $startColumn,
        public readonly int $endColumn,
        public readonly int $startOffset,
        public readonly int $endOffset,
    ) {
    }

    /**
     * @return array{startLine: int, endLine: int, startColumn: int, endColumn: int, startOffset: int, endOffset: int}
     */
    public function toArray(): array
    {
        return [
            'startLine' => $this->startLine,
            'endLine' => $this->endLine,
            'startColumn' => $this->startColumn,
            'endColumn' => $this->endColumn,
            'startOffset' => $this->startOffset,
            'endOffset' => $this->endOffset,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['startLine', 'endLine', 'startColumn', 'endColumn', 'startOffset', 'endOffset'] as $key) {
            if (!is_int($data[$key] ?? null)) {
                // A partial span is not a span (§4). Rather than fill the gaps
                // with zeros - the exact "invented values" the spec rules out -
                // the node simply has no position.
                return null;
            }
        }

        /** @var array{startLine: int, endLine: int, startColumn: int, endColumn: int, startOffset: int, endOffset: int} $data */
        return new self(
            $data['startLine'],
            $data['endLine'],
            $data['startColumn'],
            $data['endColumn'],
            $data['startOffset'],
            $data['endOffset'],
        );
    }
}
