<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence is measured against its own item's content column, so one in a
 * nested item stays code instead of being escaped into a paragraph (#2099).
 */
class AFenceInANestedMarkdownItemIsCodeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a fence in a nested item' => [
                "- a\n\n  - b\n\n    ```\n    code\n    ```",
                "{loose}\n- a\n\n  {loose}\n  - b\n\n    ```\n    code\n    ```",
            ],
            'a fence in a doubly nested item' => [
                "- a\n\n  - b\n\n    - c\n\n      ```\n      code\n      ```",
                "{loose}\n- a\n\n  {loose}\n  - b\n\n    {loose}\n    - c\n\n      ```\n      code\n      ```",
            ],
            'a fence on a nested item line' => [
                "1. a\n   - ```\n     code\n     ```",
                "1. a\n   - ```\n     code\n     ```",
            ],
            'an unclosed fence in a nested item' => [
                "- a\n\n  - b\n\n    ```\n    code",
                "{loose}\n- a\n\n  {loose}\n  - b\n\n    ```\n    code\n    ```",
            ],
            'a tilde fence in a nested item' => [
                "- a\n\n  - b\n\n    ~~~\n    code\n    ~~~",
                "{loose}\n- a\n\n  {loose}\n  - b\n\n    ```\n    code\n    ```",
            ],
            'an ordered parent with a bullet child' => [
                "1. a\n\n   - b\n\n     ```\n     code\n     ```",
                "{loose}\n1. a\n\n   {loose}\n   - b\n\n     ```\n     code\n     ```",
            ],
            'a nested item fence with an info string' => [
                "- a\n\n  - b\n\n    ```php\n    echo 1;\n    ```",
                "{loose}\n- a\n\n  {loose}\n  - b\n\n    ```php\n    echo 1;\n    ```",
            ],
            'a nested item fence info with a raw-block equals sign' => [
                "- a\n\n  - b\n\n    ```=html\n    <b>\n    ```",
                "{loose}\n- a\n\n  {loose}\n  - b\n\n    ```html\n    <b>\n    ```",
            ],
            'a nested item fence indented three columns past its item' => [
                "- a\n\n  - b\n\n       ```\n       code\n       ```",
                "{loose}\n- a\n\n  {loose}\n  - b\n\n    ```\n    code\n    ```",
            ],
            'a nested item fence ended by a dedented line' => [
                "- a\n\n  - b\n\n    ```\n    code\n\n  more",
                "- a\n\n  {loose}\n  - b\n\n    ```\n    code\n\n    ```\n\n  more",
            ],
            'a body line indented no further than its item' => [
                "- b\n\n     ```\n  code\n     ```",
                "{loose}\n- b\n\n  ```\n  code\n  ```",
            ],
            'a plus-marker nested item line' => [
                "- a\n  + ```\n    code\n    ```",
                "- a\n  - ```\n    code\n    ```",
            ],
            'a tab-indented fence in an item' => [
                "- a\n\n\t```\n\tcode\n\t```",
                "{loose}\n- a\n\n  ```\n  code\n  ```",
            ],
        ];
    }

    /**
     * Indentation reaching four columns past the item's content column opens an
     * INDENTED code block, whose content is the fence text itself. A leading tab
     * carries four columns, so it reaches that at the top level.
     *
     * @return array<string, array{string, string}>
     */
    public static function columnEdges(): array
    {
        return [
            'four columns past a nested item column' => [
                "- a\n\n  - b\n\n        ```\n        code\n        ```",
                "{loose}\n- a\n\n  {loose}\n  - b\n\n    ````\n    ```\n    code\n    ```\n    ````",
            ],
            'a tab-indented fence at the top level' => [
                "\t```\n\tcode\n\t```",
                "````\n```\ncode\n```\n````",
            ],
        ];
    }

    /**
     * A backtick in a backtick fence's info string makes the line paragraph
     * text, at the top level as in a nested item.
     *
     * @return array<string, array{string, string}>
     */
    public static function backtickInfoStrings(): array
    {
        return [
            'at the top level' => ["```foo`bar\nx", "\\`\\`\\`foo\\`bar\nx"],
            'in a nested item' => [
                "- a\n\n  - b\n\n    ```foo`bar\n    x",
                "{loose}\n- a\n\n  - b\n\n    \\`\\`\\`foo\\`bar\n    x",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheNestedFenceIsCode(string $markdown, string $carve): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }

    #[DataProvider('shapes')]
    public function testTheImportedNestedFenceRendersAsACodeBlock(string $markdown, string $carve): void
    {
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));

        $this->assertStringContainsString('<pre><code', $html);
        $this->assertStringNotContainsString('```', strip_tags($html));
    }

    #[DataProvider('columnEdges')]
    public function testAFenceBeyondTheColumnIsCodeText(string $markdown, string $carve): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
        $this->assertStringContainsString('<code>```', (new CarveConverter())->convert($carve));
    }

    #[DataProvider('backtickInfoStrings')]
    public function testABacktickInfoStringOpensNoFence(string $markdown, string $carve): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }
}
