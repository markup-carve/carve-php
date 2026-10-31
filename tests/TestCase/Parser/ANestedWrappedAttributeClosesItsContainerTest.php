<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANestedWrappedAttributeClosesItsContainerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'definition body' => [":: t\n: - a\n    {.c\n}\npara\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a</li>\n    </ul>\n  </dd>\n</dl>\n<p>para</p>\n"],
            'definition after a paragraph' => [":: t\n: d\n\n  - a\n    {.c\n}\npara\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <ul>\n      <li>a</li>\n    </ul>\n  </dd>\n</dl>\n<p>para</p>\n"],
            'quoted closer' => ["> - a\n>   {.c\n> }\npara\n", "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n</blockquote>\n<p>para</p>\n"],
            'closer outside quote' => ["> - a\n>   {.c\n}\npara\n", "<blockquote>\n  <ul>\n    <li>a\n{.c\n}\npara</li>\n  </ul>\n</blockquote>\n"],
            'braces after a blank' => ["> - a\n>\n>   {x\n>   y\n", "<blockquote>\n  <ul>\n    <li><p>a</p>\n      <p>{x\ny</p>\n    </li>\n  </ul>\n</blockquote>\n"],
            'braces past the item column' => ["> - a\n>    {.c\n>   }\npara\n", "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n</blockquote>\n<p>para</p>\n"],
            'closer outside a plain quote' => ["> a\n> {.c\n}\nb\n", "<blockquote><p>a\n{.c\n}\nb</p></blockquote>\n"],
            'unquoted continuation cannot close a quote attribute' => ["> {.c\nx\n> }\npara\n", "<blockquote><p>{.c\nx\n}\npara</p></blockquote>\n"],
            'explicit continuation after the closer' => [":: t\n: - a\n    {.c\n}\n+\npara\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a</li>\n    </ul>\n    <p>para</p>\n  </dd>\n</dl>\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testContainerOwnership(string $source, string $expected): void
    {
        self::assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function singleLineTwins(): array
    {
        return [
            'deepest definition item' => [":: t\n: - a\n    - b\n      {.c}\npara\n", ":: t\n: - a\n    - b\n      {.c\n}\npara\n"],
            'outer definition item' => [":: t\n: - a\n    - b\n    {.c}\npara\n", ":: t\n: - a\n    - b\n    {.c\n}\npara\n"],
            'deepest quote item' => ["> - a\n>   - b\n>     {.c}\npara\n", "> - a\n>   - b\n>     {.c\n> }\npara\n"],
            'outer quote item' => ["> - a\n>   - b\n>   {.c}\npara\n", "> - a\n>   - b\n>   {.c\n> }\npara\n"],
            'definition after a blank' => [":: t\n: - a\n\n    {.c}\nmore\n", ":: t\n: - a\n\n    {.c\n    }\nmore\n"],
            'quote after a blank' => ["> - a\n>\n>   {.c}\n    cont\n", "> - a\n>\n>   {.c\n>   }\n    cont\n"],
        ];
    }

    #[DataProvider('singleLineTwins')]
    public function testWrappedAttributeMatchesItsSingleLineTwin(string $single, string $wrapped): void
    {
        $converter = new CarveConverter();
        self::assertSame($converter->convert($single), $converter->convert($wrapped));
    }
}
