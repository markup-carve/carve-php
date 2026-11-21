<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\DocumentTransform;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\Navigation;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\RenderError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The carve-js reference cases of markup-carve/carve-js#5073 (test/navigation-extensions.test.ts and the registered
 * transform cases of test/document-transforms.test.ts).
 */
final class NavigationTest extends TestCase
{
    /**
     * @var string
     */
    private const DOC = "= Intro\n\nText.\n\n== Usage\n\n=== Deep\n\n== Next\n";

    private static function html(string $source, ExtensionDefinition ...$extensions): string
    {
        return Carve::render($source, ['extensions' => $extensions]);
    }

    private static function suffix(string $name, string $text): ExtensionDefinition
    {
        return new ExtensionDefinition($name, '1', documentTransform: static function (Node $view) use ($text): Node {
            $paragraph = $view->children[0] ?? null;
            if ($paragraph !== null && $paragraph->type === 'paragraph') {
                $node = new Node('text');
                $node->f['value'] = $text;
                $paragraph->children = [...($paragraph->children ?? []), $node];
            }

            return $view;
        });
    }

    public function testRegisteredTransformsRunInRegistrationOrder(): void
    {
        $options = ['extensions' => [self::suffix('host.first', '1'), self::suffix('host.second', '2')]];
        $this->assertSame('<p>a12</p>', Carve::render('a', $options));
        $this->assertSame('<p>a12</p>', Carve::toHtml('a', $options));
        $this->assertSame('<p>a12</p>', Carve::render(Carve::parse('a'), $options));
        $this->assertSame('<p>a12</p>', Carve::renderWithDiagnostics('a', $options)->html);
        $noop = new DocumentTransform('third', static fn (Node $view): Node => $view);
        $this->assertSame('<p>a12</p>', Carve::renderWithDocumentTransforms('a', [$noop], $options)->html);
        $failing = new ExtensionDefinition('host.broken', '1', documentTransform: static function (): Node {
            throw new RuntimeException('boom');
        });
        $result = Carve::renderWithDiagnostics('a', ['extensions' => [$failing]]);
        $this->assertSame('<p>a</p>', $result->html);
        $this->assertSame(['extension-transform-failed'], $result->codes());
        $this->expectException(RenderError::class);
        Carve::render('a', ['extensions' => [$failing], 'strictLosses' => true]);
    }

    public function testTheAuthoredTreeAndAPlainRenderStayUnchanged(): void
    {
        $tree = Carve::parse(self::DOC);
        $before = Carve::encodeTree($tree);
        $extensions = [Navigation::tableOfContents(), Navigation::headingNumbers(), Navigation::headingPermalinks()];
        Carve::render($tree, ['extensions' => $extensions]);
        $this->assertSame($before, Carve::encodeTree($tree));
        $this->assertStringNotContainsString('toc', Carve::render(self::DOC));
    }

    public function testTheIssueExample(): void
    {
        $this->assertSame(implode("\n", [
            '<nav class="toc" aria-label="Table of contents">',
            '  <ul>',
            '    <li><a href="#Intro">Intro</a>',
            '      <ul>',
            '        <li><a href="#Usage">Usage</a></li>',
            '      </ul>',
            '    </li>',
            '  </ul>',
            '</nav>',
            '<section id="Intro">',
            '  <h1><span class="section-number">1</span> Intro <a href="#Intro" class="permalink" aria-label="Permalink">¶</a></h1>',
            '  <section id="Usage">',
            '    <h2><span class="section-number">1.1</span> Usage <a href="#Usage" class="permalink" aria-label="Permalink">¶</a></h2>',
            '    <p>See <a href="#Usage">Section 1.1 - Usage</a>.</p>',
            '  </section>',
            '</section>',
        ]), self::html(
            "= Intro\n\n== Usage\n\nSee </#Usage>.\n",
            Navigation::headingNumbers(),
            Navigation::headingPermalinks(),
            Navigation::tableOfContents(),
        ));
    }

