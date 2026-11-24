<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6453, C2-I-089: an `^[` that never closes is two characters of text and what follows reads as usual; a note
 * that would close only because a caret pair inside it opens is text, and that caret pair is an ordinary bracket.
 */
final class UnclosedNoteTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function trees(): array
    {
        return [
            'reference behind an unclosed note (carve-php#5717)' => [
                "^[[^f] z\n\n[^f]: f\n",
                'p(t"^[" ref(f) t" z") footnote(f)',
            ],
            'reference glued behind the note text (carve-php#5700)' => [
                "a ^[x[^n]\n\n[^n]: f\n",
                'p(t"a ^[x" ref(n)) footnote(n)',
            ],
            'note behind an unclosed note' => ["x ^[a ^[b] c\n", 'p(t"x ^[a " note(t"b") t" c")'],
            'second unclosed note' => ["^[a ^[b [^1]\n\n[^1]: n\n", 'p(t"^[a ^[b " ref(1)) footnote(1)'],
            'unclosed note inside strong' => ["*a ^[b [^1]*\n\n[^1]: n\n", 'p(strong(t"a ^[b " ref(1))) footnote(1)'],
            'tie-break on an empty label' => ["^[[^] w\n", 'p(t"^[[^] w")'],
            'tie-break keeps the note behind it' => ["^[[^] ^[n]\n", 'p(t"^[[^] " note(t"n"))'],
            'a later note closes behind an ordinary bracket' => ["^[^[][,^[]\n", 'p(t"^[" note() t"[," note())'],
            'closed note keeps its caret pairs ordinary' => ["^[a [^1]]\n\n[^1]: n\n", 'p(note(t"a [^1]")) footnote(1)'],
        ];
    }

    #[DataProvider('trees')]
    public function testReadsAsTheRulingSays(string $input, string $expected): void
    {
        $this->assertSame($expected, self::shape(Carve::parse($input)->children));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function writes(): array
    {
        return [
            // carve-php#5738, C2-W-125 case five: the bracket keeps the note from forming, so only it is escaped.
            'unclosed note over two lines' => ["^[*e*\n*e*\nb\\](u) z\n", "^\\[*e*\n*e*\nb](u) z\n"],
            'unclosed note with two marks' => ["a ^[*e* *e* b\\]\n", "a ^\\[*e* *e* b]\n"],
            // C2-I-076: a footnote label ends at the first `]`, the closing bracket of a note behind it too.
            'caret bracket before a note' => ["a \\[^ ^[x]\n", "a \\[^ ^[x]\n"],
            'bracket glued before a note' => ["[[\\]\\[^[^](u)\n", "\\[\\[]\\[^[^](u)\n"],
        ];
    }

    #[DataProvider('writes')]
    public function testWritesAFixedPoint(string $input, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($input);
        $this->assertSame($expected, $result->source);
        $this->assertSame([], $result->diagnostics);
        $this->assertSame($expected, Carve::format($expected));
    }

    /**
     * The first scan leaves the unclosed notes open; the two scans that follow read each content once more.
     */
    public function testManyUnclosedNotesStayLinear(): void
    {
        $input = str_repeat('x ^[a [^1] ^[[^] b ', 2000) . "\n\n[^1]: n\n";
        $start = hrtime(true);
        $json = Carve::toJson($input);
        $this->assertLessThan(5e9, hrtime(true) - $start);
        $this->assertSame(2000, substr_count($json, '"footnote_ref"'));
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     */
    private static function shape(array $nodes): string
    {
        $out = [];
        foreach ($nodes as $node) {
            $out[] = match ($node->type) {
                'text' => 't"' . $node->f['value'] . '"',
                'paragraph' => 'p(' . self::shape($node->children) . ')',
                'inline_footnote' => 'note(' . self::shape($node->children) . ')',
                'footnote_ref' => 'ref(' . $node->f['key'] . ')',
                'footnote' => 'footnote(' . $node->f['key'] . ')',
                'strong' => 'strong(' . self::shape($node->children) . ')',
                default => $node->type,
            };
        }

        return implode(' ', $out);
    }
}
