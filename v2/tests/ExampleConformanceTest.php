<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test;

use MarkupCarve\Carve2\Carve2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ExampleConformanceTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
     */
    public static function examples(): array
    {
        $base = __DIR__ . '/fixtures/';
        $examples = json_decode((string)file_get_contents($base . 'examples.json'), true, 512, JSON_THROW_ON_ERROR);
        $trees = file($base . 'example-trees.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
        $source = json_decode((string)file_get_contents($base . 'example-trees-source.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(434, count($examples));
        self::assertCount(count($examples), $trees);
        $overrides = [];
        foreach ($source['htmlClauseOverrides'] as $override) {
            $overrides[$override['file'] . '#' . $override['number']] = $override['clauseHtml'];
        }
        $cases = [];
        foreach ($examples as $i => $example) {
            $key = $example['file'] . '#' . $example['number'];
            $tree = json_decode($trees[$i], true, 512, JSON_THROW_ON_ERROR);
            $cases[$key] = [$example, $tree, $overrides[$key] ?? $example['html']];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $example
     * @param array<string, mixed> $tree
     * @param string $html
     *
     * @return void
     */
    #[DataProvider('examples')]
    public function testC2ExampleTreeAndHtml(array $example, array $tree, string $html): void
    {
        $options = self::options($example['flags']);
        $this->assertSame($html, Carve2::toHtml($example['input'], $options));
        $got = json_decode(Carve2::toJson($example['input'], $options), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::sorted($tree['tree']), self::sorted($got));
    }

    public function testC2ExampleUnknownFlagIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        self::options(['--unknown']);
    }

    /**
     * @param array<string> $flags
     *
     * @throws \UnexpectedValueException
     *
     * @return array<string, mixed>
     */
    private static function options(array $flags): array
    {
        $options = [];
        foreach ($flags as $flag) {
            if ($flag === '--heading-ids lower') {
                $options['headingIds'] = 'lower';
            } elseif ($flag === '--roundtrip') {
                $options['roundtrip'] = true;
            } elseif ($flag === '--no-raw-html') {
                $options['rawHtml'] = false;
            } elseif (str_starts_with($flag, '--profile ')) {
                $options['profile'] = substr($flag, 10);
            } elseif (str_starts_with($flag, '--social ')) {
                $options['social'] = json_decode(substr($flag, 9), true, 512, JSON_THROW_ON_ERROR);
            } else {
                throw new UnexpectedValueException('Unknown example flag: ' . $flag);
            }
        }

        return $options;
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sorted(...), $value);
    }
}
