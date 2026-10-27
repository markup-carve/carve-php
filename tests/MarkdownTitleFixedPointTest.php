<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownTitleFixedPointTest extends TestCase
{
    public function testTitleSpellings(): void
    {
        $rows = [
            'cm-193' => [
                '   [foo]: 
      /url  
           \'the title\'  

[foo]
', '[foo][]

[foo]: /url "the title"
', '<p><a href="/url" title="the title">foo</a></p>
',
            ],
            'cm-194' => [
                '[Foo*bar\\]]:my_(url) \'title (with parens)\'

[Foo*bar\\]]
', '[Foo*bar\\]](my_(url) "title (with parens)")
', '<p><a href="my_(url)" title="title (with parens)">Foo*bar]</a></p>
',
            ],
            'cm-195' => [
                '[Foo bar]:
<my url>
\'title\'

[Foo bar]
', '[Foo bar][]

[Foo bar]: my%20url "title"
', '<p><a href="my%20url" title="title">Foo bar</a></p>
',
            ],
            'cm-196' => [
                '[foo]: /url \'
title
line1
line2
\'

[foo]
', '[foo](/url "
title
line1
line2
")
', '<p><a href="/url" title="
title
line1
line2
">foo</a></p>
',
            ],
            'cm-505' => [
                '[link](/url "title")
[link](/url \'title\')
[link](/url (title))
', '[link](/url "title")
[link](/url "title")
[link](/url "title")
', '<p><a href="/url" title="title">link</a>
<a href="/url" title="title">link</a>
<a href="/url" title="title">link</a></p>
',
            ],
            'cm-509' => [
                '[link](/url \'title "and" title\')
', '[link](/url "title \\"and\\" title")
', '<p><a href="/url" title="title &quot;and&quot; title">link</a></p>
',
            ],
        ];

        foreach ($rows as $id => [$source, $expected, $html]) {
            $actual = (new MarkdownToCarve())->convert($source);
            self::assertSame($expected, $actual, $id);
            self::assertSame($actual, CarveConverter::toCarve($actual), $id);
            self::assertSame(preg_replace('/\n\z/', '', $html), (new CarveConverter())->convert($actual), $id);
        }
    }
}
