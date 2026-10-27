<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\PinnedOracleHtml;
use PHPUnit\Framework\TestCase;

class AClosedQuotedItemDivLeavesNoClaimTest extends TestCase
{
    public function testAnUnmarkedListAfterTheCloserBelongsToTheRoot(): void
    {
        foreach ([1, 2] as $depth) {
            foreach ([3, 4] as $width) {
                foreach (['', 'body'] as $body) {
                    foreach ([0, 2] as $indent) {
                        $quote = str_repeat('> ', $depth);
                        $fence = str_repeat(':', $width);
                        $source = $quote . '- ' . $fence . " box\n"
                            . ($body === '' ? '' : $quote . '  ' . $body . "\n")
                            . $quote . '  ' . $fence . "\n"
                            . str_repeat(' ', $indent) . "- tail\n";
                        $html = (new CarveConverter())->convert($source);
                        $this->assertStringEndsWith(preg_replace('/\n\z/', '', "</blockquote>\n<ul>\n  <li>tail</li>\n</ul>\n"), $html, $source);
                        $this->assertSame($depth, substr_count($html, '<blockquote>'));
                    }
                }
            }
        }
    }

    public function testAnOpenParagraphStillKeepsTheMarkerAsText(): void
    {
        $html = (new CarveConverter())->convert("> - paragraph\n- tail\n");
        $this->assertStringContainsString("<li>paragraph\n- tail</li>", $html);
        $this->assertSame(1, substr_count($html, '<ul>'));
    }

    public function testExplicitQuoteAndAttachmentMarkersKeepTheListQuoted(): void
    {
        foreach (["> - tail\n", "+\n- tail\n"] as $tail) {
            $html = (new CarveConverter())->convert("> - ::: box\n>   :::\n" . $tail);
            $this->assertStringEndsWith(preg_replace('/\n\z/', '', "</blockquote>\n"), $html);
            $this->assertStringContainsString('tail', $html);
        }
    }

    public function testOpaqueCodeAndNestedDivsKeepTheOuterCloser(): void
    {
        foreach (
            [
                "> - ::: box\n>   ```\n>   :::\n>   ```\n>   :::\ntail\n",
                "> - ::: box\n>\n>   body\n>   :::\ntail\n",
                "> - ::: box\n>   body\nlazy\n>   :::\ntail\n",
                "> - a\n>   ::: box\n>   :::\ntail\n",
                "> - ::: box\n>\n>   :::: inner\n>   :::: \n>   :::\ntail\n",
                "> - ::: box\n>   %%%\n>   :::\n>   %%%\n>   :::\ntail\n",
                "> - ::: box\n>   body\n>   %%% x\n>   :::\n>   %%%\n>   :::\ntail\n",
                "> - ::: box\n>   %%%\n>   %%%%% a\n>   :::\n>   %%%%% b\n>   :::\ntail\n",
                "> - ::: box\n>   - :::: inner\n>     :::: \n>   :::\ntail\n",
            ] as $source
        ) {
            $html = (new CarveConverter())->convert($source);
            $this->assertStringEndsWith(preg_replace('/\n\z/', '', "</blockquote>\n<p>tail</p>\n"), $html);
        }
    }

    public function testAClaimEndsWhenItsItemOrLazyOpenerEnds(): void
    {
        foreach (
            [
                "> - ::: box\n> - b\n>   :::\ntail\n",
                "> - ::: box\ntail\n>   :::\nmore\n",
                "> > - ::: box\n> tail\n> >   :::\nmore\n",
            ] as $source
        ) {
            $html = (new CarveConverter())->convert($source);
            $this->assertSame(trim(PinnedOracleHtml::forSource($source)), trim($html));
        }
    }

    public function testAnUnclosedCommentDoesNotHideTheColonCloser(): void
    {
        $source = "> - ::: box\n>   body\n>   %%%\n>   :::\n>   after\ntail\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertStringContainsString("after\ntail", $html);
        $this->assertStringEndsWith(preg_replace('/\n\z/', '', "</blockquote>\n"), $html);
    }

    public function testMalformedColonAbsorptionBelongsToOneItem(): void
    {
        $html = (new CarveConverter())->convert("> - :::bad\n>   :::\n- tail\n");
        $this->assertStringContainsString(":::bad\n:::\n- tail", $html);
        $this->assertStringEndsWith(preg_replace('/\n\z/', '', "</blockquote>\n"), $html);
        $html = (new CarveConverter())->convert("> - prose\n>   :::bad\n> - next\n>   :::\n- tail\n");
        $this->assertStringEndsWith(preg_replace('/\n\z/', '', "</blockquote>\n<ul>\n  <li>tail</li>\n</ul>\n"), $html);
    }

    public function testTheCodePayloadAndFollowingBodyStayInsideTheDiv(): void
    {
        $source = "> - ::: box\n>   ```\n>   :::\n>   ```\n>   after\n>   :::\ntail\n";
        $expected = <<<'HTML'
<blockquote>
  <ul>
    <li>
      <div class="box">
        <pre><code>:::
</code></pre>
        <p>after</p>
      </div>
    </li>
  </ul>
</blockquote>
<p>tail</p>
HTML;
        $this->assertSame(preg_replace('/\n\z/', '', $expected . "\n"), (new CarveConverter())->convert($source));
    }

    public function testTheNestedDivRemainsWithinTheOuterDiv(): void
    {
        $source = "> - ::: box\n>   - :::: inner\n>     :::: \n>   :::\ntail\n";
        $expected = <<<'HTML'
<blockquote>
  <ul>
    <li>
      <div class="box">
        <ul>
          <li>
            <div class="inner">

            </div>
          </li>
        </ul>
      </div>
    </li>
  </ul>
</blockquote>
<p>tail</p>
HTML;
        $this->assertSame(preg_replace('/\n\z/', '', $expected . "\n"), (new CarveConverter())->convert($source));
    }
}
