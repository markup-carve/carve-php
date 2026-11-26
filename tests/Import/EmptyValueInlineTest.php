<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6859: a code, math or raw inline with an empty value needs content, so the import drops it (C2-M-069).
 */
final class EmptyValueInlineTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: array<string>}>
     */
    public static function shapes(): array
    {
        $repaired = 'import-structure-repaired';
        $comment = '<span class="comment-start" data-comment-id="1">n</span><code></code>'
            . '<span class="comment-end" data-comment-id="1"></span>y';

        return [
            'empty code' => ['<p>a<code></code>b</p>', "ab\n", []],
            'empty code with a class' => ['<p>a<code class="x"></code>b</p>', "ab\n", [$repaired]],
            'empty code with an id' => ['<p>a<code id="u"></code>b</p>', "ab\n", [$repaired]],
            'empty code in a ranged comment' => ["<p>$comment</p>", "{#n#}y\n", []],
            'empty code alone in an emphasis' => ['<p>a<em><code></code></em>b</p>', "ab\n", []],
            'empty code beside text in a strong' => ['<p>a<strong>c<code></code></strong>b</p>', "a{*c*}b\n", []],
            'empty code in a heading' => ['<h2>a<code></code>b</h2>', "== ab\n", []],
            'empty code in a list item' => ['<ul><li>a<code></code>b</li></ul>', "- ab\n", []],
            'empty code alone in a paragraph' => ['<p><code></code></p><p>b</p>', "b\n", []],
            'empty math span' => ['<p>a<span class="math inline">\\(\\)</span>b</p>', "ab\n", []],
            'empty display math span' => ['<p>a<span class="math display">\\[\\]</span>b</p>', "ab\n", []],
            'MathML with an empty TeX source' => [
                '<p>a<math><semantics><mi>x</mi><annotation encoding="application/x-tex"></annotation></semantics></math>b</p>',
                "ab\n",
                [],
            ],
            'MathML with an empty TeX source in a ranged comment' => [
                '<p><span class="comment-start" data-comment-id="1">n</span><math><semantics><mi>x</mi>'
                    . '<annotation encoding="application/x-tex"></annotation></semantics></math>'
                    . '<span class="comment-end" data-comment-id="1"></span>y</p>',
                "{#n#}y\n",
                [],
            ],
            'code with a space is kept' => ['<p>a<code> </code>b</p>', "a` `b\n", []],
        ];
    }

    /**
     * @param string $html
     * @param string $source
     * @param array<string> $codes
     */
    #[DataProvider('shapes')]
    public function testEmptyValueInlineIsDropped(string $html, string $source, array $codes): void
    {
        $result = Carve::importHtml($html, ['rawHtml' => false]);

        $this->assertSame($source, $result->source);
        $this->assertSame($codes, $result->codes());
    }

    public function testEmptyRawSpanIsDroppedWithRawHtmlOn(): void
    {
        $result = Carve::importHtml('<p>a<span data-carve-raw="html"></span>b</p>', ['rawHtml' => true]);

        $this->assertSame("ab\n", $result->source);
        $this->assertSame([], $result->codes());
    }
}
