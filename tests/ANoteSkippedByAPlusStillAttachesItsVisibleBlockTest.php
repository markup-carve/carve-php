<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P9-013: an invisible note and its folded body do not consume the block a taken `+` attaches.
 */
final class ANoteSkippedByAPlusStillAttachesItsVisibleBlockTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'item' => [
                "- a\n+\n[^n]: note\n  continuation\n# H\n\nx[^n]\n",
                "<ul>\n  <li>a\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>\n",
            ],
            'marker-line plus' => [
                "- +\n[^n]: note\n  continuation\n# H\n\nx[^n]\n",
                "<ul>\n  <li>\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>\n",
            ],
            'description' => [
                ":: t\n: d\n+\n[^n]: note\n  continuation\n# H\n\nx[^n]\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <h1 id=\"H\">H</h1>\n  </dd>\n</dl>\n",
            ],
            'quote' => [
                "> q\n+\n[^n]: note\n  continuation\n# H\n\nx[^n]\n",
                "<blockquote>\n  <p>q</p>\n  <h1 id=\"H\">H</h1>\n</blockquote>\n",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testTheFirstVisibleBlockStillAttaches(string $source, string $body): void
    {
        $endnotes = <<<'HTML'
<p>x<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p>note
continuation<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;
        $this->assertSame($body . $endnotes, (new CarveConverter())->convert($source));
    }

    public function testDescriptionShapedLazyTextStaysInTheNoteBeforeTheAttachedBlock(): void
    {
        $source = "- a\n+\n[^n]: note\n: d2\n# H\n\nx[^n]\n";
        $html = (new CarveConverter())->convert($source);

        $this->assertStringContainsString("<li>a\n    <h1 id=\"H\">H</h1>", $html);
        $this->assertStringContainsString("<p>note\n: d2<a href=\"#fnref1\"", $html);
        $this->assertStringNotContainsString("<li>a\n    : d2", $html);
    }
}
