<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AListMarkerEndsATermInABodyTest extends TestCase
{
    #[DataProvider('provider')]
    public function testBodyMarkerBoundary(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);
        $normalized = trim(str_replace('> <', '><', (string)preg_replace('/\s+/', ' ', $html)));

        $this->assertSame($expected, $normalized);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provider(): array
    {
        return [
            'note-term-col0' => [
                '[^n]: :: term
  - child

[^n]
', '<p><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><dl><dt>term</dt></dl><ul><li>child</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
            ],
            'note-term-def' => [
                '[^n]: :: term
  : def
  - child

[^n]
', '<p><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><dl><dt>term</dt><dd>def - child</dd></dl><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
            ],
            'note-para-then-term' => [
                '[^n]: para

  :: term
  - child

[^n]
', '<p><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>para</p><dl><dt>term</dt></dl><ul><li>child</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
            ],
            'note-term-cont' => [
                '[^n]: :: term
  more
  - child

[^n]
', '<p><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><dl><dt>term more</dt></dl><ul><li>child</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
            ],
            'note-para' => [
                '[^n]: para
  - child

[^n]
', '<p><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>para - child<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
            ],
            'dd-term' => [
                ':: t
: :: term
  - child
', '<dl><dt>t</dt><dd><dl><dt>term</dt></dl><ul><li>child</li></ul></dd></dl>',
            ],
            'dd-term2' => [
                ':: t
: d

  :: term
  - child
', '<dl><dt>t</dt><dd><p>d</p><dl><dt>term</dt></dl><ul><li>child</li></ul></dd></dl>',
            ],
            'dd-term-def' => [
                ':: t
: :: term
  : def
  - child
', '<dl><dt>t</dt><dd><dl><dt>term</dt><dd>def</dd></dl><ul><li>child</li></ul></dd></dl>',
            ],
            'dd-para' => [
                ':: t
: d
  - child
', '<dl><dt>t</dt><dd>d - child</dd></dl>',
            ],
            'note-heading' => [
                '[^n]: # H
  - child

[^n]
', '<p><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><h1 id="H">H</h1><ul><li>child</li></ul><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
            ],
            'dd-heading' => [
                ':: t
: # H
  - child
', '<dl><dt>t</dt><dd><h1 id="H">H</h1><ul><li>child</li></ul></dd></dl>',
            ],
            'note-term-ord' => [
                '[^n]: :: term
  1. child

[^n]
', '<p><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><dl><dt>term</dt></dl><ol><li>child</li></ol><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
            ],
        ];
    }
}
