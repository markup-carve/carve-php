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