    public function testTableOfContents(): void
    {
        $list = "<ul>\n  <li><a href=\"#Intro\">Intro</a>\n    <ul>\n      <li><a href=\"#Usage\">Usage</a>\n        <ul>\n"
            . "          <li><a href=\"#Deep\">Deep</a></li>\n        </ul>\n      </li>\n      <li><a href=\"#Next\">Next</a></li>\n"
            . "    </ul>\n  </li>\n</ul>";
        $indented = implode("\n", array_map(static fn (string $line): string => '  ' . $line, explode("\n", $list)));
        $html = self::html(self::DOC, Navigation::tableOfContents());
        $this->assertStringStartsWith(
            "<nav class=\"toc\" aria-label=\"Table of contents\">\n{$indented}\n</nav>\n<section id=\"Intro\">",
            $html,
        );
        $this->assertSame($html, Carve::render(self::DOC, ['extensions' => [Navigation::tableOfContents()], 'mode' => 'static']));
        $result = Carve::renderWithDiagnostics(self::DOC, ['extensions' => [Navigation::tableOfContents()->withoutRenderers()]]);
        $this->assertStringStartsWith("<div class=\"toc\">\n{$indented}\n</div>\n", $result->html);
        $this->assertSame(['extension-fallback-rendered'], $result->codes());
        $this->assertSame('<p>text</p>', self::html("text\n", Navigation::tableOfContents()));
        $this->assertStringContainsString(
            '<li><a href="#start">A <span>b</span> c</a></li>',
            self::html("{#start}\n= A [b]{#inner} c[^n]\n\n[^n]: note\n", Navigation::tableOfContents()),
        );
    }

    public function testTableOfContentsOptions(): void
    {
        $toc = Navigation::tableOfContents(minLevel: 2, maxLevel: 2, listType: 'ol', cssClass: 'contents', position: 'bottom');
        // The 0.2 sections close at the end of the document, so the last section holds a table placed at the bottom.
        $this->assertStringEndsWith(
            "    <nav class=\"contents\" aria-label=\"Table of contents\">\n      <ol>\n        <li><a href=\"#Usage\">Usage</a></li>\n"
            . "        <li><a href=\"#Next\">Next</a></li>\n      </ol>\n    </nav>\n  </section>\n</section>",
            self::html(self::DOC, $toc),
        );
        $this->assertStringStartsWith(
            "<details class=\"toc\" open>\n  <summary>On &lt;this&gt; page</summary>\n"
            . "  <ul>\n    <li><a href=\"#A\">A</a></li>\n  </ul>\n</details>\n",
            self::html("= A\n", Navigation::tableOfContents(collapsible: true, summary: 'On <this> page', open: true)),
        );
        $named = Carve::render("= A\n", ['extensions' => [Navigation::tableOfContents()], 'labels' => ['tocNav' => 'Contents']]);
        $this->assertStringStartsWith('<nav class="toc" aria-label="Contents">', $named);
        $bare = Carve::render("= A\n", ['extensions' => [Navigation::tableOfContents()], 'labels' => ['tocNav' => '']]);
        $this->assertStringStartsWith('<nav class="toc">', $bare);
    }

    public function testTocPlacement(): void
    {
        $html = self::html(self::DOC . "\n{depth=2 .side}\n::: toc\n:::\n", Navigation::tocPlacement());
        $this->assertStringContainsString(
            "    <nav class=\"toc side\" aria-label=\"Table of contents\">\n      <ul>\n        <li><a href=\"#Intro\">Intro</a>\n"
            . "          <ul>\n            <li><a href=\"#Usage\">Usage</a></li>\n            <li><a href=\"#Next\">Next</a></li>\n"
            . "          </ul>\n        </li>\n      </ul>\n    </nav>",
            $html,
        );
        $this->assertStringNotContainsString('depth=', $html);
        $html = self::html(self::DOC . "\n{from=3 to=2}\n::: toc \"On this page\"\n:::\n", Navigation::tocPlacement());
        $this->assertStringContainsString(
            "<nav class=\"toc\" aria-labelledby=\"toc-title-1\">\n      <p class=\"admonition-title\" id=\"toc-title-1\">On this page</p>\n"
            . "      <ul>\n        <li><a href=\"#Usage\">Usage</a>\n",
            $html,
        );
        $this->assertStringNotContainsString('#Intro', $html);
        $this->assertStringContainsString(
            '<li><a href="#Inside">Inside</a></li>',
            self::html("::: note\n= Inside\n:::\n\n::: toc\n:::\n", Navigation::tocPlacement()),
        );
        $this->assertStringContainsString(
            "  <p>Intro text.</p>\n  <nav class=\"toc\"",
            self::html("= A\n\n::: toc\nIntro text.\n:::\n", Navigation::tocPlacement()),
        );
    }

