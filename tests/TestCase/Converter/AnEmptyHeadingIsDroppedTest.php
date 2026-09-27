<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#2419: Carve spells no empty heading, so one is dropped on
 * import with a single row that covers the attributes it carried.
 */
class AnEmptyHeadingIsDroppedTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'bare' => ['<h1></h1>', '/h1[1]', 'Dropped <h1> holding no content'],
            'whitespace with id' => [
                '<h2 id="k"> </h2>',
                '/h2[1]',
                'Dropped whitespace-only <h2> holding no content character',
            ],
            'level six' => ['<h6></h6>', '/h6[1]', 'Dropped <h6> holding no content'],
            'attributed' => ['<h3 id="i" data-x="1"></h3>', '/h3[1]', 'Dropped <h3> holding no content'],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheHeadingIsDroppedWithOneRow(string $html, string $path, string $message): void
    {
        $importer = new HtmlToCarve();
        $result = $importer->convertWithReport($html . '<p>a</p>');

        $this->assertSame("a\n", $result->value);
        $this->assertSame([
            [
                'code' => 'element-dropped',
                'message' => $message,
                'severity' => 'warning',
                'fidelity' => 'dropped',
                'confidence' => 'exact',
                'path' => $path,
            ],
        ], $result->report()['diagnostics']);
    }

    /**
     * Both exits have to lose the node, or `parse(htmlToCarve(h))` reads a
     * paragraph where `htmlToAst(h)` published a heading.
     */
    #[DataProvider('shapes')]
    public function testBothExitsLoseTheHeading(string $html, string $path, string $message): void
    {
        $importer = new HtmlToCarve();
        $source = $importer->convertWithReport($html . '<p>a</p>');
        $ast = $importer->convertToAstWithReport($html . '<p>a</p>');

        $document = $ast->value;
        unset($document['srcByteLength']);
        $this->assertSame([
            'type' => 'document',
            'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'a']]]],
        ], $document);
        $this->assertSame($source->report(), $ast->report());
        $this->assertSame($source->value, (new CarveConverter())->toCarve($source->value));
    }

    /**
     * A heading holding an empty inline element is not whitespace-only - it
     * holds no character at all - so it takes the bare message, and the row is
     * still owed: the drop takes the LEVEL with it, which nothing else names.
     */
    public function testAHeadingHoldingAnEmptyInlineElementTakesTheBareMessage(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<h3><em></em></h3><p>a</p>');

        $this->assertSame("a\n", $result->value);
        $rows = $result->report()['diagnostics'];
        $this->assertSame(['element-dropped'], array_column($rows, 'code'));
        $this->assertSame('Dropped <h3> holding no content', $rows[0]['message']);
        $this->assertSame('/h3[1]', $rows[0]['path']);
    }

    public function testBothHeadingsAreDroppedInOrder(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<h1></h1><p>a</p><h2 id="k"> </h2><p>b</p>');

        $this->assertSame("a\n\nb\n", $result->value);
        $rows = $result->report()['diagnostics'];
        $this->assertSame(['element-dropped', 'element-dropped'], array_column($rows, 'code'));
        $this->assertSame(['/h1[1]', '/h2[3]'], array_column($rows, 'path'));
        $this->assertSame([
            'Dropped <h1> holding no content',
            'Dropped whitespace-only <h2> holding no content character',
        ], array_column($rows, 'message'));
    }

    public function testAHeadingWithContentIsUntouched(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<h2 id="k">t</h2>');

        $this->assertSame("{#k}\n## t\n", $result->value);
        $this->assertSame([], $result->report()['diagnostics']);
    }
}
