<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6464, C2-B-024: a line comment at the end of a line of a stanza is written as a lazy `%%` line at column 0,
 * the only spelling that reads back as a comment.
 */
final class LineBlockLazyCommentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'after a comment block' => [
                "- ::: |\n  a\n  %%%\n  c\n  %%%\n%% x\n",
                "- ::: |\n  a\n%% x\n  %%%\n  c\n  %%%\n  :::\n",
            ],
            'without a comment block' => ["- ::: |\n  a\n%% x\n", "- ::: |\n  a\n%% x\n  :::\n"],
            'in a quote' => ["> ::: |\n> a\n%% x\n> b\n", "> ::: |\n> a\n%% x\n> b\n> :::\n"],
            'an empty comment' => ["1. ::: |\n   a\n%%\n", "1. ::: |\n   a\n%%\n   :::\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testTheCommentIsWrittenAtColumnZero(string $source, string $expected): void
    {
        $written = Carve::format($source);
        $this->assertSame($expected, $written);
        $this->assertSame($written, Carve::format($written));
        $this->assertSame(self::tree($source), self::tree($written));
    }

    public function testATopLevelLineBlockHasNoSpellingForIt(): void
    {
        $line = [

            'type' => 'line',
            'children' => [
                ['type' => 'text', 'value' => 'a'],
                ['type' => 'comment', 'form' => 'line', 'value' => 'x'],
            ],
        ];
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'line_block', 'children' => [$line]]]];
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame("::: |\na\n:::\n", $result->source);
        $this->assertSame(
            ['structure-unspellable /children/0/children/0/children/1'],
            array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics),
        );
    }

    public function testAnEarlierCommentOfTheLineIsReported(): void
    {
        $line = [

            'type' => 'line',
            'children' => [
                ['type' => 'text', 'value' => 'b'],
                ['type' => 'comment', 'form' => 'line', 'value' => 'x'],
                ['type' => 'comment', 'form' => 'line', 'value' => 'y'],
            ],
        ];
        $item = ['type' => 'list_item', 'children' => [['type' => 'line_block', 'children' => [$line]]]];
        $list = ['type' => 'list', 'marker' => ['kind' => 'bullet', 'char' => '-'], 'spacing' => 'tight', 'children' => [$item]];
        $result = Writer::writeWithDiagnostics(['type' => 'document', 'astVersion' => '2.0', 'children' => [$list]]);
        $this->assertContains(
            'structure-unspellable /children/0/children/0/children/0/children/0/children/1',
            array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics),
        );
    }

    public function testLossesNameTheCommentAndTheSpacesBeforeIt(): void
    {
        $line = [

            'type' => 'line',
            'children' => [
                ['type' => 'text', 'value' => 'a'],
                ['type' => 'text', 'value' => '  '],
                ['type' => 'comment', 'form' => 'line', 'value' => "x\ny"],
            ],
        ];
        $item = ['type' => 'list_item', 'children' => [['type' => 'line_block', 'children' => [$line]]]];
        $list = ['type' => 'list', 'marker' => ['kind' => 'bullet', 'char' => '-'], 'spacing' => 'tight', 'children' => [$item]];
        $result = Writer::writeWithDiagnostics(['type' => 'document', 'astVersion' => '2.0', 'children' => [$list]]);
        $this->assertSame("- ::: |\n  a\n%% x y\n  :::\n", $result->source);
        $this->assertSame(
            [
                'field-unspellable /children/0/children/0/children/0/children/0/children/2/value',
                'field-unspellable /children/0/children/0/children/0/children/0/children/1/value',
            ],
            array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics),
        );
    }

    /**
     * @return array<mixed>
     */
    private static function tree(string $source): array
    {
        $tree = json_decode(Carve::toJson($source), true);
        self::assertIsArray($tree);

        return self::stripPositions($tree);
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function stripPositions(array $node): array
    {
        unset($node['pos'], $node['srcByteLength']);
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::stripPositions($value);
            }
        }

        return $node;
    }
}
