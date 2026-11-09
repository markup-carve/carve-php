<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ForwardReferenceInContainerTitleTest extends TestCase
{
    public function testFootnoteReferenceInEmptyAdmonitionTitleResolves(): void
    {
        $source = "::: note \"[^1]\"\n:::\n\n[^1]: y\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<p class="admonition-title" id="adm-1"><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>', $html);
        self::assertStringContainsString('<section role="doc-endnotes" aria-label="Footnotes">', $html);
    }

    public function testFootnoteReferenceInAdmonitionTitleResolvesAfterDefinition(): void
    {
        $source = "::: note \"[^1]\"\nx\n:::\n\n[^1]: y\n";
        $expected = <<<'HTML'
<aside class="admonition note" aria-labelledby="adm-1">
  <p class="admonition-title" id="adm-1"><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
  <p>x</p>
</aside>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p>y<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
