<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ADescriptionBodyHostLineTest extends TestCase
{
    #[DataProvider('cases')]
    public function testDescriptionBodyHostLine(string $source, string $expected): void
    {
        $actual = (new CarveConverter())->convert($source);
        self::assertSame(preg_replace('/\s+/', '', $expected), preg_replace('/\s+/', '', $actual));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'c010' => [':: t' . "\n" . ': para' . "\n" . ' - li' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . '- li</dd>' . "\n" . '</dl>'],
            'c011' => [':: t' . "\n" . ': para' . "\n" . '  more' . "\n" . ' [r]: /u' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . 'more' . "\n" . '[r]: /u</dd>' . "\n" . '</dl>'],
            'c012' => [':: t' . "\n" . ': para' . "\n" . '  more' . "\n" . ' [^p]: n' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . 'more' . "\n" . '[^p]: n</dd>' . "\n" . '</dl>'],
            'c013' => [':: t' . "\n" . ': para' . "\n" . '  more' . "\n" . ' {#x}' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . 'more' . "\n" . '{<span class="tag"><strong>#x</strong></span>}</dd>' . "\n" . '</dl>'],
            'c020' => ['[r][] [^p] [^c]' . "\n" . ':: t' . "\n" . ': [^c]: para' . "\n" . '        [^p]: n' . "\n" . '', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd></dd>' . "\n" . '</dl>' . "\n" . '<section role="doc-endnotes" aria-label="Footnotes">' . "\n" . '<hr>' . "\n" . '<ol>' . "\n" . '<li id="fn1">' . "\n" . '<p>para' . "\n" . '[^p]: n<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>' . "\n" . '</li>' . "\n" . '</ol>' . "\n" . '</section>'],
            'c022' => [':: t' . "\n" . ': - para' . "\n" . '```' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<ul>' . "\n" . '<li>para' . "\n" . '<code></code></li>' . "\n" . '</ul>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c025' => [':: t' . "\n" . ': 1. para' . "\n" . '    ---' . "\n" . 'tail' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<ol>' . "\n" . '<li>para' . "\n" . '—' . "\n" . 'tail</li>' . "\n" . '</ol>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c040' => [':: t' . "\n" . ': :: t' . "\n" . ' [^p]: n' . "\n" . 'tail' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<dl>' . "\n" . '<dt>t' . "\n" . '[^p]: n' . "\n" . 'tail</dt>' . "\n" . '</dl>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c042' => [':: t' . "\n" . ': ::: note' . "\n" . '      # H' . "\n" . 'tail' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<aside class="admonition note" aria-label="Note">' . "\n" . '<h1 id="H">H</h1>' . "\n" . '</aside>' . "\n" . '</dd>' . "\n" . '</dl>' . "\n" . '<p>tail</p>'],
            'c043' => [':: t' . "\n" . ': ::: note' . "\n" . '   # H' . "\n" . 'x' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<aside class="admonition note" aria-label="Note">' . "\n" . '<h1 id="H">H</h1>' . "\n" . '</aside>' . "\n" . '</dd>' . "\n" . '</dl>' . "\n" . '<p>x</p>'],
            'c044' => [':: t' . "\n" . ': ::: note' . "\n" . '  more' . "\n" . ' {#x}' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<aside class="admonition note" aria-label="Note">' . "\n" . '<p>more' . "\n" . '{<span class="tag"><strong>#x</strong></span>}</p>' . "\n" . '</aside>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c045' => [':: t' . "\n" . ': :: t' . "\n" . '        {#x}' . "\n" . 'tail' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<dl>' . "\n" . '<dt>t' . "\n" . '{<span class="tag"><strong>#x</strong></span>}' . "\n" . 'tail</dt>' . "\n" . '</dl>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c049' => [':: t' . "\n" . ': > para' . "\n" . ' - li' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<blockquote><p>para' . "\n" . '- li</p></blockquote>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c054' => [':: t' . "\n" . ': :: t' . "\n" . '        {#x}' . "\n" . 'x' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<dl>' . "\n" . '<dt>t' . "\n" . '{<span class="tag"><strong>#x</strong></span>}' . "\n" . 'x</dt>' . "\n" . '</dl>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c059' => [':: t' . "\n" . ': 1. para' . "\n" . '```' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<ol>' . "\n" . '<li>para' . "\n" . '<code></code></li>' . "\n" . '</ol>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c060' => [':: t' . "\n" . ': ::: note' . "\n" . '  para' . "\n" . ' - li' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<aside class="admonition note" aria-label="Note">' . "\n" . '<p>para' . "\n" . '- li</p>' . "\n" . '</aside>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c064' => [':: t' . "\n" . ': ::: note' . "\n" . '   ---' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<aside class="admonition note" aria-label="Note">' . "\n" . '<hr>' . "\n" . '</aside>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c065' => [':: t' . "\n" . ': > para' . "\n" . ' [r]: /u' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<blockquote><p>para' . "\n" . '[r]: /u</p></blockquote>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c069' => [':: t' . "\n" . ': ::: note' . "\n" . '      ---' . "\n" . 'tail' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<aside class="admonition note" aria-label="Note">' . "\n" . '<hr>' . "\n" . '</aside>' . "\n" . '</dd>' . "\n" . '</dl>' . "\n" . '<p>tail</p>'],
            'c082' => [':: t' . "\n" . ': > para' . "\n" . '    # H' . "\n" . 'tail' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<blockquote><p>para' . "\n" . '# H' . "\n" . 'tail</p></blockquote>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c084' => [':: t' . "\n" . ': :: t' . "\n" . '  : para' . "\n" . ' [r]: /u' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . '[r]: /u</dd>' . "\n" . '</dl>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c094' => [':: t' . "\n" . ': :: t' . "\n" . ' - li' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '</dl>' . "\n" . '<ul>' . "\n" . '<li>li</li>' . "\n" . '</ul>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c099' => ['[r][] [^p] [^c]' . "\n" . ':: t' . "\n" . ': [^c]: para' . "\n" . '    [^p]: n' . "\n" . '    tail' . "\n" . '', '<p>[r][] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> <a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a></p>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd></dd>' . "\n" . '</dl>' . "\n" . '<section role="doc-endnotes" aria-label="Footnotes">' . "\n" . '<hr>' . "\n" . '<ol>' . "\n" . '<li id="fn1">' . "\n" . '<p>n<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>' . "\n" . '</li>' . "\n" . '<li id="fn2">' . "\n" . '<p>para</p>' . "\n" . '<p>tail<a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p>' . "\n" . '</li>' . "\n" . '</ol>' . "\n" . '</section>'],
            'c101' => [':: t' . "\n" . ': > para' . "\n" . '   # H' . "\n" . 'tail' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<blockquote><p>para' . "\n" . '# H' . "\n" . 'tail</p></blockquote>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c104' => [':: t' . "\n" . ': :: t' . "\n" . '    {#x}' . "\n" . 'tail' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<dl>' . "\n" . '<dt>t' . "\n" . '{<span class="tag"><strong>#x</strong></span>}' . "\n" . 'tail</dt>' . "\n" . '</dl>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c111' => [':: t' . "\n" . ': 1. para' . "\n" . ' - li' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<ol>' . "\n" . '<li>para' . "\n" . '- li</li>' . "\n" . '</ol>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c118' => [':: t' . "\n" . ': > para' . "\n" . '     # H' . "\n" . 'x' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<blockquote><p>para' . "\n" . '# H' . "\n" . 'x</p></blockquote>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c119' => [':: t' . "\n" . ': > para' . "\n" . ' {#x}' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<blockquote><p>para' . "\n" . '{<span class="tag"><strong>#x</strong></span>}</p></blockquote>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c122' => [':: t' . "\n" . ': :: t' . "\n" . '  : para' . "\n" . ' {#x}' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . '{<span class="tag"><strong>#x</strong></span>}</dd>' . "\n" . '</dl>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c132' => [':: t' . "\n" . ': - para' . "\n" . ' - li' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<ul>' . "\n" . '<li>para' . "\n" . '- li</li>' . "\n" . '</ul>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c144' => [':: t' . "\n" . ': > para' . "\n" . '        | a |' . "\n" . 'x' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<blockquote><p>para' . "\n" . '| a |' . "\n" . 'x</p></blockquote>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c152' => ['[r][] [^p] [^c]' . "\n" . ':: t' . "\n" . ': [^c]: para' . "\n" . '     [^p]: n' . "\n" . '', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd></dd>' . "\n" . '</dl>' . "\n" . '<section role="doc-endnotes" aria-label="Footnotes">' . "\n" . '<hr>' . "\n" . '<ol>' . "\n" . '<li id="fn1">' . "\n" . '<p>para' . "\n" . '[^p]: n<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>' . "\n" . '</li>' . "\n" . '</ol>' . "\n" . '</section>'],
            'c156' => [':: t' . "\n" . ': > para' . "\n" . '     | a |' . "\n" . 'x' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<blockquote><p>para' . "\n" . '| a |' . "\n" . 'x</p></blockquote>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c163' => [':: t' . "\n" . ': 1. para' . "\n" . '    # H' . "\n" . 'tail' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<ol>' . "\n" . '<li>para' . "\n" . '# H' . "\n" . 'tail</li>' . "\n" . '</ol>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c165' => [':: t' . "\n" . ': 1. para' . "\n" . ' {#x}' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<ol>' . "\n" . '<li>para' . "\n" . '{<span class="tag"><strong>#x</strong></span>}</li>' . "\n" . '</ol>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c173' => ['[r][] [^p] [^c]' . "\n" . ':: t' . "\n" . ': ::: note' . "\n" . '  more' . "\n" . ' [r]: /u' . "\n" . '', '<p>[r][] [^p] [^c]</p>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<aside class="admonition note" aria-label="Note">' . "\n" . '<p>more' . "\n" . '[r]: /u</p>' . "\n" . '</aside>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c181' => [':: t' . "\n" . ': ::: note' . "\n" . '      | a |' . "\n" . 'x' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<aside class="admonition note" aria-label="Note">' . "\n" . '<table>' . "\n" . '<tbody>' . "\n" . '<tr><td>a</td></tr>' . "\n" . '</tbody>' . "\n" . '</table>' . "\n" . '</aside>' . "\n" . '</dd>' . "\n" . '</dl>' . "\n" . '<p>x</p>'],
            'c184' => [':: t' . "\n" . ': ::: note' . "\n" . '  para' . "\n" . ' {#x}' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<aside class="admonition note" aria-label="Note">' . "\n" . '<p>para' . "\n" . '{<span class="tag"><strong>#x</strong></span>}</p>' . "\n" . '</aside>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c186' => [':: t' . "\n" . ': 1. para' . "\n" . '    ---' . "\n" . 'x' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<ol>' . "\n" . '<li>para' . "\n" . '—' . "\n" . 'x</li>' . "\n" . '</ol>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c190' => [':: t' . "\n" . ': [^c]: para' . "\n" . '   - li' . "\n" . '```' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<ul>' . "\n" . '<li>li' . "\n" . '<code></code></li>' . "\n" . '</ul>' . "\n" . '</dd>' . "\n" . '</dl>'],
            'c027' => ['1. :: t' . "\n" . '   : para' . "\n" . '      - li' . "\n" . '', '<ol>' . "\n" . '<li>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . '- li</dd>' . "\n" . '</dl>' . "\n" . '</li>' . "\n" . '</ol>'],
            'c030' => [':: t' . "\n" . ': [^c]: para' . "\n" . '   [^p]: n' . "\n" . '    tail' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>tail</dd>' . "\n" . '</dl>'],
            'c071' => ['1. :: t' . "\n" . '   : para' . "\n" . '    - li' . "\n" . '', '<ol>' . "\n" . '<li>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . '- li</dd>' . "\n" . '</dl>' . "\n" . '</li>' . "\n" . '</ol>'],
            'c077' => [':: t' . "\n" . ': [^c]: para' . "\n" . '   {#x}' . "\n" . 'tail' . "\n" . '', '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd></dd>' . "\n" . '</dl>' . "\n" . '<p>tail</p>'],
            'c088' => ['- :: t' . "\n" . '  : para' . "\n" . '   - li' . "\n" . '', '<ul>' . "\n" . '<li>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . '- li</dd>' . "\n" . '</dl>' . "\n" . '</li>' . "\n" . '</ul>'],
            'c095' => ['1. :: t' . "\n" . '   : para' . "\n" . '%% c' . "\n" . '```' . "\n" . '', '<ol>' . "\n" . '<li>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<p>para</p>' . "\n" . '<p><code></code></p>' . "\n" . '</dd>' . "\n" . '</dl>' . "\n" . '</li>' . "\n" . '</ol>'],
            'c106' => ['1. :: t' . "\n" . '   : para' . "\n" . '         - li' . "\n" . '', '<ol>' . "\n" . '<li>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . '- li</dd>' . "\n" . '</dl>' . "\n" . '</li>' . "\n" . '</ol>'],
            'c128' => ['- :: t' . "\n" . '  : para' . "\n" . ' %% c' . "\n" . '```' . "\n" . '', '<ul>' . "\n" . '<li>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>' . "\n" . '<p>para</p>' . "\n" . '<p><code></code></p>' . "\n" . '</dd>' . "\n" . '</dl>' . "\n" . '</li>' . "\n" . '</ul>'],
            'c142' => ['- :: t' . "\n" . '  : para' . "\n" . '        - li' . "\n" . '', '<ul>' . "\n" . '<li>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . '- li</dd>' . "\n" . '</dl>' . "\n" . '</li>' . "\n" . '</ul>'],
            'c169' => ['1. :: t' . "\n" . '   : para' . "\n" . '     - li' . "\n" . '', '<ol>' . "\n" . '<li>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . '- li</dd>' . "\n" . '</dl>' . "\n" . '</li>' . "\n" . '</ol>'],
            'c170' => ['- :: t' . "\n" . '  : para' . "\n" . '     - li' . "\n" . '', '<ul>' . "\n" . '<li>' . "\n" . '<dl>' . "\n" . '<dt>t</dt>' . "\n" . '<dd>para' . "\n" . '- li</dd>' . "\n" . '</dl>' . "\n" . '</li>' . "\n" . '</ul>'],
            'sib2' => [':: t' . "\n" . ':  - a' . "\n" . '  - n' . "\n" . 'tail', '<dl><dt>t</dt><dd><ul><li>a</li><li>n tail</li></ul></dd></dl>'],
            'sib1' => [':: t' . "\n" . ':  - a' . "\n" . ' - n' . "\n" . 'tail', '<dl><dt>t</dt><dd><ul><li>a - n tail</li></ul></dd></dl>'],
            'control-at-column-marker' => [':: t' . "\n" . ': para' . "\n" . '  - li' . "\n" . '', '<dl><dt>t</dt><dd>para' . "\n" . '- li</dd></dl>'],
            'control-at-column-definition' => [':: t' . "\n" . ': para' . "\n" . '  [r]: /u' . "\n" . '', '<dl><dt>t</dt><dd>para</dd></dl>'],
            'definition-below-marker-line-note' => [":: t\n: [^g]: b\n   [r]: /url\n\nSee [r][] and [^g].\n", '<dl><dt>t</dt><dd></dd></dl><p>See <a href="/url">r</a> and <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>.</p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>b<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>'],
            'control-blank-list' => [':: t' . "\n" . ': para' . "\n" . '' . "\n" . '  - li' . "\n" . '', '<dl><dt>t</dt><dd><p>para</p><ul><li>li</li></ul></dd></dl>'],
        ];
    }
}
