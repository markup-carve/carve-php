<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\IncludeLinter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\TestCase;

final class IncludeLintConformanceTest extends TestCase
{
    public function testVectors(): void
    {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../spec/tests/lint-include-conformance/vectors.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(29, $data['vectors']);
        foreach ($data['vectors'] as $vector) {
            $resolver = $vector['resolver'] === 'none' ? null : new class ($vector['files']) implements IncludeResolverInterface {
                public function __construct(private array $files)
                {
                }

                public function resolve(string $path, IncludeContext $context): ?string
                {
                    return $this->files[$path] ?? null;
                }
            };
            $actual = array_map(static function ($warning) use ($data): array {
                $row = ['rule' => $warning->rule, 'file' => $warning->file ?? $data['sourcePath'], 'line' => $warning->line, 'column' => $warning->column];
                if ($warning->includedBy !== null) {
                    $row['includedBy'] = $warning->includedBy;
                }

                return $row;
            }, (new IncludeLinter())->lint($vector['entry'], [], $resolver, $data['sourcePath']));
            $sort = static fn (array $a, array $b): int => [$a['file'], $a['line'], $a['column'], $a['rule']] <=> [$b['file'], $b['line'], $b['column'], $b['rule']];
            usort($actual, $sort);
            $expected = $vector['expected']['findings'];
            usort($expected, $sort);
            self::assertSame($expected, $actual, $vector['name']);
        }
    }
}
