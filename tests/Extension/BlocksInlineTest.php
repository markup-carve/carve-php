<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Bundle;
use MarkupCarve\Carve\Extension\ColorSwatch;
use MarkupCarve\Carve\Extension\Details;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\ListTable;
use MarkupCarve\Carve\Extension\SemanticSpan;
use MarkupCarve\Carve\Extension\SmartQuotes;
use MarkupCarve\Carve\Extension\Spoiler;
use MarkupCarve\Carve\Extension\Transforms;
use MarkupCarve\Carve\Extension\Wikilinks;
use MarkupCarve\Carve\OptionError;
use PHPUnit\Framework\TestCase;

/**
 * The carve-js reference cases of markup-carve/carve-js#5070 (test/extensions/blocks-inline.test.ts).
 */
final class BlocksInlineTest extends TestCase
{
    /**
     * @param string $source
     * @param \MarkupCarve\Carve\Extension\Bundle $bundle
     * @param array<string, mixed> $options
     */
    private static function html(string $source, Bundle $bundle, array $options = []): string
    {
        return Carve::render($source, ['extensions' => [$bundle]] + $options);
    }

    /**
     * @param string $source
     * @param \MarkupCarve\Carve\Extension\Bundle $bundle
     * @param array<string, mixed> $options
     */
    private static function transformed(string $source, Bundle $bundle, array $options = []): string
    {
        return Carve::renderWithDocumentTransforms($source, $bundle->transforms(), $options)->html;
    }

    public function testExpectedOfTheIssue(): void
    {
        $all = Bundle::merge(Details::bundle(), Spoiler::bundle(), SemanticSpan::bundle(), Wikilinks::bundle());
        $source = "{#faq}\n::: details \"More *info*\"\nHidden.\n:::\n\nPlot :spoiler[the butler], [x]{samp}, :wiki[Main Page#top].\n";
        $this->assertSame(
            "<details id=\"faq\">\n  <summary>More <strong>info</strong></summary>\n  <p>Hidden.</p>\n</details>\n"
            . '<p>Plot <span class="spoiler">the butler</span>, <samp>x</samp>, '
            . '<a href="main-page#top" class="wikilink" data-wikilink="Main Page">Main Page</a>.</p>',
            self::html($source, $all),
        );
    }

    public function testDetails(): void
    {
        $this->assertSame(
            "<details>\n  <summary>More info</summary>\n  <p>Hidden until expanded.</p>\n</details>",
            self::html("::: details \"More info\"\nHidden until expanded.\n:::\n", Details::bundle()),
        );
        $this->assertStringContainsString('<summary>Details</summary>', self::html("::: details\nx\n:::\n", Details::bundle()));
        $this->assertStringContainsString(
            '<summary>a <strong>b</strong> &lt;c&gt;</summary>',
            self::html("::: details \"a *b* <c>\"\nx\n:::\n", Details::bundle()),
        );
        $this->assertStringStartsWith(
            '<details id="faq" class="wide">',
            self::html("{#faq .wide}\n::: details \"Q\"\nA\n:::\n", Details::bundle()),
        );
        $this->assertSame(
            "<ul>\n  <li>x\n    <details>\n      <summary>Details</summary>\n      <p>a</p>\n      <p>b</p>\n"
            . "    </details>\n  </li>\n</ul>",
            self::html("- x\n\n  ::: details\n  a\n\n  b\n  :::\n", Details::bundle()),
        );
        $this->assertStringStartsWith('<details open>', self::html("::: details\nx\n:::\n", Details::bundle(), ['mode' => 'static']));
        $this->assertStringStartsWith(
            '<details open="">',
            self::html("{open}\n::: details\nx\n:::\n", Details::bundle(), ['mode' => 'static']),
        );
        $this->assertStringContainsString('class="admonition note"', self::html("::: note\nx\n:::\n", Details::bundle()));
        $this->assertStringContainsString('<div class="other">', self::html("::: other\nx\n:::\n", Details::bundle()));
        $this->assertSame(
            "<div class=\"details\">\n  <p class=\"admonition-title\">T</p>\n  <p>x</p>\n</div>",
            Carve::render("::: details \"T\"\nx\n:::\n"),
        );
    }

    public function testDetailsTreeWriteAndFallback(): void
    {
        $source = "::: details \"T\"\nx\n:::\n";
        $options = ['extensions' => [Details::bundle()]];
        $node = json_decode(Carve::encodeTree(Carve::parse($source, $options)), true)['children'][0];
        $this->assertSame('block_extension', $node['type']);
        $this->assertSame('carve.details', $node['name']);
        $this->assertSame(['form' => 'div', 'name' => 'details', 'body' => "x\n", 'title' => 'T'], $node['source']);
        $this->assertSame($source, Carve::write(Carve::parse($source, $options), null, $options));
        $only = ['extensions' => [Details::bundle()->withoutRenderers()]];
        $result = Carve::renderWithDiagnostics(Carve::parse($source, $only), $only);
        $this->assertSame("<p><strong>T</strong></p>\n<p>x</p>", $result->html);
        $this->assertContains('extension-fallback-rendered', $result->codes());
    }

