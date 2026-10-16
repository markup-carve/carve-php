<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ANoteOnANotesMarkerLineHostsItsBodyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'nested definition' => [
            "[r][] [^p] [^c] [^d]\n[^c]: [^d]: para\n       [^p]: n\n        tail\n",
            '<p>[r][] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> <a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a> <a id="fnref3" href="#fn3" role="doc-noteref"><sup>3</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes"><hr><ol>
<li id="fn1"><p>n<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li>
<li id="fn2"><p><a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p></li>
<li id="fn3"><p>para</p><p>tail<a href="#fnref3" role="doc-backlink" aria-label="Back to reference">↩</a></p></li>
</ol></section>',
        ];

        yield 'nested table' => [
            "[r][] [^p] [^c] [^d]\n[^c]: [^d]: para\n       | a |\n",
            '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> <a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes"><hr><ol>
<li id="fn1"><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li>
<li id="fn2"><p>para</p><table><tbody><tr><td>a</td></tr></tbody></table><p><a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p></li>
</ol></section>',
        ];

        yield 'single note with definition' => [
            "[r][] [^p] [^c]\n[^c]: para\n       [^p]: n\n        tail\n",
            '<p>[r][] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> <a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes"><hr><ol>
<li id="fn1"><p>n<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li>
<li id="fn2"><p>para</p><p>tail<a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p></li>
</ol></section>',
        ];

        yield 'single note with table' => [
            "[r][] [^p] [^c]\n[^c]: para\n       | a |\n",
            '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes"><hr><ol>
<li id="fn1"><p>para</p><table><tbody><tr><td>a</td></tr></tbody></table><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li>
</ol></section>',
        ];
    }

    #[DataProvider('cases')]
    public function testBodyOwnership(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);
        self::assertSame(preg_replace('/\s+/', '', $expected), preg_replace('/\s+/', '', $html));
    }
}
