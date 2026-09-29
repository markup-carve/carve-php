<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ContainerOwnershipTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield from self::fixtureCases('container-ownership.json');
        yield from self::fixtureCases('container-ownership-boundaries.json');
    }

    /**
     * @throws \RuntimeException
     *
     * @return iterable<string, array{string, string}>
     */
    private static function fixtureCases(string $file): iterable
    {
        $json = file_get_contents(__DIR__ . '/fixtures/' . $file);
        if ($json === false) {
            throw new RuntimeException('Cannot read ownership fixtures.');
        }
        $cases = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($cases)) {
            throw new RuntimeException('Expected an array of ownership fixtures.');
        }
        foreach ($cases as $case) {
            if (!is_array($case) || !isset($case['source'], $case['html']) || !is_string($case['source']) || !is_string($case['html'])) {
                throw new RuntimeException('Expected source and HTML strings.');
            }

            yield $case['source'] => [$case['source'], $case['html']];
        }
    }

    #[DataProvider('cases')]
    public function testContainerOwnership(string $source, string $html): void
    {
        $this->assertSame($html, trim(CarveConverter::create()->convert($source)));
    }
}
