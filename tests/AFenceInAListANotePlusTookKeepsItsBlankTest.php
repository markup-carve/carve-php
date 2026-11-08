<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only a fence the `+` attached ends at its first blank; one inside an attached list or quote keeps the blank as payload (PART 0 S1, carve#5539).
 */
final class AFenceInAListANotePlusTookKeepsItsBlankTest extends TestCase
{
    public function testReproduction(): void
    {
        $expected = <<<'HTML'
<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <ul>
        <li>li
          <pre><code class="language-x">code

</code></pre>
        </li>
      </ul>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert("[^a]: +\n- li\n  ``` x\n  code\n\nsee[^a]\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function payloads(): array
    {
        return [
            'ordered list' => ["[^a]: +\n1. li\n   ``` x\n   code\n\nsee[^a]\n", "code\n\n</code>"],
            'nested list' => ["[^a]: +\n- li\n  - in\n    ``` x\n    code\n\nsee[^a]\n", "code\n\n</code>"],
            'tilde fence' => ["[^a]: +\n- li\n  ~~~\n  code\n\nsee[^a]\n", "code\n\n</code>"],
            'attached fence ends at the blank' => ["[^a]: +\n``` x\ncode\n\nsee[^a]\n", "code\n</code>"],
            'fence in an attached colon body ends at the blank' => ["[^a]: +\n::: d\n``` x\ncode\n\nsee[^a]\n", "code\n</code>"],
        ];
    }

    #[DataProvider('payloads')]
    public function testPayload(string $source, string $needle): void
    {
        self::assertStringContainsString($needle, (new CarveConverter())->convert($source));
    }
}
