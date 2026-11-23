<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-117, C2-A-040: no parse gives an empty text. Beside another text it joins that one; with no text beside it, it
 * reads back as none and is reported; at the end of its content its parent is, as carve-js and carve-rs `next2` do.
 */
final class LoneEmptyTextTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string, array<string>}>
     */
    public static function paragraphs(): array
    {
        $text = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $em = ['type' => 'emphasis', 'children' => [$text('a')]];
        $strong = ['type' => 'strong', 'children' => [$text('b')]];
        $at = static fn (string $path): array => ["field-unspellable $path/value"];

        return [
            'before emphasis' => [[$text(''), $em], "/a/\n", $at('/children/0/children/0')],
            'between spans' => [[$em, $text(''), $strong], "/a/*b*\n", $at('/children/0/children/1')],
            'inside emphasis before strong' => [
                [['type' => 'emphasis', 'children' => [$text(''), $strong]]],
                "/*b*/\n",
                $at('/children/0/children/0/children/0'),
            ],
            'before a link' => [
                [$text(''), ['type' => 'link', 'href' => '/u', 'children' => [$text('x')]], $text(' b')],
                "[x](/u) b\n",
                $at('/children/0/children/0'),
            ],
            'between code and emphasis' => [
                [['type' => 'code', 'value' => 'x'], $text(''), $em],
                "`x`/a/\n",
                $at('/children/0/children/1'),
            ],
            'after code' => [[['type' => 'code', 'value' => 'x'], $text('')], "`x`\n", ['structure-unspellable /children/0']],
            'at the end of the paragraph its parent is lost' => [[$em, $text('')], "/a/\n", ['structure-unspellable /children/0']],
            'at the end of emphasis' => [
                [['type' => 'emphasis', 'children' => [$strong, $text('')]], $text(' c')],
                "/*b*/ c\n",
                ['structure-unspellable /children/0/children/0'],
            ],
            'before a soft break' => [
                [$em, $text(''), ['type' => 'soft_break'], $text('b')],
                "/a/\nb\n",
                $at('/children/0/children/1'),
            ],
            'beside text joins it' => [[$text(''), $text('a')], "a\n", []],
        ];
    }

    /**
     * @param array<mixed> $inlines
     * @param string $source
     * @param array<string> $losses
     *
     * @return void
     */
    #[DataProvider('paragraphs')]
    public function testALoneEmptyTextIsLost(array $inlines, string $source, array $losses): void
    {
        $result = Writer::writeBlocksWithDiagnostics([['type' => 'paragraph', 'children' => $inlines]]);

        $this->assertSame($source, $result->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics));
    }

    /**
     * @return void
     */
    public function testACellEndingInALoneEmptyTextLosesItsParagraph(): void
    {
        $content = [['type' => 'strong', 'children' => [['type' => 'text', 'value' => 'a']]], ['type' => 'text', 'value' => '']];
        $cell = ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'cell_paragraph', 'children' => $content]]];
        $table = [
            'type' => 'table',
            'columns' => [['type' => 'table_column']],
            'children' => [['type' => 'table_body', 'children' => [['type' => 'table_row', 'children' => [$cell]]]]],
        ];
        $result = Writer::writeBlocksWithDiagnostics([$table]);

        $this->assertSame(
            ['structure-unspellable /children/0/children/0/children/0/children/0/children/0'],
            array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics),
        );
    }
}
