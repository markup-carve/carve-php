<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class APlusAttachedFenceKeepsItsCaptionAndAFoldedFenceLineLoosensTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function attachedFences(): array
    {
        return [
            'backtick bullet' => ["- x\n+\n```\nx\n```\n^ cap\n- c\n", '<pre><code>x'],
            'tilde ordered' => ["1. x\n+\n~~~\nx\n~~~\n^ cap\n2. c\n", '<pre><code>x'],
            'first block' => ["- +\n```\nx\n```\n^ cap\n- c\n", '<pre><code>x'],
            'figure group' => ["- x\n+\n::: figure\n![a](b.png)\n:::\n^ cap\n- c\n", 'carve-figure-group'],
        ];
    }

    #[DataProvider('attachedFences')]
    public function testAttachedFenceKeepsItsCaption(string $source, string $host): void
    {
        $html = CarveConverter::create()->convert($source);
        self::assertStringContainsString($host, $html);
        self::assertStringContainsString('<figcaption>cap</figcaption>', $html);
        self::assertStringContainsString('<li>c</li>', $html);
        self::assertStringNotContainsString('<p>^ cap', $html);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function figureOpeners(): array
    {
        return [
            'label' => ['::: figure [label]', '<ul><li>x <div class="figure"><p class="div-label">label</p><p>hi</p></div></li></ul><p>^ cap - c</p>'],
            'title' => ['::: figure "t"', '<ul><li>x <div class="figure"><p class="admonition-title">t</p><p>hi</p></div></li></ul><p>^ cap - c</p>'],
            'metadata' => ['::: figure {#id}', '<ul><li>x <div class="figure"><p>hi</p></div></li></ul><p>^ cap - c</p>'],
            'bare' => ['::: figure', '<ul><li>x <figure class="carve-figure-group"><p>hi</p><figcaption>cap</figcaption></figure></li><li>c</li></ul>'],
        ];
    }

    #[DataProvider('figureOpeners')]
    public function testOnlyFigureGroupTakesAttachedCaption(string $opener, string $expected): void
    {
        $source = "- x\n+\n{$opener}\nhi\n:::\n^ cap\n- c\n";
        $html = CarveConverter::create()->convert($source);
        $collapsed = preg_replace('/\s+/', ' ', trim($html));
        self::assertSame($expected, preg_replace('/> </', '><', $collapsed));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundTrips(): array
    {
        return [
            'backtick' => ["- x\n  ```\n  x\n  ```\n\n  ^ cap\n- c\n"],
            'nested lead' => ["- - text\n  ```\n  x\n  ```\n\n  ^ cap\n- c\n"],
            'tilde' => ["- x\n  ~~~\n  x\n  ~~~\n\n  ^ cap\n- c\n"],
        ];
    }

    #[DataProvider('roundTrips')]
    public function testFormatterPreservesTheReading(string $source): void
    {
        $converter = CarveConverter::create();
        $formatted = CarveConverter::toCarve($source);
        self::assertSame($converter->convert($source), $converter->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function foldedLines(): array
    {
        return [
            'backtick' => ["- - text\n  ```\n  x\n\n  cap\n- c\n", true],
            'tilde' => ["- - text\n  ~~~\n  x\n\n  cap\n- c\n", true],
            'image control' => ["- - text\n  ![i](x.png)\n  x\n\n  cap\n- c\n", true],
            'no blank control' => ["- - text\n  ```\n  x\n  cap\n- c\n", false],
        ];
    }

    #[DataProvider('foldedLines')]
    public function testFoldedFenceDoesNotHideTheOuterBlank(string $source, bool $loose): void
    {
        $html = CarveConverter::create()->convert($source);
        self::assertStringContainsString($loose ? '<p>cap</p>' : 'cap', $html);
        self::assertStringContainsString($loose ? '<li><p>c</p></li>' : '<li>c</li>', $html);
    }
}
