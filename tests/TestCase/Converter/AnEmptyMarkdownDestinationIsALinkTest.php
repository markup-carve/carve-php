<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnEmptyMarkdownDestinationIsALinkTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        $path = dirname(__DIR__, 2) . '/fixtures/empty-markdown-destinations.json';
        $rows = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($rows as $row) {
            $cases[$row['name']] = [$row['source'], $row['carve']];
        }

        return $cases;
    }

    #[DataProvider('shapes')]
    public function testTheLinkOrImageSurvives(string $markdown, string $carve): void
    {
        $actual = rtrim((new MarkdownToCarve())->convert($markdown), "\n");
        self::assertSame($carve, $actual);
        $converter = new CarveConverter();
        self::assertSame($converter->convert($carve), $converter->convert($actual));
    }

    public function testPointyDestinationHoldingASpaceIsNotEmpty(): void
    {
        self::assertNotSame('[m]()', rtrim((new MarkdownToCarve())->convert('[m](< >)'), "\n"));
    }
}
