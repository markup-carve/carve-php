<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088: a later paragraph line opens no include or list item where the bare parse keeps it text (carve-php#5667),
 * and a mark behind a bare `{` is a braced opener that pairs with no bare mark (carve-php#5715).
 */
final class LineStartAndBracedMarkTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function provideUnchanged(): array
    {
        return [
            'attribute shape before a blank' => ["{#m}\n\nx"],
            'include on a later line' => ["a\n{{ x.crv }}"],
            'include in an item' => ["- a\n  {{ x.crv }}"],
            'list marker in a div' => ["::: div\n---tom\n- li\n:::"],
            'list marker in a quote fence' => ["::: >\na\n* li\n:::"],
            'list marker in a div in an item' => ["- x\n\n  ::: div\n  a\n  - li\n  :::"],
            'caption mark below text' => ["a\n^ cap"],
            'caption mark below text in a div' => ["::: div\na\n^ cap\n:::"],
            'brace shape on a first line' => ["{{x}}\n\nx"],
        ];
    }

    #[DataProvider('provideUnchanged')]
    public function testLineStartTakesNoBackslash(string $source): void
    {
        $result = Carve::formatWithDiagnostics($source);
        self::assertSame($source . "\n", $result->source);
    }

    /**
     * @return array<string, array{array<array<string, mixed>>, string}>
     */
    public static function provideBracedMarks(): array
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];

        return [
            'star behind a bare brace' => [[$text('/[!*]//{*')], '/[!*]//{*'],
            'slash behind a bare brace' => [[$text('!*^/*[{/]^]')], '!\*^/\*[{/]^]'],
            'braced star is no partner' => [[$text('){*[*[(b!]a*')], '){\*[\*[(b!]a*'],
            'lone mark behind a braced mark' => [[$text('[=]{{**[/}')], '[=]{{**[/}'],
            'lone slash behind a braced slash' => [[$text('(^{//]}**[')], '(^{//]}**['],
            'emphasis opening on a brace' => [
                [$text('{/b='), ['type' => 'emphasis', 'children' => [$text('}a')]]],
                '\{\/b=/}a/',
            ],
            'braced emphasis keeps its own closer' => [
                [$text('}=}b^{/'), ['type' => 'emphasis', 'children' => [$text('}')]], $text('b^[)b')],
                '}=}b^{/{/}/}b^[)b',
            ],
        ];
    }

    /**
     * @param array<array<string, mixed>> $inlines
     * @param string $expected
     */
    #[DataProvider('provideBracedMarks')]
    public function testMarkBehindBareBrace(array $inlines, string $expected): void
    {
        $tree = ['astVersion' => '2.0', 'type' => 'document', 'children' => [['type' => 'paragraph', 'children' => $inlines]]];
        $result = Writer::writeWithDiagnostics($tree);
        self::assertSame($expected . "\n", $result->source);
        self::assertSame([], $result->diagnostics);
        $read = json_decode(Carve::toJson($expected), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected . "\n", Carve::write($read));
    }
}