    public function testSpoiler(): void
    {
        $this->assertSame(
            '<p>Plot: <span class="spoiler x">the butler</span>.</p>',
            self::html('Plot: :spoiler[the butler]{.x onclick="a()"}.', Spoiler::bundle()),
        );
        $this->assertSame(
            "<details class=\"spoiler\">\n  <summary>Ending</summary>\n  <p>Everyone lives.</p>\n</details>",
            self::html("::: spoiler \"Ending\"\nEveryone lives.\n:::\n", Spoiler::bundle()),
        );
        $this->assertStringContainsString('<summary>Spoiler</summary>', self::html("::: spoiler\nx\n:::\n", Spoiler::bundle()));
        $this->assertSame(
            '<p><span class="spoiler spoiler-revealed">x</span></p>',
            self::html(':spoiler[x]', Spoiler::bundle(), ['mode' => 'static']),
        );
        $this->assertSame(
            "<section class=\"spoiler spoiler-revealed\">\n  <h3 class=\"spoiler-title\">E</h3>\n  <p>x</p>\n</section>",
            self::html("::: spoiler \"E\"\nx\n:::\n", Spoiler::bundle(), ['mode' => 'static']),
        );
        $this->assertSame('<p><span class="ext-spoiler">x</span></p>', Carve::render(':spoiler[x]'));
        $this->assertSame("<div class=\"spoiler\">\n  <p>x</p>\n</div>", Carve::render("::: spoiler\nx\n:::\n"));
    }

    public function testSemanticSpan(): void
    {
        $this->assertSame(
            '<p><samp>x</samp> <var>y</var> <cite>z</cite> <dfn title="term">w</dfn></p>',
            self::html('[x]{samp} [y]{var} [z]{cite} [w]{dfn=term}', SemanticSpan::bundle()),
        );
        $this->assertSame('<p><kbd><samp>x</samp></kbd></p>', self::html('[x]{kbd samp}', SemanticSpan::bundle()));
        $this->assertSame('<p><span samp="">x</span></p>', Carve::render('[x]{samp}'));
        $codes = static fn (string $source, array $options = []): array => array_map(
            static fn ($d): string => $d->code,
            Carve::lint($source, $options),
        );
        $options = ['extensions' => [SemanticSpan::bundle()]];
        $this->assertSame(['semantic-attribute-outside-span'], $codes("{samp}\nOutput text.\n", $options));
        $this->assertSame([], $codes("{samp}\nOutput text.\n"));
        $this->assertSame(['semantic-attribute-value-ignored'], $codes("[x]{samp=1}\n", $options));
    }

    public function testWikilinks(): void
    {
        $this->assertSame(
            '<p><a href="main-page#top" class="wikilink" data-wikilink="Main Page">Main Page</a></p>',
            self::html(':wiki[Main Page#top]', Wikilinks::bundle()),
        );
        $this->assertSame(
            '<p><a href="docs/install-guide" class="wikilink" data-wikilink="docs/Install Guide">the guide</a></p>',
            self::html(':wiki[docs/Install Guide|the guide]', Wikilinks::bundle()),
        );
        $custom = Wikilinks::bundle(urlGenerator: static fn (string $page): string => '/w/' . $page, cssClass: 'a b', newWindow: true);
        $this->assertSame(
            '<p><a href="/w/P" class="a b" data-wikilink="P" target="_blank" rel="noopener">P</a></p>',
            self::html(':wiki[P]', $custom),
        );
        $this->assertSame(
            '<p><a href="/w/P" class="a b" data-wikilink="P" lang="de" target="_blank" rel="noopener">P</a></p>',
            self::html(':wiki[P]{rel=opener TARGET=x data-wikilink=y HREF=/evil lang=de}', $custom),
        );
        $this->assertSame(
            '<p>P</p>',
            self::html(':wiki[P]', Wikilinks::bundle(urlGenerator: static fn (): string => 'javascript:alert(1)')),
        );
        $this->assertSame('<p><span class="ext-wiki">P</span></p>', Carve::render(':wiki[P]'));
        $node = json_decode(Carve::encodeTree(Carve::parse(':wiki[P]', ['extensions' => [Wikilinks::bundle()]])), true);
        $this->assertSame('inline_extension', $node['children'][0]['children'][0]['type']);
        $this->assertSame('carve.wiki', $node['children'][0]['children'][0]['name']);
    }

