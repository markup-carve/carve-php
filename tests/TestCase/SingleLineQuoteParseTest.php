<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class SingleLineQuoteParseTest extends TestCase
{
    public function testInlineLinkAndFollowingParagraph(): void
    {
        self::assertSame(
            "<blockquote><p>A <a href=\"https://example.com\">link</a>.</p></blockquote>\n<p>After.</p>\n",
            (new CarveConverter())->convert("> A [link](https://example.com).\n\nAfter.\n"),
        );
    }

    public function testCustomBlockMatcherRunsInsideQuote(): void
    {
        $parser = new BlockParser();
        $calls = 0;
        $parser->addBlockPattern('/^A custom/u', static function (
            array $lines,
            int $start,
            Node $parent,
            BlockParser $blockParser,
        ) use (&$calls): int {
            $calls++;
            $paragraph = new Paragraph();
            $paragraph->appendChild(new Text('from matcher'));
            $parent->appendChild($paragraph);

            return 1;
        });

        $converter = CarveConverter::create(parser: $parser);

        self::assertSame("<blockquote><p>from matcher</p></blockquote>\n", $converter->convert("> A custom line\n"));
        self::assertSame(1, $calls);
    }
}
