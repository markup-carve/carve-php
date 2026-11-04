<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5016: a first-line `---` opener claims through its first closer or EOF.
 */
class AFirstLineFrontmatterOpenerClaimsThroughEofTest extends TestCase
{
    #[DataProvider('renderProvider')]
    public function testRender(string $source, string $html): void
    {
        $this->assertSame($html, trim(CarveConverter::create()->convert($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function renderProvider(): iterable
    {
        yield 'bare opener, no closer' => ["---\nx\n", ''];
        yield 'lone opener' => ['---', ''];
        yield 'typed opener, no closer' => ["---yaml\ntitle: a\n\n# T\n", ''];
        yield 'indented closer is payload' => ["---\na\n ---\nx\n", ''];
        yield 'closed block still ends at its closer' => ["---\na\n---\nx\n", '<p>x</p>'];
        yield 'tab-padded format is no opener' => ["---\tyaml\nx\n", "<p>—\tyaml\nx</p>"];
        yield 'opener with a space and a non-format token' => ["--- -\nx\n ---\n", "<p>— -\nx</p>\n<hr>"];
        yield 'indented opener is a break' => [" ---\nx\n", "<hr>\n<p>x</p>"];
    }

    public function testUnclosedFrontmatterSpansThroughTheLastLine(): void
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $ast = (new AstCodec())->encode($converter->parse("---\nx\n"));
        $frontmatter = $ast['children'][0];

        $this->assertSame('frontmatter', $frontmatter['type']);
        $this->assertSame('x', $frontmatter['content']);
        $this->assertSame(['startLine' => 1, 'endLine' => 2, 'startColumn' => 1, 'endColumn' => 2, 'startOffset' => 0, 'endOffset' => 5], $frontmatter['pos']);
    }

    public function testTheWriterClosesUnclosedFrontmatter(): void
    {
        $this->assertSame("---yaml\nx\n---\n", CarveConverter::toCarve("---\nx"));
    }

    public function testTheWriterRespellsALeadingBreakWithNoLaterDashLine(): void
    {
        $this->assertSame("***\n\nx\n", CarveConverter::toCarve("***\n\nx\n"));
    }
}
