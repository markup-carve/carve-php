<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownEmphasisFidelityTest extends TestCase
{
    public function testInlineMeaning(): void
    {
        $cases = [
            ['a*"foo"*', '<p>a*"foo"*</p>'],
            ['&quot;quoted&quot; and &#39;text&#39;', '<p>"quoted" and \'text\'</p>'],
            ['"hello" and \'goodbye\'', '<p>"hello" and \'goodbye\'</p>'],
            ['don\'t change "quotes"', '<p>don\'t change "quotes"</p>'],
            ['**foo "*bar*" foo**', '<p><strong>foo "<em>bar</em>" foo</strong></p>'],
            ['*foo **bar *baz* bim** bop*', '<p><em>foo <strong>bar <em>baz</em> bim</strong> bop</em></p>'],
            ['***outer _inner_ end***', '<p><em><strong>outer <em>inner</em> end</strong></em></p>'],
            ['foo __*__', '<p>foo <strong>*</strong></p>'],
            ['`"code"` and ["label"](/url "title")', '<p><code>"code"</code> and <a href="/url" title="title">"label"</a></p>'],
            ['<span title="quoted">"text"</span>', '<p><span title="quoted">"text"</span></p>'],
            ["*start\n    # heading\nend*", "<p><em>start\n# heading\nend</em></p>"],
            ["*start\n    ---\nend*", "<p><em>start\n---\nend</em></p>"],
            ["*foo\nbar*", "<p><em>foo\nbar</em></p>"],
            ["**foo *bar **baz**\nbim* bop**", "<p><strong>foo <em>bar <strong>baz</strong>\nbim</em> bop</strong></p>"],
            ["*foo  \nbar*", "<p><em>foo<br>\nbar</em></p>"],
            ["*foo\n\nbar*", "<p>*foo</p>\n<p>bar*</p>"],
            ["**foo\n# bar**", "<p>**foo</p>\n<section id=\"bar\">\n  <h1>bar**</h1>\n</section>"],
            ["*foo\n```\nbar*\n```", "<p>*foo</p>\n<pre><code>bar*\n</code></pre>"],
        ];
        foreach ($cases as [$source, $expected]) {
            $written = (new MarkdownToCarve())->convert($source);
            $this->assertSame($expected, rtrim((new CarveConverter())->convert($written), "\n"), $source);
        }
    }
}