    public function testTocPlacementFallbackAndCore(): void
    {
        $fallback = self::html("= A\n\n::: toc\n:::\n", Navigation::tocPlacement()->withoutRenderers());
        $this->assertStringContainsString(
            "<div class=\"toc\">\n    <ul>\n      <li><a href=\"#A\">A</a></li>\n    </ul>\n  </div>",
            $fallback,
        );
        $this->assertStringNotContainsString("<div class=\"toc\">\n    <div", $fallback);
        $core = Carve::render("= A\n\n::: toc\n:::\n");
        $this->assertStringContainsString('<div class="toc">', $core);
        $this->assertStringNotContainsString('<ul>', $core);
    }

    public function testHeadingPermalinks(): void
    {
        $this->assertSame(
            "<section id=\"My-Heading\">\n"
            . "  <h1>My Heading <a href=\"#My-Heading\" class=\"permalink\" aria-label=\"Permalink\">¶</a></h1>\n</section>",
            self::html("= My Heading\n", Navigation::headingPermalinks()),
        );
        $html = self::html("= A\n\n== B\n", Navigation::headingPermalinks(
            symbol: '#',
            cssClass: 'p',
            ariaLabel: 'Link',
            levels: [2],
            prepend: true,
            showOnHover: true,
            copyToClipboard: true,
        ));
        $this->assertStringContainsString('<h1>A</h1>', $html);
        $this->assertStringContainsString(
            '<h2><span class="permalink-wrapper permalink-hover">'
            . '<a href="#B" class="p" aria-label="Link" data-permalink-copy="">#</a></span> B</h2>',
            $html,
        );
        $this->assertStringContainsString(
            '<p>See <a href="#A">A</a> and <a href="#A">A</a>.</p>',
            self::html("= A\n\nSee [A][] and </#A>.\n", Navigation::headingPermalinks()),
        );
    }

    public function testHeadingNumbers(): void
    {
        $html = self::html(self::DOC . "\nSee </#Deep>.\n", Navigation::headingNumbers());
        $this->assertStringContainsString('<h1><span class="section-number">1</span> Intro</h1>', $html);
        $this->assertStringContainsString('<h3><span class="section-number">1.1.1</span> Deep</h3>', $html);
        $this->assertStringContainsString('<h2><span class="section-number">1.2</span> Next</h2>', $html);
        $this->assertStringContainsString('<section id="Deep">', $html);
        $this->assertStringContainsString('<p>See <a href="#Deep">Section 1.1.1 - Deep</a>.</p>', $html);
        $source = "= Title\n\n== A\n\n{.unnumbered}\n== B\n\n> = Quoted\n\n== C\n\nSee </#C> and </#B>.\n";
        $html = self::html($source, Navigation::headingNumbers(minLevel: 2, label: '§', crossref: 'number'));
        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('<h2><span class="section-number">1</span> A</h2>', $html);
        $this->assertStringContainsString('<h2 class="unnumbered">B</h2>', $html);
        $this->assertStringContainsString('<h1 id="Quoted">Quoted</h1>', $html);
        $this->assertStringContainsString('<h2><span class="section-number">2</span> C</h2>', $html);
        $this->assertStringContainsString('See <a href="#C">§ 2</a> and <a href="#B">B</a>.', $html);
        $this->assertStringContainsString('See <a href="#C">C</a>', self::html($source, Navigation::headingNumbers(crossref: 'title')));
        $this->assertStringContainsString(
            '<li><a href="#A">A</a></li>',
            self::html("= A\n", Navigation::headingNumbers(), Navigation::tableOfContents()),
        );
    }

    public function testAnExplicitDisplayModeStaysWithTheCore(): void
    {
        $tree = json_decode(Carve::encodeTree(Carve::parse("== C\n\nSee </#C>.\n")), true);
        foreach ($tree['children'][1]['children'] as $at => $child) {
            if ($child['type'] === 'xref') {
                $tree['children'][1]['children'][$at]['display'] = 'page';
            }
        }
        $html = Carve::render(Carve::decodeTree((string)json_encode($tree)), ['extensions' => [Navigation::headingNumbers()]]);
        $this->assertStringNotContainsString('Section 1 - C', $html);
    }

