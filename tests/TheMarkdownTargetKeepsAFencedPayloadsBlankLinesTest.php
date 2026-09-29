<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every blank line between two fence delimiters is PAYLOAD.
 *
 * The Markdown target collapsed runs of blank lines across the whole assembled
 * document, which is right between two blocks and wrong inside a fence: a
 * payload of two or more blank lines went out as one, so the written document
 * re-read with the rest of the payload gone (carve-php#2736).
 *
 * The bytes are asserted, because the Markdown target is compared across engines
 * byte for byte and carve-js owes the same output. What the bytes have to MEAN
 * is asserted beside them: each case reads its own output back through
 * `MarkdownToCarve`, which follows cmark-gfm 0.29.0.gfm.13, and the HTML has to
 * be the source's. The reference CommonMark reader answers the same for every
 * row below.
 */
class TheMarkdownTargetKeepsAFencedPayloadsBlankLinesTest extends TestCase
{
    protected CarveConverter $markdown;

    protected MarkdownToCarve $reader;

    protected function setUp(): void
    {
        $this->markdown = CarveConverter::markdown();
        $this->reader = new MarkdownToCarve();
    }

    /**
     * The payload's blank lines, written out one for one.
     *
     * @return array<string, array{string, string}>
     */
    public static function payloadsSurvive(): array
    {
        return [
            'no payload line' => ["```\n```\n", "```\n```\n"],
            'one blank payload line' => ["```\n\n```\n", "```\n\n```\n"],
            'two blank payload lines' => ["```\n\n\n```\n", "```\n\n\n```\n"],
            'three blank payload lines' => ["```\n\n\n\n```\n", "```\n\n\n\n```\n"],
            'a blank run between two payload lines' => ["```\na\n\n\nb\n```\n", "```\na\n\n\nb\n```\n"],
            'a payload under a language' => ["``` mermaid\na\n\n\nb\n```\n", "```mermaid\na\n\n\nb\n```\n"],
            'a payload inside a list item' => ["- x\n\n  ```\n\n\n  ```\n", "- x\n  ```\n\n\n  ```\n"],
            // The blanks are written as bare markers here, so they were never
            // blank lines to the collapse and this row never moved.
            'a payload inside a block quote' => ["> ```\n>\n>\n> ```\n", "> ```\n>\n>\n> ```\n"],
        ];
    }

    /**
     * Outside the delimiters the collapse still runs, which is what says the
     * change reaches the payload and nothing else.
     *
     * @return array<string, array{string, string}>
     */
    public static function spacingStillCollapses(): array
    {
        return [
            'between two paragraphs' => ["a\n\n\n\nb\n", "a\n\nb\n"],
            'below a fence whose payload is kept' => ["```\n\n\n```\n\n\n\ntext\n", "```\n\n\n```\n\ntext\n"],
        ];
    }

    #[DataProvider('payloadsSurvive')]
    #[DataProvider('spacingStillCollapses')]
    public function testTheTargetWritesTheseBytes(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->markdown->convert($source));
    }

    #[DataProvider('payloadsSurvive')]
    public function testTheWrittenPayloadReadsBackWhole(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->markdown->convert($source));

        $html = CarveConverter::create();

        $this->assertSame(
            $html->convert($source),
            $html->convert($this->reader->convert($this->markdown->convert($source))),
        );
    }
}
