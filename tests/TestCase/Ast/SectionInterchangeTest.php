<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Node\Block\Section;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §30 (CARVE-P12-052): an explicit sectioning wrapper an importer
 * built, which the canonical writer flattens back to its headings.
 */
final class SectionInterchangeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function payload(?int $level = null): array
    {
        $section = ['type' => 'section'];
        if ($level !== null) {
            $section['level'] = $level;
        }
        $section['children'] = [
            ['type' => 'heading', 'level' => 3, 'attrs' => ['id' => 'topic'], 'children' => [['type' => 'text', 'value' => 'Topic']]],
            ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'Body']]],
        ];

        return ['type' => 'document', 'srcByteLength' => 0, 'children' => [$section]];
    }

    public function testTheStatedLevelRoundTrips(): void
    {
        $codec = new AstCodec();
        $document = $codec->decode(self::payload(3));
        $section = $document->getChildren()[0];

        self::assertInstanceOf(Section::class, $section);
        self::assertSame(3, $section->getLevel());
        self::assertSame(self::payload(3), $codec->encode($document));
        self::assertSame(self::payload(3), $codec->encode(clone $document));
    }

    public function testAnAbsentLevelStaysAbsent(): void
    {
        $codec = new AstCodec();
        $document = $codec->decode(self::payload());
        $section = $document->getChildren()[0];

        self::assertInstanceOf(Section::class, $section);
        self::assertNull($section->getLevel());
        self::assertSame(self::payload(), $codec->encode($document));
    }

    public function testALevelOutsideTheHeadingRangeIsRefused(): void
    {
        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode(self::payload(7));
    }

    public function testHtmlRendersTheEnclosedBlocks(): void
    {
        $document = (new AstCodec())->decode(self::payload(3));

        self::assertSame(
            "<section>\n  <h3 id=\"topic\">Topic</h3>\n  <p>Body</p>\n</section>\n",
            (new HtmlRenderer())->render($document),
        );
    }

    public function testCarveFlattensTheSectionBackToItsHeadings(): void
    {
        $document = (new AstCodec())->decode(self::payload(3));

        self::assertSame("### Topic\n\nBody", trim((new CarveRenderer())->render($document)));
    }
}
