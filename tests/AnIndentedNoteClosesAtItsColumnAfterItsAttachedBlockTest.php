<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * After an indented note's attached block, a line below its body column leaves the note (carve-php#4942).
 */
final class AnIndentedNoteClosesAtItsColumnAfterItsAttachedBlockTest extends TestCase
{
    public function testReproduction(): void
    {
        $expected = <<<'HTML'
<p>para</p>
<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <blockquote><p>aq</p></blockquote>
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert("  [^a]: +\n  > aq\n\n  para\n\nsee[^a]\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'closed fence attached' => ["  [^a]: +\n  ``` x\n  c\n  ```\n\n  para\n\nsee[^a]\n", '<p>para</p>'],
            'fence after the quote' => ["  [^a]: +\n  > aq\n\n  ```\n  code\n  ```\n\nsee[^a]\n", "<pre><code>code\n</code></pre>\n<p>see"],
            'three columns' => ["   [^a]: +\n   > aq\n\n   para\n\nsee[^a]\n", '<p>para</p>'],
        ];
    }

    #[DataProvider('shapes')]
    public function testLineLeavesTheNote(string $source, string $needle): void
    {
        self::assertStringContainsString($needle, (new CarveConverter())->convert($source));
    }

    public function testLineAtTheBodyColumnResumesTheNote(): void
    {
        $html = (new CarveConverter())->convert("  [^a]: +\n  > aq\n\n    para\n\nsee[^a]\n");

        self::assertStringContainsString("<blockquote><p>aq</p></blockquote>\n      <p>para<a", $html);
    }
}
