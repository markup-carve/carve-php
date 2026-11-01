<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContainerCodeColonBoundaryIsUnspellableTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hosts(): iterable
    {
        $code = "x <code>a\n:::\nb</code> y";

        yield 'list item' => ["<ul><li><p>$code</p></li></ul>"];
        yield 'block quote' => ["<blockquote><p>$code</p></blockquote>"];
        yield 'nested list' => ["<ul><li><ul><li><p>$code</p></li></ul></li></ul>"];
        yield 'four colons' => ["<blockquote><p>x <code>a\n::::\nb</code> y</p></blockquote>"];
    }

    #[DataProvider('hosts')]
    public function testWriterRefusesColonBoundaryInContainerParagraph(string $html): void
    {
        $this->expectException(SourceUnspellableException::class);
        (new HtmlToCarve())->convert($html);
    }

    public function testColonTextOnSameLineRemainsSpellable(): void
    {
        $html = '<ul><li><p>x <code>a:::b</code> y</p></li></ul>';

        self::assertStringContainsString('`a:::b`', (new HtmlToCarve())->convert($html));
    }
}
