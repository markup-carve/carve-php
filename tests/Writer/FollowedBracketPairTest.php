<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-125 case five, C2-I-072, C2-I-079, C2-I-064: a text bracket pair inside a bracket stays bare where the `[` or
 * `{` glued behind it forms nothing: a blank label, or a brace with no `}` in the leaf. Each input is the form
 * carve-js writes, so each is a fixed point that reads back as its own tree.
 */
final class FollowedBracketPairTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function fixedPoints(): array
    {
        return [
            'pair before a lone bracket and a blank label in a note' => ["^a^[[b]\\[ ]]\n"],
            'pair before a brace that opens no block in a note' => ["x ^[c [d]{\\[] f]] g\n"],
            'pair before a lone brace at the end of a note' => ["^[[d]{]]\n"],
            'pair before a brace and text in a note' => ["^[[b]{x ]\n"],
            'pair before a brace in link text' => ["[[b]{](/u)\n"],
            'pair before a blank label in link text' => ["[[d]\\[ ](/u) ]]\n"],
            'pair before a blank label inside a note' => ["x ^[[b]\\[ ] g]\n"],
            'pair before a collapsed label keeps its backslash' => ["x ^[\\[b\\]\\[] g\n"],
            'pair before a label that reads on keeps its backslash' => ["^a^[\\[d\\]\\[] ]]\n"],
            'pair before a brace the span block closes keeps its backslash' => ["[\\[ {.c}\\[d\\]{]{.c}]\n"],
        ];
    }

    #[DataProvider('fixedPoints')]
    public function testWritesBackUnchanged(string $input): void
    {
        $result = Carve::formatWithDiagnostics($input);
        $this->assertSame($input, $result->source);
        $this->assertSame([], $result->diagnostics);
        $this->assertSame($input, Carve::write(Carve::parse($input)));
    }
}
