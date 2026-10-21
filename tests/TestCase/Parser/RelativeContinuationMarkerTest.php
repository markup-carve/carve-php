<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\FenceAndContinuationLinter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RelativeContinuationMarkerTest extends TestCase
{
    public static function cases(): iterable
    {
        yield 'nested bullet' => ["- a\n  - b\n  +\n  > q", "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>"];
        yield 'nested ordered' => ["1. a\n   1. b\n   +\n   # H", "<ol>\n  <li>a\n    <ol>\n      <li>b\n        <h1 id=\"H\">H</h1>\n      </li>\n    </ol>\n  </li>\n</ol>"];
        yield 'task bullet' => ["- foo\n  - [ ] task\n  +\n  > q", "<ul>\n  <li>foo\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled aria-label=\"task\"> task\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>"];
        yield 'task checkbox column' => ["- [ ] task\n  +\n  > q", "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled aria-label=\"task +\"> task\n+\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>"];
        yield 'quote prefix' => ["> - a\n> +\n> # H", "<blockquote>\n  <ul>\n    <li>a\n      <h1 id=\"H\">H</h1>\n    </li>\n  </ul>\n</blockquote>"];
        yield 'quote absent prefix' => ["> - a\n  +", "<blockquote>\n  <ul>\n    <li>a\n+</li>\n  </ul>\n</blockquote>"];
        yield 'description' => [":: t\n: a\n+\n# H", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <h1 id=\"H\">H</h1>\n  </dd>\n</dl>"];
        yield 'footnote refused' => ["[^a]: a\n+\n  > q", "<p>+\n&gt; q</p>"];
        yield 'table before marker' => ["- a\n  | a |\n  +\n  # H", "<ul>\n  <li>a\n    <table>\n      <tbody>\n        <tr><td>a</td></tr>\n      </tbody>\n    </table>\n    +\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>"];
        yield 'tab columns' => ["- a\n\t+\n\t# H", "<ul>\n  <li>a\n+\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>"];
        yield 'stacked list' => ["- - +\n# H", "<ul>\n  <li>\n    <ul>\n      <li>\n        <h1 id=\"H\">H</h1>\n      </li>\n    </ul>\n  </li>\n</ul>"];
        yield 'stacked quote' => ["- > +\n# H", "<ul>\n  <li>\n    <blockquote>\n      <h1 id=\"H\">H</h1>\n    </blockquote>\n  </li>\n</ul>"];
        yield 'first block document column' => ["- a\n  - +\n> q", "<ul>\n  <li>a\n    <ul>\n      <li>\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>"];
        yield 'first block opener column' => ["- a\n  - +\n  > q", "<ul>\n  <li>a\n    <ul>\n      <li>\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>"];
        yield 'first block between columns' => ["- a\n  - +\n x", "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>\n<p>x</p>"];
        yield 'left follower' => ["- a\n  - b\n  +\n# H", "<ul>\n  <li>a\n    <ul>\n      <li>b\n+</li>\n    </ul>\n  </li>\n</ul>\n<section id=\"H\">\n  <h1>H</h1>\n</section>"];
        yield 'right follower' => ["- a\n  - b\n  +\n    # H", "<ul>\n  <li>a\n    <ul>\n      <li>b\n+\n        <h1 id=\"H\">H</h1>\n      </li>\n    </ul>\n  </li>\n</ul>"];
        yield 'bullet sibling' => ["- a\n  - b\n  +\n  - c", "<ul>\n  <li>a\n    <ul>\n      <li>b\n+</li>\n      <li>c</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'ordered sibling' => ["1. a\n   1. b\n   +\n   2. c", "<ol>\n  <li>a\n    <ol>\n      <li>b\n+</li>\n      <li>c</li>\n    </ol>\n  </li>\n</ol>"];
        yield 'task sibling' => ["- a\n  - [ ] b\n  +\n  - c", "<ul>\n  <li>a\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled aria-label=\"b +\"> b\n+</li>\n    </ul>\n    <ul>\n      <li>c</li>\n    </ul>\n  </li>\n</ul>"];
        yield 'blank refusal' => ["- a\n+\n\n# H", "<ul>\n  <li>a\n+</li>\n</ul>\n<section id=\"H\">\n  <h1>H</h1>\n</section>"];
        yield 'end refusal' => ["- a\n+", "<ul>\n  <li>a\n+</li>\n</ul>"];
        yield 'fence payload' => ["- ```\n  +\n\n  x\n  ```", "<ul>\n  <li>\n    <pre><code>+\n\nx\n</code></pre>\n  </li>\n</ul>"];
        yield 'lazy paragraph' => ["> - a\n  +", "<blockquote>\n  <ul>\n    <li>a\n+</li>\n  </ul>\n</blockquote>"];
        yield 'refused paragraph interruption' => ["- a\n\n+\n- s", "<ul>\n  <li>a</li>\n</ul>\n<p>+</p>\n<ul>\n  <li>s</li>\n</ul>"];
        yield 'task first block' => ["- [x] +\n# H", "<ul class=\"task-list\">\n  <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled> \n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>"];
        yield 'ordered text' => ["1. [ ] +\n# H", "<ol>\n  <li>[ ] +</li>\n</ol>\n<section id=\"H\">\n  <h1>H</h1>\n</section>"];
        yield 'empty task first block' => ['- [x] +', "<ul class=\"task-list\">\n  <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled> </li>\n</ul>"];
        yield 'nested quote marker' => ["- a\n  > q\n  +\n  para", "<ul>\n  <li>a\n    <blockquote>\n      <p>q</p>\n      <p>para</p>\n    </blockquote>\n  </li>\n</ul>"];
        yield 'right of marker' => ["- a\n  +\n    # H", "<ul>\n  <li>a\n+\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>"];
        yield 'description first block' => [":: t\n: +\n# H", "<dl>\n  <dt>t</dt>\n  <dd>\n    <h1 id=\"H\">H</h1>\n  </dd>\n</dl>"];
        yield 'footnote first block' => ["[^a]: +\n# H\n\n[^a]", "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <h1 id=\"H\">H</h1>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"];
    }

    #[DataProvider('cases')]
    public function testRelativeMarkerRendering(string $source, string $html): void
    {
        $this->assertSame(trim($html), trim((new CarveConverter())->convert($source)), $source);
    }

    public function testNestedTightItemRoundTrips(): void
    {
        $source = "- a\n  - +\n> q";
        $converter = new CarveConverter();
        $document = $converter->parse($source);
        $written = (new CarveRenderer())->render($document);
        $this->assertSame($converter->render($document), $converter->convert($written));
        $this->assertSame($written, CarveConverter::toCarve($written));
    }

    public function testQuotedMarkerLintUsesPhysicalColumn(): void
    {
        $warnings = (new FenceAndContinuationLinter())->lint("> - a\n> +\n> \n");
        $refusals = array_values(array_filter($warnings, static fn ($warning): bool => $warning->rule === 'continuation-marker-refused'));
        $this->assertCount(1, $refusals);
        $this->assertSame([2, 3], [$refusals[0]->line, $refusals[0]->column]);
    }

    public function testFencePayloadAndOrdinaryTextDoNotWarn(): void
    {
        $linter = new FenceAndContinuationLinter();
        foreach (["foo\n+\nbar\n", "- a\n\n  ```\n  x\n  +\n\n  y\n  ```\n", "```\n- a\n  +\n  b\n```\n"] as $source) {
            $refusals = array_filter($linter->lint($source), static fn ($warning): bool => $warning->rule === 'continuation-marker-refused');
            $this->assertCount(0, $refusals, $source);
        }
    }

    public function testRightFollowerReportsRefusal(): void
    {
        $warnings = (new FenceAndContinuationLinter())->lint("- a\n+\n    # H\n");
        $refusals = array_values(array_filter($warnings, static fn ($warning): bool => $warning->rule === 'continuation-marker-refused'));
        $this->assertCount(1, $refusals);
        $this->assertSame([2, 1], [$refusals[0]->line, $refusals[0]->column]);
    }

    public function testIndentedFirstBlockMarkerUsesTheOpenerColumn(): void
    {
        $linter = new FenceAndContinuationLinter();
        foreach ([0 => false, 1 => true, 2 => false, 3 => true, 4 => true] as $column => $refused) {
            $fence = str_repeat(' ', $column) . '```';
            $source = "- foo\n  - +\n{$fence}\n" . str_repeat(' ', $column) . "x\n{$fence}\n";
            $warnings = array_values(array_filter(
                $linter->lint($source),
                static fn ($warning): bool => $warning->rule === 'continuation-marker-refused',
            ));
            $this->assertCount($refused ? 1 : 0, $warnings, "follower column {$column}");
            if ($refused) {
                $this->assertSame([2, 5], [$warnings[0]->line, $warnings[0]->column]);
            }
        }
        foreach (["- foo\n  - +\n- x\n", "- foo\n  - +\n  * x\n", "- foo\n  - +\n  1. x\n"] as $source) {
            $warnings = array_values(array_filter(
                $linter->lint($source),
                static fn ($warning): bool => $warning->rule === 'continuation-marker-refused',
            ));
            $this->assertCount(1, $warnings, $source);
            $this->assertSame([2, 5], [$warnings[0]->line, $warnings[0]->column]);
        }
    }

    public function testSeparateMarkerRefusesNestedListAtTheContentColumn(): void
    {
        $source = "- foo\n  - b\n  +\n    - x\n";
        $warnings = array_values(array_filter(
            (new FenceAndContinuationLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === 'continuation-marker-refused',
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame([3, 3], [$warnings[0]->line, $warnings[0]->column]);
    }

    public function testAnUnclosedFenceEndingWithItsQuoteDoesNotHideALaterRefusal(): void
    {
        $warnings = (new FenceAndContinuationLinter())->lint("> ```\n> code\n- item\n+\n");
        $refusals = array_values(array_filter($warnings, static fn ($warning): bool => $warning->rule === 'continuation-marker-refused'));
        $this->assertCount(1, $refusals);
        $this->assertSame([4, 1], [$refusals[0]->line, $refusals[0]->column]);
    }
}
