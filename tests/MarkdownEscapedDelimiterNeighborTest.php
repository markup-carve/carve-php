<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownEscapedDelimiterNeighborTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'escaped asterisk before strong' => ['_\\***x** q', '<p>_*<strong>x</strong> q</p>' . "\n"],
            'escaped underscore before strong' => ['\\_**x** q', '<p>_<strong>x</strong> q</p>' . "\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testEscapedDelimiterRemainsTextBesideStrong(string $markdown, string $expectedHtml): void
    {
        $carve = (new MarkdownToCarve())->convert($markdown);

        self::assertSame(preg_replace('/\n\z/', '', $expectedHtml), CarveConverter::create()->convert($carve));
    }
}
