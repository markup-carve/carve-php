<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnInvisibleBlockLeavesNoLazyLineTest extends TestCase
{
    #[DataProvider('cases')]
    public function testContainerExtent(string $source, string $expected): void
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
            'n:desc/note-2992' => [":: t\n: ::: note\n  more\n[^p]: n\nx\n", '<dl><dt>t</dt><dd><aside class="admonition note" aria-label="Note"><p>more</p></aside></dd></dl><p>x</p>'],
            'n:ordered/quote-3064' => ["1. > para\n         [r]: /u\nx\n", '<ol><li><blockquote><p>para</p></blockquote></li></ol><p>x</p>'],
            'n:item/quote-3072' => ["- > para\n        [r]: /u\ntail\n", '<ul><li><blockquote><p>para</p></blockquote></li></ul><p>tail</p>'],
            'n:item/note-3091' => ["- ::: note\n   [r]: /u\ntail\n", '<ul><li><aside class="admonition note" aria-label="Note"></aside></li></ul><p>tail</p>'],
            'n:ordered/quote-3464' => ["1. > para\n     [^p]: n\nx\n", '<ol><li><blockquote><p>para</p></blockquote></li></ol><p>x</p>'],
            'n:item/note-3752' => ["- ::: note\n  {#x}\ntail\n", '<ul><li><aside class="admonition note" aria-label="Note"></aside></li></ul><p>tail</p>'],
            'n:quote/note-3967' => ["> ::: note\n>  %% c\ntail\n", '<blockquote><aside class="admonition note" aria-label="Note"></aside></blockquote><p>tail</p>'],
            'n:ordered/note-4285' => ["1. ::: note\n       [r]: /u\nx\n", '<ol><li><aside class="admonition note" aria-label="Note"></aside></li></ol><p>x</p>'],
            'n:ordered/note-4406' => ["1. ::: note\n   [^p]: n\nx\n", '<ol><li><aside class="admonition note" aria-label="Note"></aside></li></ol><p>x</p>'],
            'n:desc/note-4460' => [":: t\n: ::: note\n  [^p]: n\nx\n", '<dl><dt>t</dt><dd><aside class="admonition note" aria-label="Note"></aside></dd></dl><p>x</p>'],
            'n:ordered/note-4464' => ["1. ::: note\n    [^p]: n\ntail\n", '<ol><li><aside class="admonition note" aria-label="Note"></aside></li></ol><p>tail</p>'],
            'n:desc/note-4702' => [":: t\n: ::: note\n      [^p]: n\ntail\n", '<dl><dt>t</dt><dd><aside class="admonition note" aria-label="Note"></aside></dd></dl><p>tail</p>'],
            'n:ordered/quote-4947' => ["1. > para\n      [^p]: n\nx\n", '<ol><li><blockquote><p>para</p></blockquote></li></ol><p>x</p>'],
            'n:ordered/quote-5094' => ["1. > para\n     [r]: /u\ntail\n", '<ol><li><blockquote><p>para</p></blockquote></li></ol><p>tail</p>'],
            'n:ordered/note-5348' => ["1. ::: note\n       [r]: /u\ntail\n", '<ol><li><aside class="admonition note" aria-label="Note"></aside></li></ol><p>tail</p>'],
            'n:ordered/quote-5422' => ["1. > para\n     [^p]: n\ntail\n", '<ol><li><blockquote><p>para</p></blockquote></li></ol><p>tail</p>'],
            'n:ordered/note-5437' => ["1. ::: note\n    [r]: /u\ntail\n", '<ol><li><aside class="admonition note" aria-label="Note"></aside></li></ol><p>tail</p>'],
            'n:quote/item-5445' => ["> - para\n>    [^p]: n\nx\n", '<blockquote><ul><li>para</li></ul></blockquote><p>x</p>'],
            'n:item/note-5500' => ["- ::: note\n      [r]: /u\nx\n", '<ul><li><aside class="admonition note" aria-label="Note"></aside></li></ul><p>x</p>'],
            'n:item/note-5684' => ["- ::: note\n  [^p]: n\nx\n", '<ul><li><aside class="admonition note" aria-label="Note"></aside></li></ul><p>x</p>'],
            'n:item/quote-5691' => ["- > para\n    [^p]: n\ntail\n", '<ul><li><blockquote><p>para</p></blockquote></li></ul><p>tail</p>'],
            'n:item/note-5760' => ["- ::: note\n  [^p]: n\ntail\n", '<ul><li><aside class="admonition note" aria-label="Note"></aside></li></ul><p>tail</p>'],
            'n:ordered/note-5805' => ["1. ::: note\n   [r]: /u\ntail\n", '<ol><li><aside class="admonition note" aria-label="Note"></aside></li></ol><p>tail</p>'],
            'n:item/note-5832' => ["- ::: note\n  [r]: /u\nx\n", '<ul><li><aside class="admonition note" aria-label="Note"></aside></li></ul><p>x</p>'],
            'n:quote/note-5993' => ["> ::: note\n> [r]: /u\nx\n", '<blockquote><aside class="admonition note" aria-label="Note"></aside></blockquote><p>x</p>'],
            'famI:c000' => ["- para\n   {#x}\ntail\n", '<ul><li>para</li></ul><p>tail</p>'],
            'famI:c001' => ["- para\n   {#x}\n```\n", '<ul><li>para</li></ul><pre><code></code></pre>'],
            'famI:c002' => ["- para\n      {#x}\ntail\n", '<ul><li>para</li></ul><p>tail</p>'],
            'famI:c003' => ["- para\n      {#x}\n```\n", '<ul><li>para</li></ul><pre><code></code></pre>'],
            'famI:c004' => ["1. para\n    {#x}\ntail\n", '<ol><li>para</li></ol><p>tail</p>'],
            'famI:c005' => ["1. para\n    {#x}\n```\n", '<ol><li>para</li></ol><pre><code></code></pre>'],
            'famI:c006' => ["1. para\n       {#x}\ntail\n", '<ol><li>para</li></ol><p>tail</p>'],
            'famI:c007' => ["1. para\n       {#x}\n```\n", '<ol><li>para</li></ol><pre><code></code></pre>'],
            'famI:c023' => ["- 1. para\n   {#x}\nx\n", '<ul><li><ol><li>para</li></ol></li></ul><p>x</p>'],
            'famI:c028' => ["- - para\n   {#x}\ntail\n", '<ul><li><ul><li>para</li></ul></li></ul><p>tail</p>'],
            'famI:c072' => ["1. 1. para\n     {#x}\ntail\n", '<ol><li><ol><li>para</li></ol></li></ol><p>tail</p>'],
            'famI:c143' => ["- 1. para\n    {#x}\nx\n", '<ul><li><ol><li>para</li></ol></li></ul><p>x</p>'],
            'famI:c146' => ["- [^c]: para\n   {#x}\ntail\n", '<ul><li></li></ul><p>tail</p>'],
            'famI:c192' => ["1. - para\n    {#x}\ntail\n", '<ol><li><ul><li>para</li></ul></li></ol><p>tail</p>'],
            'famI:c154' => ["1. :: t\n   : para\n     {#x}\ntail\n", '<ol><li><dl><dt>t</dt><dd>para</dd></dl></li></ol><p>tail</p>'],
            'famI:c189' => ["1. :: t\n   : para\n      {#x}\ntail\n", '<ol><li><dl><dt>t</dt><dd>para</dd></dl></li></ol><p>tail</p>'],
            'famI:c177' => ["- :: t\n  : para\n   {#x}\ntail\n", '<ul><li><dl><dt>t</dt><dd>para</dd></dl></li></ul><p>tail</p>'],
            'famI:c033' => ["- [^c]: para\n        [^p]: n\n    tail\n", '<ul><li>tail</li></ul>'],
            'famI:c047' => ["- > para\n     [^p]: n\n    tail\n", '<ul><li><blockquote><p>para</p></blockquote>tail</li></ul>'],
            'famI:c080' => ["- > para\n        [^p]: n\n    tail\n", '<ul><li><blockquote><p>para</p></blockquote>tail</li></ul>'],
            'famI:c114' => ["1. [^c]: para\n         [^p]: n\n     tail\n", '<ol><li>tail</li></ol>'],
            'open-div-paragraph' => ["- ::: note\n  para\ntail\n", '<ul><li><aside class="admonition note" aria-label="Note"><p>para tail</p></aside></li></ul>'],
            'open-quote-paragraph' => ["1. > para\ntail\n", '<ol><li><blockquote><p>para tail</p></blockquote></li></ol>'],
        ];
    }
}
