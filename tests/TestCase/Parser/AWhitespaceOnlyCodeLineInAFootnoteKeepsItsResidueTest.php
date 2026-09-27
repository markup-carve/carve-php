<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A code line of spaces inside a footnote body keeps what lies past the body's
 * content column, exactly as one inside a list item does since
 * `markup-carve/carve-php#2541`. CARVE-P11-016 states it for every container,
 * so the footnote collector is not an exception (markup-carve/carve#2420).
 */
class AWhitespaceOnlyCodeLineInAFootnoteKeepsItsResidueTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'past the body column' => [
                "x[^1]\n\n[^1]: note\n\n    ```\n    a\n      \n    b\n    ```\n",
                "a\n  \nb",
            ],
            'no wider than the body column' => [
                "x[^1]\n\n[^1]: note\n\n    ```\n    a\n    \n    b\n    ```\n",
                "a\n\nb",
            ],
            'past a body written past its own column' => [
                "x[^1]\n\n[^1]: note\n\n  ```\n  a\n    \n  b\n  ```\n",
                "a\n  \nb",
            ],
            'a content line in the same body keeps the same columns' => [
                "x[^1]\n\n[^1]: note\n\n    ```\n    a\n      c\n    b\n    ```\n",
                "a\n  c\nb",
            ],
            'in a block quote, the control' => [
                "> ```\n> a\n>   \n> b\n> ```\n",
                "a\n  \nb",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testTheCodeLineKeepsItsResidue(string $source, string $content): void
    {
        $this->assertSame([$content], $this->codeContents((new CarveConverter())->parse($source)));
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
