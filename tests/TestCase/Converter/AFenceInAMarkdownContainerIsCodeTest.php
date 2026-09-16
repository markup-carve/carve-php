<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence opened in a block quote or on a list item's first line is code, and
 * CommonMark closes it where its container ends (#2076).
 */
class AFenceInAMarkdownContainerIsCodeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'an unclosed fence in a quote' => ["> ```\n> code", "> ```\n> code\n> ```"],
            'a closed fence in a quote keeps its content' => ["> ```\n> *x*\n> ```", "> ```\n> *x*\n> ```"],
            'a quote ended by a blank line' => ["> ```\n> code\n\nafter", "> ```\n> code\n> ```\n\nafter"],
            'a quote paragraph around the fence' => ["> text\n> ```\n> x\n> ```\n> more", "> text\n>\n> ```\n> x\n> ```\n>\n> more"],
            'a nested quote ended by a lazy line' => ["> > ```\n> > a\n> b", "> > ```\n> > a\n> > ```\n> >\n> b"],
            'a blank code line keeps only the marker' => ["> ```\n>\n>   y\n> ```", "> ```\n>\n>   y\n> ```"],
            'an indented quote fence strips its own indent' => [">  ```\n>   y\n>  ```", "> ```\n>  y\n> ```"],
            'an info string with a raw-block equals sign' => ["> ```=html\n> <b>\n> ```", "> ```html\n> <b>\n> ```"],
            'a tilde fence in a quote' => ["> ~~~\n> *x*\n> ~~~", "> ~~~\n> *x*\n> ~~~"],
            'a backtick info string with a backtick is not a fence' => ['> ``` a`b', '> \\`\\`\\` a\\`b'],
            'a fence on a list item line' => ["- ```\n  *x*\n  ```", "- ```\n  *x*\n  ```"],
            'an item fence info with a raw-block equals sign' => ["- ```=html\n  <b>", "- ```html\n  <b>"],
            'a closer at a wide item column' => ["10. ```\n    code\n    ```\n\nafter", "10. ```\n    code\n    ```\n\nafter"],
            'a tab-indented closer in an item' => ["- ```\n  x\n\t```\nafter", "- ```\n  x\n  ```\n\nafter"],
            'a closer indented four columns is code' => ["```\nx\n    ```\n*y*", "```\nx\n    ```\n*y*"],
            'a tab-indented closer at the top level is code' => ["```\nx\n\t```\n*y*", "```\nx\n\t```\n*y*"],
            'five spaces after a marker open no fence' => ["-     ```\n      x", "-     \\`\\`\\`\n      x"],
            'an ordered item fence with info' => ["1. ```js\n   x", "1. ```js\n   x"],
            'an item fence ended by a dedented line' => ["- ```\n  code\n\n*after*", "- ```\n  code\n\n  ```\n/after/"],
            'an indented item fence ended by a dedented line' => ["- a\n\n  ```\n  code\n*b*", "- a\n\n  ```\n  code\n  ```\n/b/"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheFenceIsCode(string $markdown, string $carve): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }
}
