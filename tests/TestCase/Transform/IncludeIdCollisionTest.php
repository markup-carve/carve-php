<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Explicit ids brought together by include expansion (spec PART 9 section 19,
 * I5): one namespace for every element, renamed on collision across file
 * inclusions, with the renamed file's own references following.
 */
class IncludeIdCollisionTest extends TestCase
{
    /**
     * @param string $source
     * @param array<string, string> $files
     * @param string $expected
     * @param list<string> $messages
     */
    #[DataProvider('collisionProvider')]
    public function testExplicitIdsAreRenamedAcrossInclusions(string $source, array $files, string $expected, array $messages): void
    {
        [$html, $warnings] = $this->expand($source, $files);

        $this->assertSame($expected, $html);
        $this->assertSame($messages, $warnings);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string, list<string>}>
     */
    public static function collisionProvider(): iterable
    {
        yield 'two paragraphs, and the child link follows its own target' => [
            "{#tip}\nKeep the dough cold.\n\n{{ child.crv }}\n",
            ['child.crv' => "{#tip}\nRest it overnight.\n\n[The tip above](#tip) is the one this file wrote.\n"],
            "<p id=\"tip\">Keep the dough cold.</p>\n<p id=\"tip-2\">Rest it overnight.</p>\n"
                . "<p><a href=\"#tip-2\">The tip above</a> is the one this file wrote.</p>\n",
            ["child.crv: Duplicate id 'tip' renamed to 'tip-2'"],
        ];

        yield 'a heading and a paragraph share one namespace' => [
            "{#tip}\n# Tip\n\n{{ c.crv }}\n",
            ['c.crv' => "{#tip}\npara\n"],
            "<section id=\"tip\">\n  <h1>Tip</h1>\n  <p id=\"tip-2\">para</p>\n</section>\n",
            ["c.crv: Duplicate id 'tip' renamed to 'tip-2'"],
        ];

        yield 'an inline span collides with a block' => [
            "A [span]{#tip} here.\n\n{{ c.crv }}\n",
            ['c.crv' => "{#tip}\npara\n"],
            "<p>A <span id=\"tip\">span</span> here.</p>\n<p id=\"tip-2\">para</p>\n",
            ["c.crv: Duplicate id 'tip' renamed to 'tip-2'"],
        ];

        yield 'a child span is renamed and its link follows' => [
            "{#tip}\npara\n\n{{ c.crv }}\n",
            ['c.crv' => "A [span]{#tip} and [go](#tip).\n"],
            "<p id=\"tip\">para</p>\n<p>A <span id=\"tip-2\">span</span> and <a href=\"#tip-2\">go</a>.</p>\n",
            ["c.crv: Duplicate id 'tip' renamed to 'tip-2'"],
        ];

        yield 'ids differing only in case do not collide' => [
            "{#Tip}\npara\n\n{{ c.crv }}\n",
            ['c.crv' => "{#tip}\nchild\n"],
            "<p id=\"Tip\">para</p>\n<p id=\"tip\">child</p>\n",
            [],
        ];

        yield 'duplicates one file holds on its own are left alone' => [
            "{{ c.crv }}\n",
            ['c.crv' => "{#tip}\none\n\n{#tip}\ntwo\n"],
            "<p id=\"tip\">one</p>\n<p id=\"tip\">two</p>\n",
            [],
        ];

        yield 'a link to an id only the parent defines is not rewritten' => [
            "{#top}\nTop.\n\n{{ c.crv }}\n",
            ['c.crv' => "[up](#top) and [self](#tip)\n\n{#tip}\nmine\n"],
            "<p id=\"top\">Top.</p>\n<p><a href=\"#top\">up</a> and <a href=\"#tip\">self</a></p>\n<p id=\"tip\">mine</p>\n",
            [],
        ];

        yield 'a link through the own reference definition follows' => [
            "{#tip}\nparent\n\n{{ c.crv }}\n",
            ['c.crv' => "{#tip}\nchild\n\n[a][r] and ![i][r]\n\n[r]: #tip\n"],
            "<p id=\"tip\">parent</p>\n<p id=\"tip-2\">child</p>\n"
                . "<p><a href=\"#tip-2\">a</a> and <img src=\"#tip-2\" alt=\"i\"></p>\n",
            ["c.crv: Duplicate id 'tip' renamed to 'tip-2'"],
        ];

        yield 'a cross-reference differing only in case does not follow' => [
            "{#tip}\n# Parent\n\n{{ c.crv }}\n",
            ['c.crv' => "{#tip}\n# Child\n\nSee </#TIP>.\n"],
            "<section id=\"tip\">\n  <h1>Parent</h1>\n</section>\n<section id=\"tip-2\">\n  <h1>Child</h1>\n"
                . "  <p>See &lt;/#TIP&gt;.</p>\n</section>\n",
            ["c.crv: Duplicate heading id 'tip' renamed to 'tip-2'"],
        ];

        yield 'a cross-reference matching an auto slug exactly stays put' => [
            "{#Tip}\np\n\n{{ c.crv }}\n",
            ['c.crv' => "# tip\n\n{#Tip}\n# Other\n\nSee </#tip>.\n"],
            "<p id=\"Tip\">p</p>\n<section id=\"tip\">\n  <h1>tip</h1>\n</section>\n"
                . "<section id=\"Tip-2\">\n  <h1>Other</h1>\n  <p>See <a href=\"#tip\">tip</a>.</p>\n</section>\n",
            ["c.crv: Duplicate heading id 'Tip' renamed to 'Tip-2'"],
        ];

        yield 'an auto slug suffixed past an explicit id does not stop the follow' => [
            "{#tip}\n# Parent\n\n{{ c.crv }}\n",
            ['c.crv' => "{#tip}\n# Child\n\n# tip\n\nSee </#tip>.\n"],
            "<section id=\"tip\">\n  <h1>Parent</h1>\n</section>\n<section id=\"tip-2\">\n  <h1>Child</h1>\n</section>\n"
                . "<section id=\"tip-3\">\n  <h1>tip</h1>\n  <p>See <a href=\"#tip-2\">Child</a>.</p>\n</section>\n",
            ["c.crv: Duplicate heading id 'tip' renamed to 'tip-2'"],
        ];

        yield 'an exact match on an auto slug ignores a renamed id of another case' => [
            "{#TIP}\np\n\n{{ c.crv }}\n",
            ['c.crv' => "# tip\n\n{#TIP}\n| a |\n\nSee </#tip>.\n"],
            "<p id=\"TIP\">p</p>\n<section id=\"tip\">\n  <h1>tip</h1>\n  <table id=\"TIP-2\">\n"
                . "    <tbody>\n      <tr><td>a</td></tr>\n    </tbody>\n  </table>\n"
                . "  <p>See <a href=\"#tip\">tip</a>.</p>\n</section>\n",
            ["c.crv: Duplicate id 'TIP' renamed to 'TIP-2'"],
        ];

        yield 'an uncaptioned table is no cross-reference target' => [
            "{#TIP}\np\n\n{{ c.crv }}\n",
            ['c.crv' => "{#TIP}\n| a |\n\n# tip\n\nSee </#tip>.\n"],
            "<p id=\"TIP\">p</p>\n<table id=\"TIP-2\">\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n"
                . "<section id=\"tip\">\n  <h1>tip</h1>\n  <p>See <a href=\"#tip\">tip</a>.</p>\n</section>\n",
            ["c.crv: Duplicate id 'TIP' renamed to 'TIP-2'"],
        ];

        yield 'a numbered table caption is a cross-reference target that follows' => [
            "{#tbl}\np\n\n{{ c.crv }}\n",
            ['c.crv' => "{#tbl}\n| a |\n^ Table #: data\n\nSee </#tbl>.\n"],
            "<p id=\"tbl\">p</p>\n<table id=\"tbl-2\">\n  <caption>Table 1: data</caption>\n"
                . "  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n<p>See <a href=\"#tbl-2\">Table 1</a>.</p>\n",
            ["c.crv: Duplicate id 'tbl' renamed to 'tbl-2'"],
        ];

        yield 'a parent link keeps reaching the parent target' => [
            "{#tip}\nparent [p](#tip)\n\n{{ c.crv }}\n",
            ['c.crv' => "{#tip}\nchild\n"],
            "<p id=\"tip\">parent <a href=\"#tip\">p</a></p>\n<p id=\"tip-2\">child</p>\n",
            ["c.crv: Duplicate id 'tip' renamed to 'tip-2'"],
        ];

        yield 'two inclusions of one file collide, each link follows its own' => [
            "{{ c.crv }}\n\n{{ c.crv }}\n",
            ['c.crv' => "{#tip}\nchild\n\n[go](#tip)\n"],
            "<p id=\"tip\">child</p>\n<p><a href=\"#tip\">go</a></p>\n"
                . "<p id=\"tip-2\">child</p>\n<p><a href=\"#tip-2\">go</a></p>\n",
            ["c.crv: Duplicate id 'tip' renamed to 'tip-2'"],
        ];

        yield 'the rename takes the least free suffix' => [
            "{#tip}\na\n\n{#tip-2}\nb\n\n{{ c.crv }}\n",
            ['c.crv' => "{#tip}\nchild\n"],
            "<p id=\"tip\">a</p>\n<p id=\"tip-2\">b</p>\n<p id=\"tip-3\">child</p>\n",
            ["c.crv: Duplicate id 'tip' renamed to 'tip-3'"],
        ];

        yield 'each colliding copy in one child gets its own suffix, links follow the first' => [
            "{#d}\np\n\n{{ c.crv }}\n",
            ['c.crv' => "{#d}\na [l](#d)\n\n{#d}\nb\n"],
            "<p id=\"d\">p</p>\n<p id=\"d-2\">a <a href=\"#d-2\">l</a></p>\n<p id=\"d-3\">b</p>\n",
            ["c.crv: Duplicate id 'd' renamed to 'd-2'", "c.crv: Duplicate id 'd' renamed to 'd-3'"],
        ];

        yield 'the suffix skips an id the parent declares later' => [
            "{#d}\np\n\n{{ c.crv }}\n\n{#d-2}\nlater\n",
            ['c.crv' => "{#d}\nchild\n"],
            "<p id=\"d\">p</p>\n<p id=\"d-3\">child</p>\n<p id=\"d-2\">later</p>\n",
            ["c.crv: Duplicate id 'd' renamed to 'd-3'"],
        ];

        yield 'parent before child, child before grandchild' => [
            "{#tip}\np\n\n{{ a.crv }}\n",
            ['a.crv' => "{#tip}\na\n\n{{ b.crv }}\n", 'b.crv' => "{#tip}\nb [l](#tip)\n"],
            "<p id=\"tip\">p</p>\n<p id=\"tip-2\">a</p>\n<p id=\"tip-3\">b <a href=\"#tip-3\">l</a></p>\n",
            ["a.crv: Duplicate id 'tip' renamed to 'tip-2'", "b.crv: Duplicate id 'tip' renamed to 'tip-3'"],
        ];

        yield 'a selected block collides with the parent' => [
            "{#dough}\nmine\n\n{{ r.crv #dough }}\n",
            ['r.crv' => "{#dough}\n```text\nflour\n```\n"],
            "<p id=\"dough\">mine</p>\n<pre id=\"dough-2\"><code class=\"language-text\">flour\n</code></pre>\n",
            ["r.crv: Duplicate id 'dough' renamed to 'dough-2'"],
        ];

        yield 'a table caption link follows the renamed table' => [
            "{#tip}\np\n\n{{ c.crv }}\n",
            ['c.crv' => "{#tip}\n| a |\n^ Table: [go](#tip)\n"],
            "<p id=\"tip\">p</p>\n<table id=\"tip-2\">\n  <caption>Table: <a href=\"#tip-2\">go</a></caption>\n"
                . "  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n",
            ["c.crv: Duplicate id 'tip' renamed to 'tip-2'"],
        ];

        yield 'an id inside a table caption collides too' => [
            "[s]{#cap}\n\n{{ c.crv }}\n",
            ['c.crv' => "| a |\n^ Table: [s]{#cap} [go](#cap)\n"],
            "<p><span id=\"cap\">s</span></p>\n<table>\n"
                . "  <caption>Table: <span id=\"cap-2\">s</span> <a href=\"#cap-2\">go</a></caption>\n"
                . "  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n",
            ["c.crv: Duplicate id 'cap' renamed to 'cap-2'"],
        ];

        yield 'a footnote label never collides with an element id' => [
            "{#n}\nparent\n\n{{ c.crv }}\n",
            ['c.crv' => "Note[^n].\n\n[^n]: body\n"],
            "<p id=\"n\">parent</p>\n<p>Note<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>.</p>\n"
                . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
                . "      <p>body<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
                . "    </li>\n  </ol>\n</section>\n",
            [],
        ];
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     *
     * @return array{string, list<string>}
     */
    protected function expand(string $source, array $files): array
    {
        $converter = new CarveConverter();
        $expander = new IncludeExpander($this->resolver($files));
        $html = $converter->render($converter->transform($converter->parse($source), $expander));
        $warnings = array_map(
            static fn ($warning): string => $warning->getFile() . ': ' . $warning->getMessage(),
            $expander->getWarnings(),
        );

        return [$html, $warnings];
    }

    /**
     * @param array<string, string> $files
     *
     * @throws \RuntimeException
     */
    protected function resolver(array $files): IncludeResolverInterface
    {
        return new class ($files) implements IncludeResolverInterface {
            /**
             * @param array<string, string> $files
             */
            public function __construct(private readonly array $files)
            {
            }

            public function resolve(string $path, IncludeContext $context): string
            {
                if (!array_key_exists($path, $this->files)) {
                    throw new RuntimeException("Missing include: {$path}");
                }

                return $this->files[$path];
            }
        };
    }
}
