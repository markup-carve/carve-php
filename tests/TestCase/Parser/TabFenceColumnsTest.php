<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TabFenceColumnsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function fenceColumns(): array
    {
        return [
            'tab-floor-1' => [
                "- a\n    ```\n    code\n\t```\t\nafter\n",
                "<ul><li>a\n    <pre><code>code\n</code></pre></li></ul><p>after</p>",
            ],
            'tab-floor-3' => [
                "- a\n\t```\n\tcode\n\t```\t\nafter\n",
                "<ul><li>a\n    <pre><code>code\n</code></pre></li></ul><p>after</p>",
            ],
            'tab-floor-5' => [
                "- a\n  \t```\n  \tcode\n\t```\t\nafter\n",
                "<ul><li>a\n    <pre><code>code\n</code></pre></li></ul><p>after</p>",
            ],
            'tab-floor-7' => [
                "- a\n    ~~~~\n    code\n\t~~~~\t\nafter\n",
                "<ul><li>a\n    <pre><code>code\n</code></pre></li></ul><p>after</p>",
            ],
            'tab-floor-9' => [
                "- a\n\t~~~~\n\tcode\n\t~~~~\t\nafter\n",
                "<ul><li>a\n    <pre><code>code\n</code></pre></li></ul><p>after</p>",
            ],
            'tab-floor-11' => [
                "- a\n  \t~~~~\n  \tcode\n\t~~~~\t\nafter\n",
                "<ul><li>a\n    <pre><code>code\n</code></pre></li></ul><p>after</p>",
            ],
            'tab-floor-13' => [
                "> - a\n>     ```\n>     code\n> \t```\t\n> after\n",
                "<blockquote><ul><li>a\n      <pre><code>code\n</code></pre></li></ul><p>after</p></blockquote>",
            ],
            'tab-floor-15' => [
                "> - a\n> \t```\n> \tcode\n> \t```\t\n> after\n",
                "<blockquote><ul><li>a\n      <pre><code>code\n</code></pre></li></ul><p>after</p></blockquote>",
            ],
            'tab-floor-17' => [
                "> - a\n>   \t```\n>   \tcode\n> \t```\t\n> after\n",
                "<blockquote><ul><li>a\n      <pre><code>code\n</code></pre></li></ul><p>after</p></blockquote>",
            ],
            'tab-floor-19' => [
                "> - a\n>     ~~~~\n>     code\n> \t~~~~\t\n> after\n",
                "<blockquote><ul><li>a\n      <pre><code>code\n</code></pre></li></ul><p>after</p></blockquote>",
            ],
            'tab-floor-21' => [
                "> - a\n> \t~~~~\n> \tcode\n> \t~~~~\t\n> after\n",
                "<blockquote><ul><li>a\n      <pre><code>code\n</code></pre></li></ul><p>after</p></blockquote>",
            ],
            'tab-floor-23' => [
                "> - a\n>   \t~~~~\n>   \tcode\n> \t~~~~\t\n> after\n",
                "<blockquote><ul><li>a\n      <pre><code>code\n</code></pre></li></ul><p>after</p></blockquote>",
            ],
            'barrier-floor-24' => [
                "> - a\n>     ```\n>   # heading\n>   ```\n> after\n",
                "<blockquote><ul><li>a\n      <pre><code># heading\n</code></pre></li></ul><p>after</p></blockquote>",
            ],
            'barrier-floor-25' => [
                "> - a\n>     ```\n>   %%%\n>   hidden\n>   %%%\n>   ```\n> after\n",
                "<blockquote><ul><li>a\n      <pre><code>%%%\nhidden\n%%%\n</code></pre></li></ul><p>after</p></blockquote>",
            ],
            'barrier-floor-26' => [
                "> - a\n>     ```\n>   ::: |\n>   inner\n>   :::\n>   ```\n> after\n",
                "<blockquote><ul><li>a\n      <pre><code>::: |\ninner\n:::\n</code></pre></li></ul><p>after</p></blockquote>",
            ],
            'barrier-floor-27' => [
                "> - a\n>     ```\n> - sibling\n>   ```\n> after\n",
                "<blockquote><ul><li>a\n<code></code></li><li>sibling\n<code>\nafter</code></li></ul></blockquote>",
            ],
        ];
    }

    #[DataProvider('fenceColumns')]
    public function testFenceColumnsSurviveItemRebasing(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);
        $normalized = preg_replace('/>\s+</', '><', trim($html));
        $this->assertSame(preg_replace('/>\s+</', '><', $expected), $normalized);
    }

    public function testRawFenceRebasingPreservesPayloadAndReleasesTail(): void
    {
        $doc = (new CarveConverter())->parse("- a\n  \t```=html\n  \t<b>payload</b>\n\t```\t\nafter\n");
        $children = $doc->getChildren();
        $this->assertCount(2, $children);
        $this->assertInstanceOf(Paragraph::class, $children[1]);
        $item = $children[0]->getChildren()[0];
        $raw = $item->getChildren()[1];
        $this->assertInstanceOf(RawBlock::class, $raw);
        $this->assertSame("<b>payload</b>\n", $raw->getContent());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function reviewedPreservationControls(): array
    {
        return [
            'review2-0' => [
                "1. a\n\t\t````\n   {.c}\n\t\t~~~\n",
                "<ol>\n  <li>a\n<code></code>\n    <pre class=\"c\"><code></code></pre>\n  </li>\n</ol>\n",
            ],
            'review2-3' => [
                "- a\n  - b\n    \t```=html\n> q\n\t  `````\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>=html\n</code></li>\n    </ul>\n  </li>\n</ul>\n<blockquote><p>q\n<code></code></p></blockquote>\n",
            ],
            'review2-4' => [
                "- [x] t\n\t \t````\n\t \t%% c\n    \t```\n",
                "<ul class=\"task-list\">\n  <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled aria-label=\"t\"> t\n<code></code>\n    <pre><code></code></pre>\n  </li>\n</ul>\n",
            ],
            'review2-5' => [
                "- [x] t\n\t \t`````\n \t %% c\n\t \t~~~\n",
                "<ul class=\"task-list\">\n  <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled aria-label=\"t\"> t\n<code></code>\n    <pre><code></code></pre>\n  </li>\n</ul>\n",
            ],
            'non-tab-stop-raw-base' => [
                "- a\n  > q\n\t\t[^n]: f\n  # h\n\t  ```=html\n\t- s\n    ``` \n\t```make\n    \t```\t\nafter\n",
                "<ul>\n  <li>a\n    <blockquote><p>q</p></blockquote>\n    <h1 id=\"h\">h</h1>\n      - s\n  ``` \n  ```make\n  </li>\n</ul>\n<p>after</p>\n",
            ],
            'review3-p2-0' => [
                "- a\n  \t  ```\n   \t x\n  \t  ```\n",
                "<ul>\n  <li>a\n    <pre><code> \t x\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'review3-p2-1' => [
                "- a\n  \t  ```\n   \t x\n  \t  ```\n\nafter\n",
                "<ul>\n  <li>a\n    <pre><code> \t x\n</code></pre>\n  </li>\n</ul>\n<p>after</p>\n",
            ],
            'review3-p2-2' => [
                "- a\n\n  \t  ```\n   \t x\n  \t  ```\n",
                "<ul>\n  <li>a\n    <pre><code> \t x\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'review3-p2-3' => [
                "- a\n  \t  ```\n  x\n  \t```\n  y\n  \t  ```\n",
                "<ul>\n  <li>a\n    <pre><code>x\n\t```\ny\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'review3-p2-4' => [
                "- a\n  \t  ```\n  \t```\n  \t  ```\n",
                "<ul>\n  <li>a\n    <pre><code>\t```\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'review3-p2-5' => [
                "- a\n  \t  ```\n  \t```\n  y\n  \t  ```\n\nafter\n",
                "<ul>\n  <li>a\n    <pre><code>\t```\ny\n</code></pre>\n    <pre><code></code></pre>\n  </li>\n</ul>\n<p>after</p>\n",
            ],
            'review3-p2-6' => [
                "1. a\n   \t  ```\n   \tx\n   \t  ```\n",
                "<ol>\n  <li>a\n    <pre><code>\tx\n</code></pre>\n  </li>\n</ol>\n",
            ],
            'review3-p2-7' => [
                "1. a\n    \t```\n   \t x\n    \t```\n",
                "<ol>\n  <li>a\n    <pre><code> x\n</code></pre>\n  </li>\n</ol>\n",
            ],
            'review3-p2-8' => [
                "- a\n  \t  ```=html\n   \t <b>\n  \t  ```\n",
                "<ul>\n  <li>a\n     \t <b>\n  </li>\n</ul>\n",
            ],
            'review3-p2-9' => [
                "- a\n  \t ```\n  \tx\n  \t ```\n",
                "<ul>\n  <li>a\n    <pre><code>\tx\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'review3-p2-10' => [
                "- a\n  \t ```\n   \tx\n  \t ```\n",
                "<ul>\n  <li>a\n    <pre><code> \tx\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'review3-p5-0' => [
                "- t\n  \t> q\n   \t{.c}\n\t\t%% c\n  \t ````\n \t{.c}\n \t```\n",
                "<ul>\n  <li>t\n    <blockquote><p>q</p></blockquote>\n    <p class=\"c\"><code></code></p>\n    <pre class=\"c\"><code></code></pre>\n  </li>\n</ul>\n",
            ],
            'review3-p5-1' => [
                "- t\n  \t> q\n\t\t%% c\n  \t ````\n \t```\n",
                "<ul>\n  <li>t\n    <blockquote><p>q</p></blockquote>\n    <code>\n```</code>\n  </li>\n</ul>\n",
            ],
            'review3-p5-2' => [
                "- t\n  > q\n  %% c\n  \t ````\n  x\n",
                "<ul>\n  <li>t\n    <blockquote><p>q</p></blockquote>\n    <pre><code>x\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'review3-p5-3' => [
                "- t\n  > q\n  \t ````\n  x\n",
                "<ul>\n  <li>t\n    <blockquote><p>q\n<code>\nx</code></p></blockquote>\n  </li>\n</ul>\n",
            ],
            'review3-p5-4' => [
                "- t\n  > q\n  {.c}\n  \t ````\n  x\n",
                "<ul>\n  <li>t\n    <blockquote><p>q</p></blockquote>\n    <pre class=\"c\"><code>x\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'review3-p5-5' => [
                "- t\n  \t> q\n   \t{.c}\n  \t ````\n  x\n",
                "<ul>\n  <li>t\n    <blockquote><p>q</p></blockquote>\n    <p class=\"c\"><code>\nx</code></p>\n  </li>\n</ul>\n",
            ],
            'failed-admission-0' => [
                "- t\n  \t~~~~\n\n    x\n  \t  ~~~~\n",
                "<ul>\n  <li>t\n~~~~\n    x\n~~~~\n  </li>\n</ul>\n",
            ],
            'failed-admission-1' => [
                "- t\n  \t```\n\n    x\n  \t  ```\n",
                "<ul>\n  <li>t\n<code></code>\n    x\n<code></code>\n  </li>\n</ul>\n",
            ],
            'failed-admission-2' => [
                "- t\n  \t```=html\n\n    <b>\n  \t  ```\n",
                "<ul>\n  <li>t\n<code>=html</code>\n    &lt;b&gt;\n<code></code>\n  </li>\n</ul>\n",
            ],
            'earlier-attributes-0' => [
                "> 1. a\n> \t  {.c}\n>    \t````\n>  \t~~\n>     ````\n",
                "<blockquote>\n  <ol>\n    <li>a\n      <pre class=\"c\"><code> ~~\n ````\n</code></pre>\n    </li>\n  </ol>\n</blockquote>\n",
            ],
            'earlier-attributes-1' => [
                "1. a\n   - \n \t\t{.c}\n \t%% c\n   \t```\n\t````\n  y\n",
                "<ol>\n  <li>a\n-\n    <pre class=\"c\"><code> ````\n</code></pre>\n  </li>\n</ol>\n<p>y</p>\n",
            ],
            'below-fence-column' => [
                "- a\n  # h\n      ```\n    x\n   ```\n      ```\nafter\n",
                "<ul>\n  <li>a\n    <h1 id=\"h\">h</h1>\n    <pre><code>  x\n ```\n</code></pre>\n  </li>\n</ul>\n<p>after</p>",
            ],
            'inside-tab-authored-div' => [
                "* a\n    \t:::\n\n    \t```\t\n",
                "<ul>\n  <li>a\n    <div>\n      <pre><code></code></pre>\n    </div>\n  </li>\n</ul>",
            ],
            'review-fuzz-0' => [
                "> - [ ] t\n>   \t````\n>   \t\t```make\n>    ```\n> \t\t```\n> after\n",
                "<blockquote>\n  <ul class=\"task-list\">\n    <li><input type=\"checkbox\" disabled aria-label=\"t ```make ``` ``` after\"> t\n<code>\n```make\n```\n```\nafter</code></li>\n  </ul>\n</blockquote>",
            ],
            'review-fuzz-1' => [
                "> - a\n>   > q\n> \t ```\t\n>   \t\t``` \n> \t\t```\n>  : d\n>       ::: |\n>  \t: d\n> after\n",
                "<blockquote>\n  <ul>\n    <li>a\n      <blockquote><p>q\n<code>\n</code>\n<code>\n: d\n::: |\n: d\nafter</code></p></blockquote>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'review-fuzz-2' => [
                "> - [ ] t\n>   ``` \n> \t\t```\t\n>     \t```\n> after\n",
                "<blockquote>\n  <ul class=\"task-list\">\n    <li><input type=\"checkbox\" disabled aria-label=\"t after\"> t\n<code>\n</code>\n<code>\nafter</code></li>\n  </ul>\n</blockquote>",
            ],
            'review-fuzz-3' => [
                "- a\n  - b\n\t```\n\t  ~~~~\n\t\t<b>r</b>\n\t\t```\n  \t1. n\n      ```\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n<code>\n~~~~\n&lt;b&gt;r&lt;/b&gt;\n</code>\n        <ol>\n          <li>n\n<code></code></li>\n        </ol>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'review-fuzz-4' => [
                "- a\n  > q\n    \t1. n\n   ```make\n    \t```=html\n  \t```\n    : d\n  \t````\n      ~~~~\nafter\n",
                "<ul>\n  <li>a\n    <blockquote><p>q\n1. n\n<code>make\n</code>=html\n<code>\n: d\n````\n~~~~\nafter</code></p></blockquote>\n  </li>\n</ul>",
            ],
            'review-fuzz-5' => [
                "1. a\n \t: d\n  \t\t~~~~\n\t\t```\n  \t\t```\n \t<b>r</b>\nafter\n",
                "<ol>\n  <li>a\n: d\n~~~~\n    <pre><code></code></pre>\n    &lt;b&gt;r&lt;/b&gt;\nafter\n  </li>\n</ol>",
            ],
        ];
    }

    #[DataProvider('reviewedPreservationControls')]
    public function testExistingFenceOwnershipIsPreserved(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);
        $this->assertSame(preg_replace('/>\s+</', '><', trim($expected)), preg_replace('/>\s+</', '><', trim($html)));
    }

    public function testDedentedFootnoteFollowerRemainsVisible(): void
    {
        $html = (new CarveConverter())->convert("- a\n  [^n]: f\n      ```\n  x\nafter\n");
        $this->assertStringContainsString("\n    x\n", $html);
    }

    public function testUnadmittedFenceKeepsItsPayloadColumns(): void
    {
        $source = "- a\n  \t```\n  \tcode\n  ```\t\nafter\n";
        $doc = (new CarveConverter())->parse($source);
        $code = $doc->getChildren()[0]->getChildren()[0]->getChildren()[1];
        $this->assertInstanceOf(CodeBlock::class, $code);
        $this->assertSame("code\n", $code->getContent());
    }

    public function testFencePayloadTabsRemainLiteral(): void
    {
        $source = "- a\n  ```make\n  all:\n  \tgcc x\n  \t\n  ```\nafter\n";
        $doc = (new CarveConverter())->parse($source);
        $code = $doc->getChildren()[0]->getChildren()[0]->getChildren()[1];
        $this->assertInstanceOf(CodeBlock::class, $code);
        $this->assertSame("all:\n\tgcc x\n\t\n", $code->getContent());
        $rawDoc = (new CarveConverter())->parse(str_replace('```make', '```=html', $source));
        $raw = $rawDoc->getChildren()[0]->getChildren()[0]->getChildren()[1];
        $this->assertInstanceOf(RawBlock::class, $raw);
        $this->assertSame("all:\n\tgcc x\n\t\n", $raw->getContent());
    }

    public function testRemainingTabsKeepTheirPhysicalColumns(): void
    {
        $this->assertSame("\tcode", IndentationHelper::stripLeadingColumns("\tcode", -1));
        $this->assertSame('  ```', IndentationHelper::rebaseLeadingColumns("  \t```", 2));
        $this->assertSame('      ```', IndentationHelper::rebaseLeadingColumns("\t\t```", 2));
        $this->assertSame("  code\tpayload", IndentationHelper::rebaseLeadingColumns("  \tcode\tpayload", 2));
        $this->assertSame("\tcode", IndentationHelper::stripLeadingColumns("\t\tcode", 4));
    }
}
