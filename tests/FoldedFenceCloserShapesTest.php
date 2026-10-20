<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FoldedFenceCloserShapesTest extends TestCase
{
    #[DataProvider('shapes')]
    public function testShape(string $source, string $html): void
    {
        $this->assertSame($html, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        $fixture = file_get_contents(__DIR__ . '/fixtures/folded-fence-closer-shapes.json');
        self::assertNotFalse($fixture);
        /** @var array<string, array{source: string, html: string}> $shapes */
        $shapes = json_decode($fixture, true, 512, JSON_THROW_ON_ERROR);
        foreach ($shapes as $name => $shape) {
            yield $name => [$shape['source'], $shape['html']];
        }
    }
}
