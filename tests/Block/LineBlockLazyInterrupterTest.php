<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6593, C2-B-024, C2-B-113: an open stanza takes no lazy line that interrupts a paragraph, and the blank lines
 * at the end of an unclosed line block stand between the blocks around it.
 */
final class LineBlockLazyInterrupterTest extends TestCase
{
    /**
     * @var string
     */
    private const ITEM = "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>a</p>\n    </div>\n  </li>\n";

    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'a sibling bullet' => ["- ::: |\n  a\n- c\n", self::ITEM . "  <li>c</li>\n</ul>"],
            'a sibling bullet after a blank line' => ["- ::: |\n  a\n\n- c\n", self::ITEM . "  <li><p>c</p></li>\n</ul>"],
            'an ordered marker' => [
                "- ::: |\n  a\n1. c\n",
                self::ITEM . "</ul>\n<ol>\n  <li>c</li>\n</ol>",
            ],
            'a table row below a quote' => [
                "> ::: |\n> a\n||\n",
                "<blockquote>\n  <div class=\"line-block\">\n    <p>a</p>\n  </div>\n</blockquote>\n"
                . "<table>\n  <tbody>\n    <tr><td></td></tr>\n  </tbody>\n</table>",
            ],
            'a plain lazy line stays in the stanza' => [
                "- ::: |\n  a\nc\n",
                "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>a<br>\nc</p>\n    </div>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('sources')]
    public function testTheStanzaEndsBeforeAnInterrupter(string $source, string $expected): void
    {
        $this->assertSame($expected, Carve::toHtml($source));
    }

    public function testACommentAfterTheStanzaIsKept(): void
    {
        $doc = Carve::parse("- ::: |\n  a\n\n%% c\n");
        $types = array_map(static fn ($node): string => $node->type, $doc->children ?? []);

        $this->assertSame(['list', 'comment'], $types);
    }
}
