<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Ast\SourceSpan;

/**
 * Converts the parser's byte positions into the codepoint positions PART 12 §4
 * requires.
 *
 * The parser works in bytes because PHP strings are byte indexed, so every
 * offset it measures is a byte offset. §4 counts UNICODE CODEPOINTS, and says
 * why: a codepoint index always lands on a character boundary, while a byte
 * offset can point inside a UTF-8 sequence and a UTF-16 offset inside a
 * surrogate pair - either lets a consumer slice a document into invalid text.
 *
 * §4 also anticipates this class: "every implementation therefore converts, and
 * each pays it once per document rather than per position". The table below is
 * that single pass. It is the identity for any document without a multi-byte
 * character, which is most of them.
 */
final class PositionIndex
{
    /**
     * Codepoint count preceding each byte offset, for offsets 0..strlen.
     *
     * @var array<int, int>
     */
    private array $codepoints = [];

    private bool $ascii;

    public function __construct(private string $source)
    {
        // A pure-ASCII document needs no table at all: byte offset IS codepoint
        // offset, and building one would cost a pass for nothing.
        $this->ascii = !preg_match('/[\x80-\xFF]/', $source);
        if ($this->ascii) {
            return;
        }

        $length = strlen($source);
        $count = 0;
        for ($i = 0; $i <= $length; $i++) {
            $this->codepoints[$i] = $count;
            // Continuation bytes (10xxxxxx) do not begin a codepoint.
            if ($i < $length && (ord($source[$i]) & 0xC0) !== 0x80) {
                $count++;
            }
        }
    }

    /**
     * The number of codepoints before a byte offset.
     */
    public function codepointAt(int $byteOffset): int
    {
        if ($this->ascii) {
            return $byteOffset;
        }

        if (isset($this->codepoints[$byteOffset])) {
            return $this->codepoints[$byteOffset];
        }

        // Past the end: clamp to the document's total, so a span can never
        // report an offset the source does not reach.
        $lastKey = array_key_last($this->codepoints);

        return $lastKey === null ? 0 : $this->codepoints[$lastKey];
    }

    /**
     * Build a span from byte positions, converting every field §4 counts.
     *
     * Lines are unaffected - a line number is a line number in any unit - but
     * columns are codepoints from the line's first character, so they convert
     * alongside the offsets.
     */
    public function span(
        int $startByte,
        int $endByte,
        int $startLine,
        int $endLine,
        int $startLineByte,
        int $endLineByte,
    ): SourceSpan {
        return new SourceSpan(
            startLine: $startLine,
            endLine: $endLine,
            startColumn: $this->codepointAt($startByte) - $this->codepointAt($startLineByte) + 1,
            endColumn: $this->codepointAt($endByte) - $this->codepointAt($endLineByte) + 1,
            startOffset: $this->codepointAt($startByte),
            endOffset: $this->codepointAt($endByte),
        );
    }

    public function source(): string
    {
        return $this->source;
    }
}
