<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\AttributeFallback;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown target degrades an inline mark to a raw `<mark>` but used to drop
 * a container's attributes and an image's attributes outright, so `{=x=}`
 * survived an export while `{#id .class data-*}` did not (carve-php#458).
 *
 * `AttributeFallback::Html` is the opt-in that keeps them, as raw HTML. The
 * default stays Drop, so no consumer's output moves.
 */
class MarkdownAttributeFallbackTest extends TestCase
{
    /**
     * The example from the issue: a container carrying id/class/data, an
     * attributed image, and an inline mark.
     *
     * @var string
     */
    private const ISSUE_SOURCE = <<<'CARVE'
        {#c1 .calc data-unit="kWh"}
        ::: calc
        Value 42
        :::

        ![P](p.png){.wide}

        Text with {=mark=}
        CARVE;

    private function drop(): CarveConverter
    {
        return CarveConverter::create(null, new MarkdownRenderer());
    }

    private function html(): CarveConverter
    {
        return CarveConverter::create(
            null,
            (new MarkdownRenderer())->setAttributeFallback(AttributeFallback::Html),
        );
    }

    public function testDropIsTheDefaultAndKeepsTodaysOutput(): void
    {
        $expected = <<<'MD'
            Value 42

            ![P](p.png)

            Text with <mark>mark</mark>

            MD;

        $this->assertSame($expected, CarveConverter::markdown()->convert(self::ISSUE_SOURCE));
        $this->assertSame($expected, $this->drop()->convert(self::ISSUE_SOURCE));
    }

    public function testHtmlModeKeepsContainerAndImageAttributes(): void
    {
        $expected = <<<'MD'
            <div class="calc" id="c1" data-unit="kWh">

            Value 42

            </div>

            <img src="p.png" alt="P" class="wide">

            Text with <mark>mark</mark>

            MD;

        $this->assertSame($expected, $this->html()->convert(self::ISSUE_SOURCE));
    }

    /**
     * The wrapper is separated from its body by blank lines so a Markdown parser
     * still parses the body as Markdown rather than as one opaque HTML block.
     */
    public function testHtmlModeBodyStaysMarkdown(): void
    {
        $expected = <<<'MD'
            <div class="box">

            **bold** and a list:

            - one
            - two

            </div>

            MD;

        $source = <<<'CARVE'
            ::: box
            *bold* and a list:

            - one
            - two
            :::
            CARVE;

        $this->assertSame($expected, $this->html()->convert($source));
    }

    public function testHtmlModeAddsNoWrapperWithoutAttributes(): void
    {
        $source = <<<'CARVE'
            :::
            Body
            :::
            CARVE;

        $this->assertSame("Body\n", $this->html()->convert($source));
        $this->assertSame("Body\n", $this->drop()->convert($source));
    }

    /**
     * Every attribute name was sanitized away, so there is nothing left to carry
     * and the container must not gain an attribute-less wrapper either.
     */
    public function testHtmlModeAddsNoWrapperWhenEveryAttributeIsSanitizedAway(): void
    {
        $source = <<<'CARVE'
            {onclick="alert(1)"}
            :::
            Body
            :::
            CARVE;

        $this->assertSame("Body\n", $this->html()->convert($source));
    }

    public function testHtmlModeNestsWrappers(): void
    {
        $expected = <<<'MD'
            <div class="outer">

            <div class="inner" id="deep">

            Deep

            </div>

            </div>

            MD;

        $source = <<<'CARVE'
            :::: outer
            {#deep}
            ::: inner
            Deep
            :::
            ::::
            CARVE;

        $this->assertSame($expected, $this->html()->convert($source));
    }

    /**
     * The bold title/label lines renderDiv() already surfaces stay, and stay
     * INSIDE the wrapper: they are content the container introduces, so a
     * consumer re-parsing the Markdown finds them where the container is.
     */
    public function testHtmlModeKeepsTheAdmonitionTitleInsideTheWrapper(): void
    {
        $expected = <<<'MD'
            <div class="note">

            **Heads up**

            Body

            </div>

            MD;

        $source = <<<'CARVE'
            ::: note "Heads up"
            Body
            :::
            CARVE;

        $this->assertSame($expected, $this->html()->convert($source));
        $this->assertSame("**Heads up**\n\nBody\n", $this->drop()->convert($source));
    }

    public function testHtmlModeKeepsTheGroupLabelInsideTheWrapper(): void
    {
        $expected = <<<'MD'
            <div class="tabs">

            **First**

            Body

            </div>

            MD;

        $source = <<<'CARVE'
            ::: tabs [First]
            Body
            :::
            CARVE;

        $this->assertSame($expected, $this->html()->convert($source));
        $this->assertSame("**First**\n\nBody\n", $this->drop()->convert($source));
    }

    public function testHtmlModeKeepsAnImageTitle(): void
    {
        $this->assertSame(
            '<img src="p.png" alt="P" title="T" class="wide">' . "\n",
            $this->html()->convert('![P](p.png "T"){.wide}'),
        );
    }

    public function testHtmlModeLeavesAnUnattributedImageAsMarkdown(): void
    {
        $this->assertSame("![P](p.png)\n", $this->html()->convert('![P](p.png)'));
    }

    /**
     * `src` / `alt` / `title` are spelled by the tag itself, so an attribute of
     * the same name is not emitted a second time: a duplicate attribute is
     * invalid HTML and an HTML parser keeps only the first occurrence, which is
     * the one the tag wrote.
     */
    public function testHtmlModeDoesNotEmitAnAttributeTheTagAlreadySpells(): void
    {
        $this->assertSame(
            '<img src="p.png" alt="P" class="wide">' . "\n",
            $this->html()->convert('![P](p.png){alt=Q .wide}'),
        );
        $this->assertSame(
            '<img src="p.png" alt="P" title="T" class="wide">' . "\n",
            $this->html()->convert('![P](p.png "T"){title=U .wide}'),
        );
        $this->assertSame(
            '<img src="p.png" alt="P" class="wide">' . "\n",
            $this->html()->convert('![P](p.png){src=evil.png .wide}'),
        );
    }

    /**
     * A `title` attribute is the only source of the title when the image itself
     * carries none, so it is kept.
     */
    public function testHtmlModeKeepsATitleAttributeOnAnImageWithoutOne(): void
    {
        $this->assertSame(
            '<img src="p.png" alt="P" title="U">' . "\n",
            $this->html()->convert('![P](p.png){title=U}'),
        );
    }

    /**
     * The shadowed name was the only attribute, so nothing survives to carry and
     * the ordinary Markdown image is emitted rather than a tag that adds nothing.
     */
    public function testHtmlModeFallsBackToMarkdownWhenOnlyAShadowedNameIsSet(): void
    {
        $this->assertSame("![P](p.png)\n", $this->html()->convert('![P](p.png){alt=Q}'));
    }

    /**
     * A quote or an angle bracket in an attribute value must not be able to close
     * the attribute or open a tag - the value goes through the HTML renderer's own
     * attribute escaper, not a hand-rolled one.
     */
    public function testAttributeValuesAreEscapedForTheAttributeContext(): void
    {
        $source = <<<'CARVE'
            {data-x="\"><b>x</b>" data-y="a'b&c"}
            ::: k
            B
            :::
            CARVE;

        $expected = <<<'MD'
            <div class="k" data-x="&quot;&gt;&lt;b&gt;x&lt;/b&gt;" data-y="a&apos;b&amp;c">

            B

            </div>

            MD;

        $this->assertSame($expected, $this->html()->convert($source));
    }

    public function testImageAltAndTitleAreEscapedForTheAttributeContext(): void
    {
        $this->assertSame(
            '<img src="p.png" alt="&quot;&gt;&amp;&lt;b&gt;" title="&quot;&gt;&amp;" class="wide">' . "\n",
            $this->html()->convert('![">&<b>](p.png "\">&"){.wide}'),
        );
    }

    /**
     * A hostile attribute NAME is dropped by the same validation the HTML target
     * applies - `on*` handlers, the `srcdoc` / `formaction` sinks, and any name
     * failing the identifier check. The identifier check matters because an
     * extension can set an attribute name the parser would never produce, which
     * is how a name-level bypass landed once before.
     *
     * A benign `data-keep` rides along in every case, so the wrapper IS emitted
     * and the assertion sees the tag the hostile name would have landed in.
     */
    #[DataProvider('hostileAttributeNameProvider')]
    public function testHostileAttributeNamesAreDropped(string $name, string $value): void
    {
        $document = (new CarveConverter())->parse(":::\nB\n:::\n");
        $div = $this->firstDiv($document);
        $div->setAttribute('data-keep', 'yes');
        $div->setAttribute($name, $value);

        $renderer = (new MarkdownRenderer())->setAttributeFallback(AttributeFallback::Html);

        $this->assertSame(
            '<div data-keep="yes">' . "\n\nB\n\n</div>\n",
            $renderer->render($document),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hostileAttributeNameProvider(): array
    {
        return [
            'event handler' => ['onclick', 'alert(1)'],
            'mixed-case event handler' => ['OnMouseOver', 'alert(1)'],
            'srcdoc sink' => ['srcdoc', '<script>alert(1)</script>'],
            'formaction sink' => ['formaction', 'x'],
            'quote breaking out of the name' => ['x"onmouseover="alert(1)', 'y'],
            'angle bracket in the name' => ['x><script', 'y'],
            'space in the name' => ['data-a b', 'y'],
            'equals in the name' => ['x=1 y', 'z'],
        ];
    }

    /**
     * A raw `<img src>` is a URL sink, so it gets the SAME denylist the Markdown
     * destination already gets - not a subset (compare carve-php#462).
     */
    #[DataProvider('dangerousSchemeProvider')]
    public function testDenylistedImageSourcesAreBlanked(string $url): void
    {
        $this->assertSame(
            '<img src="" alt="P" class="wide">' . "\n",
            $this->html()->convert('![P](' . $url . '){.wide}'),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousSchemeProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'file' => ['file:///etc/passwd'],
            // The CVE-2026-20841 class of OS protocol handlers: present in the
            // shared denylist, absent from the four-scheme copy this renderer
            // used to carry.
            'ms-msdt' => ['ms-msdt:/id'],
            'search-ms' => ['search-ms:query=x'],
            'shell' => ['shell:AppsFolder'],
            'vscode' => ['vscode:extension/x'],
            'jar' => ['jar:http://x!/y'],
            'whitespace hidden' => ["\u{202F}javascript:alert(1)"],
        ];
    }

    public function testDenylistedSchemeInAnAttributeValueIsBlanked(): void
    {
        $source = <<<'CARVE'
            {data-href="javascript:alert(1)"}
            ::: k
            B
            :::
            CARVE;

        $expected = <<<'MD'
            <div class="k" data-href="">

            B

            </div>

            MD;

        $this->assertSame($expected, $this->html()->convert($source));
    }

    /**
     * A value carrying a newline cannot break out of the opening tag: a blank
     * line there would end the raw HTML block early and leave `</div>` dangling.
     */
    public function testAttributeValueNewlinesDoNotSplitTheOpeningTag(): void
    {
        $document = (new CarveConverter())->parse(":::\nB\n:::\n");
        $div = $this->firstDiv($document);
        $div->setAttribute('data-a', "one\n\ntwo");

        $renderer = (new MarkdownRenderer())->setAttributeFallback(AttributeFallback::Html);

        $this->assertSame(
            '<div data-a="one two">' . "\n\nB\n\n</div>\n",
            $renderer->render($document),
        );
    }

    private function firstDiv(Document $document): Div
    {
        foreach ($document->getChildren() as $child) {
            if ($child instanceof Div) {
                return $child;
            }
        }

        $this->fail('No div in the parsed document.');
    }
}
