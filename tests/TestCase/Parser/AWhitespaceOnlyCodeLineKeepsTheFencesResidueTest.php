<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AWhitespaceOnlyCodeLineKeepsTheFencesResidueTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'A top-level, line of 2' => [
                "```\na\n  \nb\n```\n",
                "a\n  \nb\n",
            ],
            'B item at 2, fence at 2, line 2' => [
                "- item\n\n  ```\n  a\n  \n  b\n  ```\n",
                "a\n\nb\n",
            ],
            'C item at 2, fence at 4, line 4' => [
                "- item\n\n    ```\n    a\n    \n    b\n    ```\n",
                "a\n\nb\n",
            ],
            'D item at 2, fence at 4, line 6' => [
                "- item\n\n    ```\n    a\n      \n    b\n    ```\n",
                "a\n  \nb\n",
            ],
            'E note at 4, fence at 4, line 6' => [
                "x[^1]\n\n[^1]: note\n\n    ```\n    a\n      \n    b\n    ```\n",
                "a\n  \nb\n",
            ],
            'F note at 4, fence at 6, line 6' => [
                "x[^1]\n\n[^1]: note\n\n      ```\n      a\n      \n      b\n      ```\n",
                "a\n\nb\n",
            ],
            'G note at 4, fence at 6, line 8' => [
                "x[^1]\n\n[^1]: note\n\n      ```\n      a\n        \n      b\n      ```\n",
                "a\n  \nb\n",
            ],
            'H nested item, fence at 4, line 6' => [
                "- - item\n\n    ```\n    a\n      \n    b\n    ```\n",
                "a\n  \nb\n",
            ],
            'I nested item, fence at 6, line 6' => [
                "- - item\n\n      ```\n      a\n      \n      b\n      ```\n",
                "a\n\nb\n",
            ],
            'J nested item, fence at 6, line 8' => [
                "- - item\n\n      ```\n      a\n        \n      b\n      ```\n",
                "a\n  \nb\n",
            ],
            'K defn desc at 3, fence at 5, line 7' => [
                ":: t\n:  d\n\n     ```\n     a\n       \n     b\n     ```\n",
                "a\n  \nb\n",
            ],
            'L quote+item, fence at 4, line 6' => [
                "> - item\n>\n>     ```\n>     a\n>       \n>     b\n>     ```\n",
                "a\n  \nb\n",
            ],
            'M item at 2, fence at 4, line 5' => [
                "- item\n\n    ```\n    a\n     \n    b\n    ```\n",
                "a\n \nb\n",
            ],
            'a deeper fence run does not close the block' => [
                "- item\n\n    ```\n    a\n      ```\n      \n    b\n    ```\n",
                "a\n  ```\n  \nb\n",
            ],
            'tab reaches the opener column' => [
                "- item\n\n\t```\n\ta\n\t\n\tb\n\t```\n",
                "a\n\nb\n",
            ],
            'spaces past a tab-indented opener' => [
                "- item\n\n\t```\n\ta\n\t  \n\tb\n\t```\n",
                "a\n  \nb\n",
            ],
            'tab crosses the opener column' => [
                "- item\n\n   ```\n   a\n\t\n   b\n   ```\n",
                "a\n \nb\n",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testTheCodeLineKeepsItsResidue(string $source, string $content): void
    {
        $this->assertSame([$content], $this->codeContents((new CarveConverter())->parse($source)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function blankOutsideAFence(): array
    {
        return [
            'item, whitespace line past the content column' => ["- a\n    \n  b\n", "- a\n\n  b\n"],
            'three whitespace lines still break the list' => ["- a\n    \n \n\t\n- b\n", "- a\n\n\n\n- b\n"],
            'description, whitespace line past the body column' => [":: t\n:  a\n       \n   b\n", ":: t\n:  a\n\n   b\n"],
        ];
    }

    #[DataProvider('blankOutsideAFence')]
    public function testAWhitespaceOnlyLineOutsideAFenceIsStillABlank(string $source, string $plain): void
    {
        $converter = new CarveConverter();

        $this->assertSame($converter->convert($plain), $converter->convert($source));
    }

    public function testAnUnterminatedFencePastTheContentColumnStaysInline(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a\n<code>\nb\ntail</code></li>\n</ul>",
            trim((new CarveConverter())->convert("- a\n    ```\n    b\ntail\n")),
        );
    }

    /**
     * @return array<int, string>
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
