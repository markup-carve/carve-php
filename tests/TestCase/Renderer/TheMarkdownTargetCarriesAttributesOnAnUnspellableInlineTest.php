<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 section 8c, CARVE-P11-045: `underline`, `highlight`, `subscript` and
 * `superscript` have no Markdown delimiter spelling and fall back to inline
 * HTML, carrying their attribute set onto the element they emit. The target
 * used to emit the tag bare, so an authored attribute left the document at the
 * writer and was unrecoverable (markup-carve/carve#2839).
 */
class TheMarkdownTargetCarriesAttributesOnAnUnspellableInlineTest extends TestCase
{
    private static function markdown(string $source): string
    {
        return CarveConverter::create(renderer: new MarkdownRenderer())->convert($source);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function constructProvider(): array
    {
        return [
            'superscript' => ['x{^2^}{.c} y', "x<sup class=\"c\">2</sup> y\n"],
            'highlight' => ['h =hi={.c} y', "h <mark class=\"c\">hi</mark> y\n"],
            'underline' => ['u _u_{.c} y', "u <u class=\"c\">u</u> y\n"],
            'subscript' => ['s {,s,}{.c} y', "s <sub class=\"c\">s</sub> y\n"],
        ];
    }

    #[DataProvider('constructProvider')]
    public function testAClassReachesTheElement(string $source, string $expected): void
    {
        $this->assertSame($expected, self::markdown($source));
    }

    /**
     * Not only `class`: an id, a plain key-value pair and several at once.
     */
    public function testEveryAttributeKindReachesTheElement(): void
    {
        $this->assertSame("id <sup id=\"sid\">2</sup> y\n", self::markdown('id {^2^}{#sid} y'));
        $this->assertSame("kv <mark data-x=\"1\">hi</mark> y\n", self::markdown('kv =hi={data-x=1} y'));
        $this->assertSame(
            "multi <u id=\"uid\" class=\"c1 c2\" data-k=\"v\">u</u> y\n",
            self::markdown('multi _u_{#uid .c1 .c2 data-k=v} y'),
        );
        $this->assertSame("sub <sub id=\"k\" class=\"c\">s</sub> y\n", self::markdown('sub {,s,}{#k .c} y'));
    }

    /**
     * The control that keeps the fix from leaving an empty attribute artifact.
     */
    public function testAConstructWithNoAttributesKeepsItsBareTag(): void
    {
        $this->assertSame(
            "bare <sup>2</sup> <mark>hi</mark> <u>u</u> <sub>s</sub> y\n",
            self::markdown('bare {^2^} =hi= _u_ {,s,} y'),
        );
    }

    /**
     * The assertion that matters: the attribute is still in the document after
     * Carve to Markdown to Carve. The tag itself returns as raw inline HTML
     * rather than as the construct, which is markup-carve/carve#2838's subject,
     * not this one - but the attribute is no longer lost at the writer.
     */
    #[DataProvider('constructProvider')]
    public function testTheAttributeSurvivesARoundTrip(string $source, string $unusedExpected): void
    {
        $this->assertStringContainsString('class="c"', (new MarkdownToCarve())->convert(self::markdown($source)));
    }

    /**
     * The HTML target was already right and must not move.
     */
    #[DataProvider('constructProvider')]
    public function testTheHtmlTargetIsUnchanged(string $source, string $unusedExpected): void
    {
        $this->assertStringContainsString(
            'class="c"',
            CarveConverter::create(renderer: new HtmlRenderer())->convert($source),
        );
    }

    private static function fromAst(string $inline): string
    {
        $json = '{"type":"document","children":[{"type":"paragraph","children":[' . $inline . ']}],"srcByteLength":0}';
        $document = (new AstCodec())->decode(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

        return (new MarkdownRenderer())->render($document);
    }

    /**
     * The two constructs section 8c already gave an attribute rule. Neither can
     * be authored in Carve source, so they are reached through the AST.
     */
    public function testSmallCapsAndRubyStillCarryTheirAttributes(): void
    {
        $this->assertSame(
            "<span class=\"smallcaps c\">sc</span>\n",
            self::fromAst('{"type":"small_caps","attrs":{"classes":["c"]},"children":[{"type":"text","value":"sc"}]}'),
        );
        $this->assertSame(
            "<ruby class=\"c\">x<rp>(</rp><rt>r</rt><rp>)</rp></ruby>\n",
            self::fromAst(
                '{"type":"ruby","attrs":{"classes":["c"]},"pairs":[{"base":[{"type":"text","value":"x"}],'
                . '"annotation":[{"type":"text","value":"r"}]}]}',
            ),
        );
    }
}
