<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bracket pair that forms nothing is text, so a star or slash inside it pairs with one outside (C2-I-007,
 * C2-W-088). Each expected form is the one carve-js next2 writes.
 */
final class MarkAcrossTextBracketTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function trees(): array
    {
        $t = static fn (string $value): array => ['type' => 'text', 'value' => $value];

        return [
            'star into a bracket' => [[$t('*[*]')], "\\*[\\*]\n"],
            'star into a bracket inside text' => [[$t('a *[*] b')], "a \\*[\\*] b\n"],
            'star out of a bracket' => [[$t('[*]*')], "[\\*]*\n"],
            'slash into a bracket' => [[$t('/[/]')], "\\/[\\/]\n"],
            'star across a lone bracket, kind round kept' => [[$t('*]*[!=*')], "\\*]\\*[\\!=*\n"],
            'span holding its own slash between words' => [[$t('a[/b/b]]/ [')], "a[/b/b]]/ [\n"],
            'star pair around a bracket' => [[$t('*a [*] b*')], "\\*a [\\*] b*\n"],
            'star from a bracket to the end' => [[$t('x [*a] b*')], "x [\\*a] b*\n"],
            'star into a bracket in link text' => [
                [['type' => 'link', 'href' => '/u', 'children' => [$t('a *[*] b')]]],
                "[a \\*[\\*] b](/u)\n",
            ],
        ];
    }

    /**
     * @param array<mixed> $nodes
     * @param string $expected
     */
    #[DataProvider('trees')]
    public function testWritesCarveJsFormThatReadsBack(array $nodes, string $expected): void
    {
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'paragraph', 'children' => $nodes]]];

        $source = Carve::write($tree);

        $this->assertSame($expected, $source);
        $this->assertTrue(TreeEquality::equal($tree, TreeEncoder::decodedForWriter(Carve::parse($source))));
    }

    public function testFormatKeepsTheEscapedStar(): void
    {
        $this->assertSame("\\*[\\*]\n", Carve::format("*[\\*]\n"));
    }
}
