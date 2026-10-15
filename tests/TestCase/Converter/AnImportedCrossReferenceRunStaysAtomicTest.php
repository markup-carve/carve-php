<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class AnImportedCrossReferenceRunStaysAtomicTest extends TestCase
{
    public function testMarkdownStrikeKeepsCrossrefRun(): void
    {
        $this->assertImported('~a </#x~>y> b~', (new MarkdownToCarve())->convert('~~a </#x~>y> b~~'), 'x~');
    }

    public function testHtmlStrikeKeepsCrossrefRun(): void
    {
        $this->assertImported("~a </#x~>y> b~\n", (new HtmlToCarve())->convert('<p><s>a &lt;/#x~&gt;y&gt; b</s></p>'), 'x~');
    }

    public function testMarkdownInlineFootnoteKeepsCrossrefRun(): void
    {
        $this->assertImported('^[a </#x]> b]', (new MarkdownToCarve(convertInlineFootnotes: true))->convert('^[a </#x]> b]'), 'x]');
    }

    public function testHtmlInlineFootnoteKeepsCrossrefRun(): void
    {
        $source = '^[a </#x]> b]';
        $html = (new CarveConverter(roundTripMode: true))->convert($source);
        $this->assertImported($source . "\n", (new HtmlToCarve())->convert($html), 'x]');
    }

    public function testFormattingEscapedTextDoesNotCreateCrossref(): void
    {
        self::assertSame("<\\/#b>\n", CarveConverter::toCarve("<\\/#b>\n"));
    }

    public function testEscapedMarkdownRunStaysText(): void
    {
        self::assertSame('\\</\\#x>', (new MarkdownToCarve())->convert('\\</#x>'));
    }

    public function testEscapedHtmlRunStaysText(): void
    {
        self::assertSame("see \\\\</\\#x> here\n", (new HtmlToCarve())->convert('<p>see \\&lt;/#x&gt; here</p>'));
    }

    public function testMarkdownRunWhoseIdResolvesStaysText(): void
    {
        self::assertSame("# Intro\n\nsee </\\#Intro> and </#y>", (new MarkdownToCarve())->convert("# Intro\n\nsee </#Intro> and </#y>"));
    }

    public function testHtmlRunWhoseIdResolvesStaysText(): void
    {
        self::assertSame("# Intro\n\nsee </\\#Intro> and </#y>\n", (new HtmlToCarve())->convert('<h1>Intro</h1><p>see &lt;/#Intro&gt; and &lt;/#y&gt;</p>'));
    }

    public function testMarkdownEntitySpellingStaysText(): void
    {
        self::assertSame("# x\n\na \\<\\/\\#x> b \\</\\#x> c", (new MarkdownToCarve())->convert("# x\n\na &lt;/#x> b &#60;/#x> c"));
        self::assertSame('a \\<\\/\\#x> b \\</\\#x> c', (new MarkdownToCarve())->convert('a &lt;/#x> b &#60;/#x> c'));
    }

    public function testDjotRunStaysText(): void
    {
        self::assertSame('plain </\\#x> text', (new DjotToCarve())->convert('plain </#x> text'));
    }

    private function assertImported(string $expected, string $actual, string $target): void
    {
        self::assertSame($expected, $actual);
        $tree = (new AstCodec())->encode((new BlockParser())->parse($actual));
        $targets = [];
        $visit = static function (array $node) use (&$visit, &$targets): void {
            if (($node['type'] ?? null) === 'heading_ref') {
                $targets[] = $node['target'];
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $visit($value);
                }
            }
        };
        $visit($tree);
        self::assertSame([$target], $targets);
    }
}
