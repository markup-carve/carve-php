<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A list-shaped line folded into a description or footnote body's paragraph
 * (PART 9 §10 I2) opens no item, so a line past the body's column is read in
 * the body: a definition registers (CARVE-P0-020) and a heading or attribute
 * line opens on its authored base (CARVE-P0-004).
 */
class ALineUnderAFoldedMarkerIsReadInTheBodyTest extends TestCase
{
    use RuleATodo;

    #[DataProvider('provider')]
    public function testTheLineIsReadInTheBody(string $source, string $expected): void
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
        $noteref = '<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>';
        $backlink = '<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a>';
        $notes = static fn (string $body): string => '<section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1">' . $body . '</li></ol></section>';

        return [
            'bullet, definition' => [":: t\n: d\n  - para\n    [r]: /u\nx\n\n[r][]\n", '<dl><dt>t</dt><dd>d - para</dd></dl><p>x</p><p><a href="/u">r</a></p>'],
            'ordered, definition' => [":: t\n: d\n  1. para\n     [r]: /u\nx\n\n[r][]\n", '<dl><dt>t</dt><dd>d 1. para</dd></dl><p>x</p><p><a href="/u">r</a></p>'],
            'bullet, continuation, definition' => [":: t\n: d\n  - para\n    more\n    [r]: /u\nx\n\n[r][]\n", '<dl><dt>t</dt><dd>d - para more</dd></dl><p>x</p><p><a href="/u">r</a></p>'],
            'bullet, definition, line at the column' => [":: t\n: d\n  - para\n    [r]: /u\n  more\n\n[r][]\n", '<dl><dt>t</dt><dd><p>d - para</p><p>more</p></dd></dl><p><a href="/u">r</a></p>'],
            'bullet, heading' => [":: t\n: d\n  - para\n    # H\nx\n", '<dl><dt>t</dt><dd><p>d - para</p><h1 id="H">H</h1></dd></dl><p>x</p>'],
            'bullet, attribute' => [":: t\n: d\n  - para\n    {#k}\nx\n", '<dl><dt>t</dt><dd>d - para</dd></dl><p>x</p>'],
            'task, unclosed fences, definitions' => [":: term\n: para\n  - [ ] ~~~\n        ``` js\n       [r]: /in\n  +\n     [s]: /s\n\n[r][] [s][]\n", '<dl><dt>term</dt><dd><p>para - [ ] ~~~ <code> js</code></p><p>+</p></dd></dl><p><a href="/in">r</a><a href="/s">s</a></p>'],
            'note, bullet, definition' => ["[^n]: d\n  - para\n    [r]: /u\nx\n\n[r][] [^n]\n", '<p>x</p><p><a href="/u">r</a>' . $noteref . '</p>' . $notes('<p>d - para' . $backlink . '</p>')],
            'note, bullet, heading' => ["[^n]: d\n  - para\n    # H\nx\n\n[^n]\n", '<p>x</p><p>' . $noteref . '</p>' . $notes('<p>d - para</p><h1 id="H">H</h1><p>' . $backlink . '</p>')],
            'control: definition without the marker' => [":: t\n: d\n   [r]: /u\nx\n\n[r][]\n", '<dl><dt>t</dt><dd>d</dd></dl><p>x</p><p><a href="/u">r</a></p>'],
            'control: abbreviation stays text' => [":: t\n: d\n  - para\n    *[A]: b\nx\n", '<dl><dt>t</dt><dd>d - para *[A]: b x</dd></dl>'],
            'control: comment stays a comment' => [":: t\n: d\n  - para\n    %% c\nx\n", '<dl><dt>t</dt><dd>d - para</dd></dl><p>x</p>'],
            'control: a quote does not rebase' => ["- > para\n  >  [r]: /u\ntail\n\n[r][]\n", '<ul><li><blockquote><p>para [r]: /u tail</p></blockquote></li></ul><p>[r][]</p>'],
        ];
    }
}