    public function testSmartQuotes(): void
    {
        $german = SmartQuotes::bundle('de');
        $this->assertSame('<p>„Hi“ it’s ‚x‘</p>', self::html("\"Hi\" it's 'x'", $german, ['smartTypography' => true]));
        $this->assertSame('<p>"Hi"</p>', self::html('"Hi"', SmartQuotes::bundle('de'), ['smartTypography' => false]));
        $this->assertSame('<p>«a»</p>', self::html('"a"', SmartQuotes::bundle('de_CH')));
        $this->assertSame('<p>«&nbsp;a&nbsp;»</p>', self::html('"a"', SmartQuotes::bundle('fr-CA')));
        $this->assertSame('<p>“a”</p>', self::html('"a"', SmartQuotes::bundle('xx')));
        $this->assertSame('<p>“a!</p>', self::html('"a"', SmartQuotes::bundle(closeDoubleQuote: '!')));
        $this->assertSame(array_keys(SmartQuotes::LOCALES), SmartQuotes::locales());
        $this->assertTrue(SmartQuotes::isLocaleSupported('de-AT'));
        $this->assertFalse(SmartQuotes::isLocaleSupported('xx'));
    }

    public function testListTableAndColorSwatchRenderInBothChannels(): void
    {
        $source = "::: list-table\n- - a\n  - b\n:::\n";
        $html = self::html($source, ListTable::bundle());
        $this->assertStringContainsString('<table>', $html);
        $this->assertSame($html, self::html($source, ListTable::bundle(), ['mode' => 'static']));
        $this->assertStringContainsString('swatch-chip', self::html(':color[#f00]', ColorSwatch::bundle(), ['mode' => 'static']));
        $this->assertStringContainsString('swatch-chip-only', self::html(':color[#f00]', ColorSwatch::bundle(position: 'none')));
    }

    public function testListTableSpansHeadersAndColumns(): void
    {
        $source = "{header-rows=1 widths=\"30,70\" aligns=\"left,right\"}\n::: list-table \"Cap\"\n"
            . "- - H1\n  - H2\n- - a\n  - <\n- - ^\n  - c\n:::\n";
        $this->assertSame(
            "<table>\n  <caption>Cap</caption>\n  <colgroup>\n    <col style=\"width: 30%;\">\n"
            . "    <col style=\"width: 70%;\">\n  </colgroup>\n"
            . "  <thead>\n    <tr><th scope=\"col\" style=\"text-align: left;\">H1</th>"
            . "<th scope=\"col\" style=\"text-align: right;\">H2</th></tr>\n"
            . "  </thead>\n  <tbody>\n    <tr><td colspan=\"2\" style=\"text-align: left;\">a</td></tr>\n"
            . "    <tr><td style=\"text-align: left;\">^</td><td style=\"text-align: right;\">c</td></tr>\n  </tbody>\n</table>",
            self::html($source, ListTable::bundle()),
        );
    }

    public function testColorSwatchOptionsAndContrast(): void
    {
        $this->assertSame(
            '<p><span class="swatch-label" style="background:#ffffff;color:#000">#ffffff</span></p>',
            self::html(':color[#ffffff]{contrast}', ColorSwatch::bundle()),
        );
        $this->assertSame('<p><span class="ext-color">javascript</span></p>', self::html(':color[javascript]', ColorSwatch::bundle()));
        $this->expectException(InvalidArgumentException::class);
        ColorSwatch::bundle(shape: 'star');
    }

    public function testDocumentTransforms(): void
    {
        $defaults = Transforms::defaultAttributes([
            'heading' => ['class' => 'h', 'id' => 'x', 'data-a' => '1'],
            'code_block' => ['class' => 'c'],
        ]);
        $this->assertSame(
            "<h1 id=\"own\" class=\"h\" data-a=\"2\">H</h1>\n<pre class=\"c\"><code>x\n</code></pre>",
            self::transformed("{#own data-a=2}\n# H\n\n```\nx\n```\n", $defaults, ['sections' => false]),
        );
        $this->assertSame(
            "<p>a\tb <code>c    d</code></p>\n<pre><code>    x\n</code></pre>",
            self::transformed("a\tb `c\td`\n\n```\n\tx\n```\n", Transforms::tabNormalize(4)),
        );
        $this->assertSame("<pre><code>  x\n</code></pre>", self::transformed("```\n\tx\n```\n", Transforms::tabNormalize()));
        $this->assertSame(
            '<p>a (two <strong>b</strong>) c</p>',
            self::transformed("a^[two *b*] c\n", Transforms::inlineFootnotesToParentheses()),
        );
        $tree = Carve::parse("a^[n]\n");
        $before = Carve::encodeTree($tree);
        Carve::renderWithDocumentTransforms($tree, Transforms::inlineFootnotesToParentheses()->transforms());
        $this->assertSame($before, Carve::encodeTree($tree));
        $this->expectException(InvalidArgumentException::class);
        Transforms::tabNormalize(-1);
    }

    public function testAHostReplacesABundledRenderer(): void
    {
        $this->assertSame('<x>', Carve::render("::: details\nx\n:::\n", [
            'extensions' => [Details::bundle()],
            'renderers' => ['carve.details' => static fn (): string => '<x>'],
        ]));
    }

    public function testInvalidSemanticKeysAreRefused(): void
    {
        $this->expectException(OptionError::class);
        $this->expectExceptionMessageMatches('/semantic keys/');
        Carve::render('a', ['extensions' => [new ExtensionDefinition('a.b', '1', semanticKeys: [['samp', null]])]]);
    }
}
