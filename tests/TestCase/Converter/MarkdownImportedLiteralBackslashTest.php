<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownImportedLiteralBackslashTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'cm-013' => ["\\\t\\A\\a\\ \\3\\φ\\«\n", "\\\\\t\\\\A\\\\a\\\\ \\\\3\\\\φ\\\\«\n", "\\\t\\A\\a\\\\ \\3\\φ\\«\n"],
            'cm-013 with neighbors' => ["context\n\n\\\t\\A\\a\\ \\3\\φ\\«\n", "context\n\n\\\\\t\\\\A\\\\a\\\\ \\\\3\\\\φ\\\\«\n", "context\n\n\\\t\\A\\a\\\\ \\3\\φ\\«\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testLiteralBackslashesUseWriterSpelling(string $markdown, string $expected, string $oldSpelling): void
    {
        $written = (new MarkdownToCarve())->convert($markdown);
        self::assertSame($expected, $written);
        $converter = new CarveConverter();
        self::assertSame($written, (new CarveRenderer())->render($converter->parse($written)));
        self::assertSame($converter->convert($oldSpelling), $converter->convert($written));
    }
}