    public function testHeadingLevelShiftAndReference(): void
    {
        $this->assertSame(
            "<section id=\"A\">\n  <h2>A</h2>\n  <section id=\"F\">\n    <h6>F</h6>\n  </section>\n</section>",
            self::html("= A\n\n====== F\n", Navigation::headingLevelShift()),
        );
        $this->assertStringContainsString('<h6>A</h6>', self::html("= A\n", Navigation::headingLevelShift(9)));
        $this->assertStringContainsString('<h1>A</h1>', self::html("= A\n", Navigation::headingLevelShift(-2)));
        $this->assertStringContainsString(
            '<a href="#Setup" class="heading-ref">Setup</a> <a href="#Setup" class="heading-ref">here</a> <a href="https://x.org">Doc</a>',
            self::html("= Setup\n\n[Setup][] [here][Setup] [Doc][]\n\n[Doc]: https://x.org\n", Navigation::headingReference()),
        );
        $this->assertStringContainsString(
            '<a href="#Setup" class="a b">Setup</a>',
            self::html("= Setup\n\n[Setup][]\n", Navigation::headingNumbers(), Navigation::headingReference('a b')),
        );
    }

    public function testExternalLinks(): void
    {
        $this->assertSame(
            '<p><a href="https://x.org" rel="noopener noreferrer" target="_blank">a</a> '
            . '<a href="http://y.org" rel="noopener noreferrer" target="_blank">http://y.org</a> '
            . '<a href="/local">b</a> <a href="#top">c</a></p>',
            self::html("[a](https://x.org) <http://y.org> [b](/local) [c](#top)\n", Navigation::externalLinks()),
        );
        $this->assertSame(
            '<p><a href="https://x.org" rel="noopener nofollow">a</a></p>',
            self::html("[a](https://x.org){TARGET=self}\n", Navigation::externalLinks(target: '', rel: 'noopener', nofollow: true)),
        );
    }

    public function testLinkPolicy(): void
    {
        $this->assertSame(
            '<p>a https://sub.bad.org pic <a href="https://ok.org" rel="nofollow ugc">b</a></p>',
            self::html(
                "[a](https://bad.org/x) <https://sub.bad.org> ![pic](https://bad.org/i.png) [b](https://ok.org)\n",
                Navigation::linkPolicy(deniedDomains: ['bad.org'], rel: ['nofollow', 'ugc']),
            ),
        );
        $this->assertSame(
            '<p><a href="https://me.org/x">a</a> b <a href="/p">c</a> d</p>',
            self::html(
                "[a](https://me.org/x) [b](https://you.org) [c](/p) [d](//you.org/x)\n",
                Navigation::linkPolicy(allowExternal: false, baseHost: 'ME.org.'),
            ),
        );
        $this->assertSame('<p>c e</p>', self::html("[c](/p) [e](#x)\n", Navigation::linkPolicy(allowInternal: false)));
        $this->assertSame(
            '<p>a b <a href="https://ok.org">c</a></p>',
            self::html(
                "[a](http://2130706433/) [b](http://0x7f.0.0.1/x) [c](https://ok.org)\n",
                Navigation::linkPolicy(deniedDomains: ['127.0.0.1']),
            ),
        );
        $this->assertSame('<p>x y</p>', self::html("[x](doc/page) [y](a/b:c)\n", Navigation::linkPolicy(allowInternal: false)));
        $source = "[a](https://docs.ok.org) [b](https://other.org) [m](mailto:x@ok.org)\n";
        $this->assertSame(
            '<p><a href="https://docs.ok.org">a</a> b <a href="mailto:x@ok.org">m</a></p>',
            self::html($source, Navigation::linkPolicy(allowedDomains: ['ok.org'])),
        );
        $this->assertSame(
            '<p><a href="https://docs.ok.org">a</a> <a href="https://other.org">b</a> m</p>',
            self::html($source, Navigation::linkPolicy(allowedSchemes: ['https'])),
        );
    }

    public function testAutolink(): void
    {
        $this->assertSame(
            '<p>Visit <a href="https://x.org/a">https://x.org/a</a>. Mail <a href="mailto:a@b.co">a@b.co</a>, or '
            . '<a href="mailto:c@d.org">mailto:c@d.org</a>!</p>',
            self::html("Visit https://x.org/a. Mail a@b.co, or mailto:c@d.org!\n", Navigation::autolink()),
        );
        $source = "`https://x.org` [https://y.org](/p) xhttps://z.org\n";
        $this->assertSame(Carve::render($source), self::html($source, Navigation::autolink()));
        $this->assertSame(
            '<p>http://x.org <a href="https://y.org">https://y.org</a> a@b.co</p>',
            self::html("http://x.org https://y.org a@b.co\n", Navigation::autolink(['https'])),
        );
    }
}
