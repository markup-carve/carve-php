<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The empty block is an ordinary member of a bracketed span's attribute run and
 * adds no attribute (CARVE-P9-021, ruling on markup-carve/carve#4077). After an
 * emphasis or a link it still ends the run.
 */
class AnEmptyBlockIsAMemberOfASpanAttributeRunTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'two empties' => ['[a]{}{}', '<p><span>a</span></p>'],
            'empty then class' => ['[a]{}{}{.b}', '<p><span class="b">a</span></p>'],
            'class then empty' => ['[a]{.b}{}', '<p><span class="b">a</span></p>'],
            'spaced empty between' => ['[a]{.b}{ }{#i}', '<p><span class="b" id="i">a</span></p>'],
            'text after' => ['[a]{.b}{}x', '<p><span class="b">a</span>x</p>'],
            'tab block ends the run' => ["[a]{.b}{\t}", "<p><span class=\"b\">a</span>{\t}</p>"],
            'emphasis keeps it literal' => ['*x*{.b}{}', '<p><strong class="b">x</strong>{}</p>'],
            'link keeps it literal' => ['[a](u){.b}{}', '<p><a href="u" class="b">a</a>{}</p>'],
        ];
    }

    #[DataProvider('cases')]
    public function testRun(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source . "\n")));
    }
}
