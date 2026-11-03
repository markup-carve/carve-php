<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A note definition a `+` skips is invisible however many lines its body runs, so a list
 * after it is the attached block (CARVE-P9-067, markup-carve/carve#5034). An own-line `+`
 * right above a sibling note attaches nothing.
 */
class APlusSkippingALongNoteAttachesItsListTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'the carve#5034 sweep shape' => [
                "[^a]: +\n[^n]: a\n +\npara\n- i\n\nsee[^n] [^a]\n",
                <<<'HTML'
                <p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> <a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a></p>
                <section role="doc-endnotes" aria-label="Footnotes">
                  <hr>
                  <ol>
                    <li id="fn1">
                      <p>a
                +
                para<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                    <li id="fn2">
                      <ul>
                        <li>i</li>
                      </ul>
                      <p><a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                  </ol>
                </section>
                HTML,
            ],
            'a three-line lazy body' => [
                "[^a]: +\n[^n]: a\nb\nc\n- i\n\nsee[^n] [^a]\n",
                <<<'HTML'
                <p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> <a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a></p>
                <section role="doc-endnotes" aria-label="Footnotes">
                  <hr>
                  <ol>
                    <li id="fn1">
                      <p>a
                b
                c<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                    <li id="fn2">
                      <ul>
                        <li>i</li>
                      </ul>
                      <p><a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                  </ol>
                </section>
                HTML,
            ],
            'an ordered list' => [
                "[^a]: +\n[^n]: a\n b\n c\n1. i\n\nsee[^n] [^a]\n",
                <<<'HTML'
                <p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> <a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a></p>
                <section role="doc-endnotes" aria-label="Footnotes">
                  <hr>
                  <ol>
                    <li id="fn1">
                      <p>a
                b
                c<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                    <li id="fn2">
                      <ol>
                        <li>i</li>
                      </ol>
                      <p><a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                  </ol>
                </section>
                HTML,
            ],
            'an own-line plus above a sibling note' => [
                "[^a]: x\n+\n[^n]: a\nb\nc\n- i\n\nsee[^n] [^a]\n",
                <<<'HTML'
                <ul>
                  <li>i</li>
                </ul>
                <p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> <a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a></p>
                <section role="doc-endnotes" aria-label="Footnotes">
                  <hr>
                  <ol>
                    <li id="fn1">
                      <p>a
                b
                c<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                    <li id="fn2">
                      <p>x<a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                  </ol>
                </section>
                HTML,
            ],
            'an own-line plus above a sibling note and a quote' => [
                "[^a]: x\n+\n[^n]: a\n> q\n\nsee[^n] [^a]\n",
                <<<'HTML'
                <blockquote><p>q</p></blockquote>
                <p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> <a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a></p>
                <section role="doc-endnotes" aria-label="Footnotes">
                  <hr>
                  <ol>
                    <li id="fn1">
                      <p>a<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                    <li id="fn2">
                      <p>x<a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                  </ol>
                </section>
                HTML,
            ],
            'an own-line plus above a comment and a note' => [
                "[^a]: x\n+\n%% c\n[^n]: a\nb\nc\n> q\n\nsee[^n] [^a]\n",
                <<<'HTML'
                <p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> <a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a></p>
                <section role="doc-endnotes" aria-label="Footnotes">
                  <hr>
                  <ol>
                    <li id="fn1">
                      <p>a
                b
                c<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                    <li id="fn2">
                      <p>x</p>
                      <blockquote><p>q</p></blockquote>
                      <p><a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                  </ol>
                </section>
                HTML,
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testRendersAsTheRuledReading(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }
}
