<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Import\HtmlNode;
use MarkupCarve\Carve\Import\HtmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * libxml below 2.14 lets HTML5 void elements hold content and keeps bogus comments as text; the fallback repairs both.
 */
final class LegacyParserRepairsTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function inputs(): array
    {
        return [
            'wbr' => ['<p>a<wbr>b<wbr>c</p>', 'p[#a,wbr[],#b,wbr[],#c]'],
            'source and track' => [
                '<video><source src="a.mp4"><track src="a.vtt">x</video>',
                'video[source[],track[],#x]',
            ],
            'embed before a block' => ['<div><embed src="a">t<p>u</p></div>', 'div[embed[],#t,p[#u]]'],
            'downlevel-revealed comment' => ['<p>a<![if !x]>b<![endif]>c</p>', 'p[#a,![if !x],#b,![endif],#c]'],
            'bogus comment at the end' => ['<p>a</p><!x', 'p[#a],!x'],
            'markup declaration in an attribute' => ['<p title="<!x>">a</p>', 'p[#a]'],
            'markup declaration in raw text' => ['<textarea><!x></textarea>', 'textarea[#<!x>]'],
            'comment and doctype untouched' => ['<!-- c --><p>a</p>', '! c ,p[#a]'],
        ];
    }

    #[DataProvider('inputs')]
    public function testLegacyTree(string $html, string $expected): void
    {
        $this->assertSame($expected, self::shape(HtmlParser::parse($html, true)));
    }

    #[DataProvider('inputs')]
    public function testHtml5TreeAgrees(string $html, string $expected): void
    {
        if (!HtmlParser::html5()) {
            $this->markTestSkipped('only the libxml parser exists on this PHP');
        }
        $this->assertSame($expected, self::shape(HtmlParser::parse($html)));
    }

    public function testAttributeKeepsTheMarkupDeclaration(): void
    {
        $paragraph = HtmlParser::parse('<p title="<!x>">a</p><!y>', true)->children[0];
        $this->assertSame('<!x>', $paragraph->attr('title'));
    }

    public function testAnEndTagPrefixDoesNotEndRawText(): void
    {
        $textarea = HtmlParser::parse('<textarea>a</textareas><!x></textarea>', true)->children[0];
        $this->assertCount(1, $textarea->children);
        $this->assertStringEndsWith('<!x>', $textarea->children[0]->name);
    }

    private static function shape(HtmlNode $node): string
    {
        $parts = [];
        foreach ($node->children as $child) {
            $parts[] = match ($child->kind) {
                HtmlNode::TEXT => '#' . $child->name,
                HtmlNode::COMMENT => '!' . $child->name,
                default => $child->name . '[' . self::shape($child) . ']',
            };
        }

        return implode(',', $parts);
    }
}
