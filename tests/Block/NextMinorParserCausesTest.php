<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The carve-php lines of carve#6724, #6705, #6686, #6630, #6629, #6713, #6704, #6632 and #6680: the outputs of the
 * tickets' Expected sections, which carve-js next2 gives.
 */
final class NextMinorParserCausesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function renders(): array
    {
        return [
            '6724 item quote comment closes at column 0' => [
                "- > %%%\n> %%%\n",
                "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<blockquote>\n\n</blockquote>",
            ],
            '6724 task item' => [
                "- [ ] > %%%\n> %%%\n",
                "<ul class=\"task-list\">\n"
                . "  <li class=\"task\" style=\"list-style: none\"><input type=\"checkbox\" disabled> \n"
                . "    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<blockquote>\n\n</blockquote>",
            ],
            '6705 tab before the second dot' => [". \t. x\n", "<p>. \t. x</p>"],
            '6705 tab before the second dot inside an item' => ["- . \t. x\n", "<ul>\n  <li>. \t. x</li>\n</ul>"],
            '6686 hash and a space stay a line' => [
                "- ::: |\n  a\n# \n",
                "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>a<br>\n#</p>\n    </div>\n  </li>\n</ul>",
            ],
            '6686 equals and a space stay a line' => [
                "- ::: |\n  a\n= \n",
                "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>a<br>\n=</p>\n    </div>\n  </li>\n</ul>",
            ],
            '6686 equals heading ends the item' => [
                "- ::: |\n  a\n= x\n",
                "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>a</p>\n    </div>\n  </li>\n</ul>\n"
                . "<section id=\"x\">\n  <h1>x</h1>\n</section>",
            ],
            '6629 mark before a closing brace' => [
                "||\n^ Table {k=#}\n",
                "<table>\n  <caption>Table {k=1}</caption>\n  <tbody>\n    <tr><td></td></tr>\n  </tbody>\n</table>",
            ],
            '6629 mark before a space and brace' => [
                "||\n^ Table {k=# }\n",
                "<table>\n  <caption>Table {k=1 }</caption>\n  <tbody>\n    <tr><td></td></tr>\n  </tbody>\n</table>",
            ],
            '6713 glued block on a paragraph' => ["{abbr=a ABBR=b}{abbr=c}\npara\n", '<p abbr="c">para</p>'],
            '6713 time on a list' => ["{time=a TIME=b}{TiMe=c}{time=d}\n- x\n", "<ul time=\"d\">\n  <li>x</li>\n</ul>"],
            '6713 first spelling kept' => ["{ABBR=a abbr=b}{ABBR=c}\npara\n", '<p ABBR="c">para</p>'],
            '6704 lone CR stays in the heading key' => [
                "# Plan\rB\n\n[see][Plan B]\n",
                "<section id=\"Plan-B\">\n  <h1>Plan\rB</h1>\n  <p>[see][Plan B]</p>\n</section>",
            ],
            '6704 key with the CR finds the heading' => [
                "# Plan\rB\n\n[see][Plan\rB]\n",
                "<section id=\"Plan-B\">\n  <h1>Plan\rB</h1>\n  <p><a href=\"#Plan-B\">see</a></p>\n</section>",
            ],
            '6632 vertical tab is no whitespace of align' => ["{align=\"CENTER\v\"}\na\n", "<p align=\"CENTER\u{FFFD}\">a</p>"],
            '6632 form feed is' => ["{align=\"\fcenter\"}\na\n", '<p style="text-align: center;">a</p>'],
            '6680 NUL in an autolink' => ["<https://a.b/\0x>\n", "<p><a href=\"https://a.b/\u{FFFD}x\">https://a.b/\u{FFFD}x</a></p>"],
            '6680 NUL behind a scheme' => ["<c:\0>\n", "<p><a href=\"c:\u{FFFD}\">c:\u{FFFD}</a></p>"],
        ];
    }

    #[DataProvider('renders')]
    public function testRender(string $source, string $html): void
    {
        $this->assertSame($html, Carve::toHtml($source));
    }

    /**
     * @return array<string, array{string, array<string>}>
     */
    public static function lineBlocks(): array
    {
        return [
            'blank quote line behind a lazy closer' => ["> ::: |\n> %%%\n%%%\n>\n> %%%\n", ['comment:', 'line', 'comment:']],
            'blank quote line behind a prefixed closer' => ["> ::: |\n> %%%\n> %%%\n>\n> %%%\n", ['comment:', 'line', 'comment:']],
            'blank line before an open comment' => ["::: |\n\n%%% c\n\n", ['line', 'comment:c']],
            'held blank lines of an open comment' => ["- ::: |\n  %%%%\n  %%%\n\n", ['comment:%%%']],
        ];
    }

    /**
     * carve#6630, C2-A-069, C2-B-123: a comment block in a line block adds no line and ends no stanza.
     *
     * @param string $source
     * @param array<string> $expected
     */
    #[DataProvider('lineBlocks')]
    public function testLineBlockChildren(string $source, array $expected): void
    {
        $block = self::first(Carve::parse($source), 'line_block');
        $this->assertNotNull($block);
        $children = array_map(
            static fn (Node $node): string => $node->type === 'comment' && is_string($node->f['value'] ?? null)
                ? 'comment:' . $node->f['value'] : $node->type,
            $block->children ?? [],
        );
        $this->assertSame($expected, $children);
    }

    /**
     * carve#6713, C2-A-035: on every node but a span the key written last wins, in the first spelling.
     */
    public function testLaterSemanticKeyWinsOffSpans(): void
    {
        $paragraph = self::first(Carve::parse("{abbr=a ABBR=b}{abbr=c}\npara\n"), 'paragraph');
        $attrs = $paragraph?->f['attrs'] ?? null;
        $this->assertInstanceOf(Attrs::class, $attrs);
        $this->assertSame([['abbr', 'c']], $attrs->pairs);
    }

    private static function first(Node $node, string $type): ?Node
    {
        if ($node->type === $type) {
            return $node;
        }
        foreach ($node->children ?? [] as $child) {
            $found = self::first($child, $type);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
