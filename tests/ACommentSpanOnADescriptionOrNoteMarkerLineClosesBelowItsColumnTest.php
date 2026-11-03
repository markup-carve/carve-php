<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%%` span on a `: ` description or `[^n]: ` note marker line belongs to that body:
 * a closer below its column still closes it, and a blank inside it is payload
 * (PART 9 §28, PART 0 S1, carve-php#4508, markup-carve/carve#4996).
 */
class ACommentSpanOnADescriptionOrNoteMarkerLineClosesBelowItsColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'corpus 940 row 1' => [
                "- a\n  :: u\n  : %%%\n%%%\nc\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <dl>
                      <dt>u</dt>
                      <dd></dd>
                    </dl>
                  </li>
                </ul>
                <p>c</p>
                HTML,
            ],
            'corpus 940 row 2' => [
                "- a\n\n  [^n]: %%%\n%%%\nc\n",
                <<<'HTML'
                <ul>
                  <li>a</li>
                </ul>
                <p>c</p>
                HTML,
            ],
            'corpus 940 row 3' => [
                "- a\n  :: u\n  : x\n    %%%\n%%%\n:: c\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <dl>
                      <dt>u</dt>
                      <dd>x</dd>
                    </dl>
                  </li>
                </ul>
                <dl>
                  <dt>c</dt>
                </dl>
                HTML,
            ],
            'corpus 940 row 4' => [
                ":: u\n: %%%\n    n\n\n%%%\nc\n",
                <<<'HTML'
                <dl>
                  <dt>u</dt>
                  <dd></dd>
                </dl>
                <p>c</p>
                HTML,
            ],
            'corpus 940 row 5' => [
                "x[^n]\n\n[^n]: %%%\n    n\n\n%%%\nc\n",
                <<<'HTML'
                <p>x<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
                <p>c</p>
                <section role="doc-endnotes" aria-label="Footnotes">
                  <hr>
                  <ol>
                    <li id="fn1">
                      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                  </ol>
                </section>
                HTML,
            ],
            'corpus 940 row 6' => [
                "- a\n  - %%%\n    n\n\n%%%\nc\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <ul>
                      <li></li>
                    </ul>
                  </li>
                </ul>
                <p>c</p>
                HTML,
            ],
            'corpus 940 row 7' => [
                "- a\n  :: u\n  : %%%\n\n%%%\n  c\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <dl>
                      <dt>u</dt>
                      <dd></dd>
                    </dl>
                    c
                  </li>
                </ul>
                HTML,
            ],
            'corpus 940 row 8' => [
                "- a\n  :: u\n  : %%%\n    n\n    %%%\n  zz\n\n%%%\nc\n%%%\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <dl>
                      <dt>u</dt>
                      <dd></dd>
                    </dl>
                    zz
                  </li>
                </ul>
                HTML,
            ],
            'corpus 942 row 1' => [
                "- a\n\n  [^n]: %%%\n  %%%\n  c\n",
                <<<'HTML'
                <ul>
                  <li><p>a</p>
                    <p>c</p>
                  </li>
                </ul>
                HTML,
            ],
            'corpus 942 row 2' => [
                "- a\n\n  [^n]: %%%\n%%%\n    c\n",
                <<<'HTML'
                <ul>
                  <li>a</li>
                </ul>
                HTML,
            ],
            'corpus 942 row 3' => [
                "- a\n  - %%%\n\n  %%%\n  c\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <ul>
                      <li></li>
                    </ul>
                    c
                  </li>
                </ul>
                HTML,
            ],
            'corpus 942 row 4' => [
                "- a\n  - b\n    :: u\n    : %%%\n\n  %%%\n  c\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <ul>
                      <li>b
                        <dl>
                          <dt>u</dt>
                          <dd></dd>
                        </dl>
                      </li>
                    </ul>
                    c
                  </li>
                </ul>
                HTML,
            ],
            'carve-php#4508 reproduction' => [
                "- a\n  :: u\n  : %%%\n    n\n    %%%\n  zz\n\n%%%\nc\n%%%\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <dl>
                      <dt>u</dt>
                      <dd></dd>
                    </dl>
                    zz
                  </li>
                </ul>
                HTML,
            ],
            'a shorter run inside a wider description span is payload' => [
                "- a\n  :: u\n  : %%%%\n    %%%\n%%%\nc\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <dl>
                      <dt>u</dt>
                      <dd></dd>
                    </dl>
                  </li>
                </ul>
                <p>c</p>
                HTML,
            ],
            'a shorter run below a top-level description span is payload' => [
                ":: u\n: %%%%\n  n\n%%%\nc\n",
                <<<'HTML'
                <dl>
                  <dt>u</dt>
                  <dd></dd>
                </dl>
                <p>c</p>
                HTML,
            ],
            'a shorter run between the columns after a blank' => [
                "- a\n\n  :: u\n  : %%%%\n %%%\n  c\n",
                <<<'HTML'
                <ul>
                  <li>a
                    <dl>
                      <dt>u</dt>
                      <dd></dd>
                    </dl>
                    c
                  </li>
                </ul>
                HTML,
            ],
            'a blank in a note span with a closer below the column' => [
                "- a\n  x[^n]\n\n  [^n]: %%%\n        n\n\n%%%\n:: c\n",
                <<<'HTML'
                <ul>
                  <li>a
                x<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></li>
                </ul>
                <dl>
                  <dt>c</dt>
                </dl>
                <section role="doc-endnotes" aria-label="Footnotes">
                  <hr>
                  <ol>
                    <li id="fn1">
                      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                    </li>
                  </ol>
                </section>
                HTML,
            ],
            'a blank in a description span before a sibling loosens the list' => [
                "- a\n  :: u\n  : %%%\n\n  %%%\n- c\n",
                <<<'HTML'
                <ul>
                  <li><p>a</p>
                    <dl>
                      <dt>u</dt>
                      <dd></dd>
                    </dl>
                  </li>
                  <li><p>c</p></li>
                </ul>
                HTML,
            ],
            'a line below a note span ends it' => [
                "- a\n\n  [^g]: %%%\n    n\n  zz\n%%%\n\nx[^g]\n",
                <<<'HTML'
                <ul>
                  <li><p>a</p>
                    <p>zz</p>
                  </li>
                </ul>
                HTML,
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }
}
