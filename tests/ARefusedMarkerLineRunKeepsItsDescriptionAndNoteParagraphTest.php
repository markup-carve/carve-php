<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ARefusedMarkerLineRunKeepsItsDescriptionAndNoteParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'description marker line' => [
            ":: t\n: {% a\n %}\nz\n",
            <<<'HTML'
            <dl>
              <dt>t</dt>
              <dd>{% a
            %}
            z</dd>
            </dl>
            HTML,
        ];

        yield 'tab before description marker' => [
            ":: t\n\t: {% a\n    %}\nz\n",
            <<<'HTML'
            <dl>
              <dt>t</dt>
              <dd>{% a
            %}
            z</dd>
            </dl>
            HTML,
        ];

        yield 'item description keeps a note-shaped follower' => [
            "- :: t\n  : {% a\n%}\n[^m]: n\n",
            <<<'HTML'
            <ul>
              <li>
                <dl>
                  <dt>t</dt>
                  <dd>{% a
            %}
            [^m]: n</dd>
                </dl>
              </li>
            </ul>
            HTML,
        ];

        yield 'quoted description keeps a note-shaped follower' => [
            "> :: t\n> : {% a\n>  %}\n> [^m]: n\n",
            <<<'HTML'
            <blockquote>
              <dl>
                <dt>t</dt>
                <dd>{% a
            %}
            [^m]: n</dd>
              </dl>
            </blockquote>
            HTML,
        ];

        yield 'quoted description keeps a list-shaped follower' => [
            "> :: t\n> : {% a\n>  %}\n> - next\n",
            <<<'HTML'
            <blockquote>
              <dl>
                <dt>t</dt>
                <dd>{% a
            %}
            - next</dd>
              </dl>
            </blockquote>
            HTML,
        ];

        yield 'note marker line in an item' => [
            "- [^q]: {.x\n}\n[^m]: n\n  z\n\nx[^q]\n",
            <<<'HTML'
            <ul>
              <li></li>
            </ul>
            <p>x<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
            <section role="doc-endnotes" aria-label="Footnotes">
              <hr>
              <ol>
                <li id="fn1">
                  <p>{.x
            }
            [^m]: n
            z<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                </li>
              </ol>
            </section>
            HTML,
        ];

        yield 'literal note marker run in an ordered item' => [
            "1. [^q]: {#tag\n}\n[^m]: n\n  z\n\nx[^q]\n",
            <<<'HTML'
            <ol>
              <li></li>
            </ol>
            <p>x<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
            <section role="doc-endnotes" aria-label="Footnotes">
              <hr>
              <ol>
                <li id="fn1">
                  <p>{#tag
            }
            [^m]: n
            z<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                </li>
              </ol>
            </section>
            HTML,
        ];

        yield 'two lazy lines remain in the note' => [
            "- [^q]: {.x\n}\n[^m]: n\n  z\n  w\n\nx[^q]\n",
            <<<'HTML'
            <ul>
              <li></li>
            </ul>
            <p>x<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
            <section role="doc-endnotes" aria-label="Footnotes">
              <hr>
              <ol>
                <li id="fn1">
                  <p>{.x
            }
            [^m]: n
            z
            w<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
                </li>
              </ol>
            </section>
            HTML,
        ];
    }

    #[DataProvider('cases')]
    public function testRefusedRunKeepsItsParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
