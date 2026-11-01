<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §17 L1b and L3: note visibility and accepted marker identity survive list collection.
 */
final class AListHostedNoteContinuationRetainsItsSourceAndVisibilityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'invisible note leaves a sibling separator' => [
            "- see[^n]\n\n  [^n]: +\n    # h\n- e\n",
            <<<'HTML'
<ul>
  <li><p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p></li>
  <li><p>e</p></li>
</ul>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p>+</p>
      <h1 id="h">h</h1>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML
,
        ];

        yield 'nested accepted first block marker retains its source line' => [
            "- a\n  - see[^n]\n\n    [^n]: +\n    # h\nend\n",
            <<<'HTML'
<ul>
  <li>a
    <ul>
      <li>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></li>
    </ul>
  </li>
</ul>
<p>end</p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <h1 id="h">h</h1>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML
,
        ];
    }

    #[DataProvider('documents')]
    public function testListHostedNoteContinuation(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
