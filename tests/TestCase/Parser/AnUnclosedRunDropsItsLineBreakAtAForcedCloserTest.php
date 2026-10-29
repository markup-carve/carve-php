<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unclosed run ended at a forced or editorial closer drops its trailing
 * line break too, except in a line block, where a line break is content
 * (ruling B on markup-carve/carve#2089, corpus row 12-inline-code-12).
 */
class AnUnclosedRunDropsItsLineBreakAtAForcedCloserTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a code span in a strong' => ["x{*`a\n*}\n", "<p>x{*<code>a\n*}</code></p>\n"],
            'a code span in an insertion' => ["x{+`a\n+}\n", "<p>x{+<code>a\n+}</code></p>\n"],
            'math' => ["x{*\$`a\n*}\n", "<p>x{*<span class=\"math inline\" role=\"math\">\\(a\n*}\\)</span></p>\n"],
            'an inline literal' => ["x{~!`b \n~}\n", "<p>x{~b\n~}</p>\n"],
            'the line break is kept in a line block' => [
                "::: |\nx{*`a\n*}\n:::\n",
                "<div class=\"line-block\">\n  <p>x{*<code>a\n*}</code></p>\n</div>\n",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheRunEndsAsTheBlockEndWould(string $source, string $html): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', $html), CarveConverter::create()->convert($source));
    }
}
