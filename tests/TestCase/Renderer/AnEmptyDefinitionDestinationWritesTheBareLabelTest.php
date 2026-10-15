<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition whose destination is empty, authored or blanked, writes `[label]:`
 * with no trailing space and no title or attributes, as carve-js and carve-rs do
 * (corpus 108-security-hardening-12, 379).
 */
class AnEmptyDefinitionDestinationWritesTheBareLabelTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function definitionProvider(): array
    {
        return [
            'authored empty' => ["T\n\n[y]:\n", '[y]:'],
            'authored empty, trailing spaces' => ["T\n\n[y]:   \n", '[y]:'],
            'denied' => ["T\n\n[y]: javascript:alert(3)\n", '[y]:'],
            'denied with a title' => ["T\n\n[y]: javascript:x \"t\"\n", '[y]:'],
            'denied with attributes' => ["T\n\n[y]: javascript:x {.c}\n", '[y]:'],
            'kept destination' => ["T\n\n[y]: /u \"t\" {.c}\n", '[y]: /u "t" {.c}'],
        ];
    }

    #[DataProvider('definitionProvider')]
    public function testAnsi(string $source, string $line): void
    {
        $output = CarveConverter::create(renderer: new AnsiRenderer())->convert($source);

        $this->assertSame("T\n\n\033[2m" . $line . "\033[0m", rtrim($output, "\n"));
    }

    #[DataProvider('definitionProvider')]
    public function testPlain(string $source, string $line): void
    {
        $output = CarveConverter::create(renderer: new PlainTextRenderer())->convert($source);

        $this->assertSame("T\n\n" . $line, rtrim($output, "\n"));
    }
}
