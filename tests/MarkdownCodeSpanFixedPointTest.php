<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownCodeSpanFixedPointTest extends TestCase
{
    public function testCodeSpanSpellings(): void
    {
        $rows = [
            'cm-138' => ["``` ```\naaa\n", "` `\naaa\n", "<p><code> </code>\naaa</p>\n"],
            'cm-145' => ["``` aa ```\nfoo\n", "`aa`\nfoo\n", "<p><code>aa</code>\nfoo</p>\n"],
            'cm-329' => ["`` foo ` bar ``\n", "``foo ` bar``\n", "<p><code>foo ` bar</code></p>\n"],
            'cm-330' => ["` `` `\n", "``` `` ```\n", "<p><code>``</code></p>\n"],
            'cm-331' => ["`  ``  `\n", "```  ``  ```\n", "<p><code> `` </code></p>\n"],
            'cm-340' => ["` foo `` bar `\n", "```foo `` bar```\n", "<p><code>foo `` bar</code></p>\n"],
            'cm-349' => ["`foo``bar``\n", "\\`foo`bar`\n", "<p>`foo<code>bar</code></p>\n"],
        ];

        foreach ($rows as $id => [$source, $expected, $html]) {
            $actual = (new MarkdownToCarve())->convert($source);
            self::assertSame($expected, $actual, $id);
            self::assertSame($actual, CarveConverter::toCarve($actual), $id);
            self::assertSame(preg_replace('/\n\z/', '', $html), (new CarveConverter())->convert($actual), $id);
        }
    }
}
