<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A code line of spaces inside a list item keeps what lies past the item's
 * content column (PART 11 section 7, PART 9 section 24 C5), as it does inside a
 * block quote. The HTML import writes that line, so the import is a fmt fixed
 * point only when the parse keeps it.
 */
class AWhitespaceOnlyCodeLineInAnItemKeepsItsResidueTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'on the marker line' => ["- ```\n  a\n    \n  b\n  ```\n", "a\n  \nb"],
            'after a blank line' => ["- x\n\n  ```\n  a\n     \n  b\n  ```\n", "a\n   \nb"],
            'no wider than the content column' => ["- ```\n  a\n  \n  b\n  ```\n", "a\n\nb"],
            'in a block quote, the control' => ["> ```\n> a\n>   \n> b\n> ```\n", "a\n  \nb"],
        ];
    }

    #[DataProvider('documents')]
    public function testTheCodeLineKeepsItsResidue(string $source, string $content): void
    {
        $this->assertSame([$content], $this->codeContents((new CarveConverter())->parse($source)));
    }

    public function testTheImportIsAFmtFixedPoint(): void
    {
        $source = (new HtmlToCarve())->convert("<ul><li><pre>a\n \nb</pre></li></ul>");
        $document = (new CarveConverter())->parse($source);

        $this->assertSame(["a\n \nb"], $this->codeContents($document));
        $this->assertSame($source, (new CarveRenderer())->render($document));
    }

    /**
     * @return list<string>
     */
    private function codeContents(Node $node): array
    {
        $out = $node instanceof CodeBlock ? [$node->getContent()] : [];
        foreach ($node->getChildren() as $child) {
            array_push($out, ...$this->codeContents($child));
        }

        return $out;
    }
}
