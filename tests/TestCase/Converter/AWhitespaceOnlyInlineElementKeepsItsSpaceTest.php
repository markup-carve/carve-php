<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An inline element holding nothing but whitespace rendered a space, so the
 * importer writes the pair around one space instead of dropping the element
 * (#2114). An element holding nothing at all is still dropped (#2113).
 */
class AWhitespaceOnlyInlineElementKeepsItsSpaceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a strong between text' => ['<p>a<strong> </strong>b</p>', "a{* *}b\n"],
            'an emphasis between text' => ['<p>a<em> </em>b</p>', "a{/ /}b\n"],
            'a superscript' => ['<p>a<sup> </sup>b</p>', "a{^ ^}b\n"],
            'an insertion' => ['<p>a<ins> </ins>b</p>', "a{+ +}b\n"],
            'a run of spaces is one space' => ['<p>a<strong>  </strong>b</p>', "a{* *}b\n"],
            'a newline is whitespace too' => ["<p>a<em>\n</em>b</p>", "a{/ /}b\n"],
            'alone in its paragraph' => ['<p><strong> </strong></p>', "{* *}\n"],
            'beside spaces of its own' => ['<p>a <strong> </strong> b</p>', "a {* *} b\n"],
            'an empty element is still dropped' => ['<p>a<strong></strong>b</p>', "ab\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheSpaceSurvivesTheImport(string $html, string $carve): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }

    /**
     * The writer spells the same tree the same way, so the import is what
     * `carve fmt` leaves alone.
     */
    #[DataProvider('shapes')]
    public function testTheWriterAgrees(string $html, string $carve): void
    {
        $codec = new AstCodec();
        $document = CarveConverter::create()->parse($carve);

        $this->assertSame($carve, (new CarveRenderer())->render($codec->decode($codec->encode($document))));
    }
}
