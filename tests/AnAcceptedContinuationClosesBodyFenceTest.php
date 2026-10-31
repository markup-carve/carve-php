<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P9-067: a marker-column continuation ends the body's open fence
 * before its attached block is read (markup-carve/carve#4237).
 */
final class AnAcceptedContinuationClosesBodyFenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'description code fence' => [
            ":: t\n: ```\n+\n> q\n", <<<'HTML'
<dl>
  <dt>t</dt>
  <dd>
    <pre><code></code></pre>
    <blockquote><p>q</p></blockquote>
  </dd>
</dl>
HTML
,
        ];

        yield 'description colon fence' => [
            ":: t\n: :::\n+\n> q\n", <<<'HTML'
<dl>
  <dt>t</dt>
  <dd>
    <div>

    </div>
    <blockquote><p>q</p></blockquote>
  </dd>
</dl>
HTML
,
        ];

        yield 'footnote code fence' => [
            "[^n]: ```\n+\n> q\n\nr[^n]\n", <<<'HTML'
<p>r<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <pre><code></code></pre>
      <blockquote><p>q</p></blockquote>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML
,
        ];
    }

    #[DataProvider('documents')]
    public function testFenceEndsBeforeAttachedBlock(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testARefusedMarkerDoesNotAttachABlock(): void
    {
        $source = ":: t\n: ```\n+\n";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code></code></pre>\n  </dd>\n</dl>\n<p>+</p>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
