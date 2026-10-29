<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpecParityTest extends TestCase
{
    use RuleATodo;

    #[DataProvider('cases')]
    public function testSpecParity(string $source, string $html): void
    {
        self::assertSame(trim($html), trim((new CarveConverter())->convert($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/fixtures/spec-parity/*.json') ?: [] as $path) {
            $rows = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach ($rows as $row) {
                yield $row['id'] => [$row['source'], self::currentHtml($row['id'], $row['html'])];
            }
        }
    }

    private static function currentHtml(string $id, string $pinned): string
    {
        $note = <<<'HTML'
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p>intro
+
more<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;
        $reference = '<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>';
        $root = $reference . "\n" . $note;

        return match ($id) {
            'comment-boundary-retention/435-the-continuation-marker-s-column-gate-reaches-every-container-2',
            'comment-boundary-retention/435-the-continuation-marker-s-column-gate-reaches-every-container-4',
            'comment-controls/column-gate/0/marker',
            'comment-controls/column-gate/1/marker' => $root,
            'comment-controls/column-gate/6/marker' => '<blockquote>' . $reference . "</blockquote>\n" . $note,
            'comment-controls/column-gate/7/marker' => "<aside class=\"admonition note\" aria-label=\"Note\">\n\n</aside>\n" . $root,
            default => $pinned,
        };
    }
}
