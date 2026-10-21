<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ARuledContainerBoundariesRemainStableTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield '3638 note item' => [". d\n   [^n]: e\n     - a\n", "<ol>\n<li>d</li>\n</ol>"];
        yield '3653 refused marker after list' => ["- outer\n  - first\n  - second\n+\n  paragraph\n", "<ul>\n<li>outer\n<ul>\n<li>first</li>\n<li>second\n+\nparagraph</li>\n</ul>\n</li>\n</ul>"];
        yield '3653 refused marker in quote' => ["> quote\n  ```\n+\n  ```\nnext\n", "<blockquote><p>quote\n<code>\n+\n</code>\nnext</p></blockquote>"];
        yield '3653 marker in fence' => ["- outer\n  - inner\n  ```\n  +\n  ```\nnext\n", "<ul>\n<li>outer\n<ul>\n<li>inner</li>\n</ul>\n<pre><code>+\n</code></pre>\n</li>\n</ul>\n<p>next</p>"];
        yield '3653 quoted marker is fence payload' => ["- outer\n  - inner\n  ```\n  > +\n  ```\nnext\n", "<ul>\n<li>outer\n<ul>\n<li>inner</li>\n</ul>\n<pre><code>&gt; +\n</code></pre>\n</li>\n</ul>\n<p>next</p>"];
        yield '3653 nested quoted marker is fence payload' => ["- outer\n  - inner\n  ```\n  > > +\n  ```\nnext\n", "<ul>\n<li>outer\n<ul>\n<li>inner</li>\n</ul>\n<pre><code>&gt; &gt; +\n</code></pre>\n</li>\n</ul>\n<p>next</p>"];
        yield '3653 host-column payload keeps looking for a closer' => ["- outer\n  - inner\n   ```\n  +\n  ```\nnext\n", "<ul>\n<li>outer\n<ul>\n<li>inner</li>\n</ul>\n<pre><code>+\n</code></pre>\n</li>\n</ul>\n<p>next</p>"];
        yield '3653 intermediate fence stays text before nested reparse' => ["- outer\n  - inner\n ```\n  +\n  ```\nnext\n", "<ul>\n<li>outer\n<ul>\n<li>inner\n<code></code>\n<pre><code>next\n</code></pre>\n</li>\n</ul>\n</li>\n</ul>"];
        yield '625 outside closer leaves blank to split child paragraphs' => ["- outer\n  - head\n     ~~~\n     a\n ~~~\n\n    tail\n", "<ul>\n<li>outer\n<ul>\n<li><p>head\n~~~\na\n~~~</p>\n<p>tail</p>\n</li>\n</ul>\n</li>\n</ul>"];
        yield '625 outside backtick closer preserves outer blank' => ["- outer\n  - head\n     ```\n     a\n ```\n\n  tail\n", "<ul>\n<li><p>outer</p>\n<ul>\n<li>head\n<code>\na\n</code></li>\n</ul>\n<p>tail</p>\n</li>\n</ul>"];
        yield '3654 quoted marker' => [":: term\n: body\n  > +\nnext\n", "<dl>\n<dt>term</dt>\n<dd>\n<p>body</p>\n<blockquote><p>next</p></blockquote>\n</dd>\n</dl>"];
    }

    #[DataProvider('examples')]
    public function testRenderedHtml(string $source, string $expected): void
    {
        $html = trim(CarveConverter::create()->convert($source));
        $lines = [];
        $inPre = false;
        foreach (explode("\n", $html) as $line) {
            if ($inPre) {
                $lines[] = $line;
            } elseif (trim($line) !== '') {
                $lines[] = trim($line);
            }
            if (preg_match('/<pre[ >]/', $line) === 1) {
                $inPre = true;
            }
            if (str_contains($line, '</pre>')) {
                $inPre = false;
            }
        }

        $this->assertSame($expected, implode("\n", $lines));
    }
}
