<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An empty mark has no spelling, and a mark whose content starts or ends in
 * Carve whitespace needs the braced form.
 */
class EmptyAndEdgedMarksTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function markProvider(): array
    {
        return [
            'emphasis' => ['/x/'],
            'strong' => ['*x*'],
            'underline' => ['_x_'],
            'strike' => ['~x~'],
            'highlight' => ['=x='],
            'superscript' => ['{^x^}'],
            'subscript' => ['{,x,}'],
            'insert' => ['{+x+}'],
            'delete' => ['{-x-}'],
        ];
    }

    /**
     * @param string $mark
     * @param list<\MarkupCarve\Carve\Node\Node> $children
     */
    private function withMarkContent(string $mark, array $children): Document
    {
        $document = $this->converter->parse("a {$mark} b\n");
        $paragraph = $document->getChildren()[0];
        foreach ($paragraph->getChildren() as $child) {
            if (!$child instanceof Text) {
                $child->setChildren($children);

                return $document;
            }
        }

        $this->fail("no mark in {$mark}");
    }

    /**
     * `{//}` is literal text and `{--}` the braced en dash (markup-carve/carve#1608).
     */
    #[DataProvider('markProvider')]
    public function testAnEmptyMarkIsRefused(string $mark): void
    {
        $document = $this->withMarkContent($mark, []);

        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($document);
    }

    /**
     * @return array<string, array{string, callable(): list<\MarkupCarve\Carve\Node\Node>}>
     */
    public static function edgeProvider(): array
    {
        $cases = [];
        foreach (self::markProvider() as $name => [$mark]) {
            $cases["{$name}, leading tab"] = [$mark, fn (): array => [new Text("\tx")]];
            $cases["{$name}, trailing tab"] = [$mark, fn (): array => [new Text("x\t")]];
            $cases["{$name}, leading soft break"] = [$mark, fn (): array => [new SoftBreak(), new Text('x')]];
        }

        return $cases;
    }

    /**
     * @param string $mark
     * @param callable(): list<\MarkupCarve\Carve\Node\Node> $children
     */
    #[DataProvider('edgeProvider')]
    public function testAWhitespaceEdgedMarkKeepsItsMeaning(string $mark, callable $children): void
    {
        $document = $this->withMarkContent($mark, $children());
        $source = (new CarveRenderer())->render($document);

        $this->assertSame($this->converter->render($document), $this->converter->convert($source), $source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyElementProvider(): array
    {
        $cases = [];
        foreach (['em', 'i', 'strong', 'b', 's', 'strike', 'u', 'mark', 'sub', 'sup', 'ins', 'del'] as $tag) {
            $cases[$tag] = [$tag];
        }

        return $cases;
    }

    /**
     * Ruling markup-carve/carve-rs#1719: it holds nothing a reader sees.
     */
    #[DataProvider('emptyElementProvider')]
    public function testTheHtmlImporterDropsAnEmptyMarkWithoutARow(string $tag): void
    {
        $result = (new HtmlToCarve())->convertWithReport("<p>a <{$tag}></{$tag}> b</p>");

        $this->assertSame("a  b\n", $result->value);
        $this->assertSame([], $result->diagnostics);
    }

    public function testTheHtmlImporterKeepsAnEmptyMarksAttributesOnASpan(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p>a <em id="t"></em> b</p>');

        $this->assertSame("a []{#t} b\n", $result->value);
        $this->assertStringContainsString('id="t"', $this->converter->convert($result->value));
    }
}
