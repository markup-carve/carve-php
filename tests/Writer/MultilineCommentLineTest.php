<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6668, C2-W-117, C2-B-033: a later line of a braced or critic comment is tested as it is written, its last
 * line with the closing marks, and only against the interrupters of its owner.
 */
final class MultilineCommentLineTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'a list marker line' => ["p {% a\n- b %}\n", "p {% a\n- b %}\n"],
            'a bare list marker' => ["{%\n-%}\n", "{% \n- %}\n"],
            'an ordered marker' => ["p {% a\n1. b %}\n", "p {% a\n1. b %}\n"],
            'a thematic break before the marks' => ["p {% a\n*** %}\n", "p {% a\n*** %}\n"],
            'a table row before the marks' => ["p {% a\n| b | c | %}\n", "p {% a\n| b | c | %}\n"],
            'a critic comment with a row' => ["|{#}\n| b | c |{#}\n", "|{#}\n| b | c |{#}\n"],
            'a critic comment with a marker' => ["p {# a\n- b #}\n", "p {# a\n- b #}\n"],
            'in a quote' => ["> p {% a\n> - b %}\n", "> p {% a\n> - b %}\n"],
            // C2-W-005, C2-A-074: the padded line would open a div or a nested list; the glued form reads back.
            'a div fence glued' => ["{%\n:::%}\n", "{%\n:::%}\n"],
            'a nested marker glued' => ["- {%\n  -%}\n", "- {%\n  -%}\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testTheCommentIsWrittenWithoutALoss(string $source, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($source);
        $this->assertSame($expected, $result->source);
        $this->assertSame([], array_map(static fn ($d): string => $d->code, $result->diagnostics));
        $this->assertSame($expected, Carve::write(Carve::parse($source)));
    }

    public function testACriticCommentWithADivFenceIsReported(): void
    {
        $comment = ['type' => 'critic_comment', 'value' => "a\n::: b"];
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'paragraph', 'children' => [$comment]]]];
        $result = Carve::writeWithDiagnostics($tree);
        $this->assertSame(
            ['field-unspellable /children/0/children/0/value'],
            array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics),
        );
    }
}
