<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SnippetFootnote6921Test extends TestCase
{
    /**
     * @return array<string, array{string, array<string, string>, list<array{string, string}>}>
     */
    public static function cases(): array
    {
        return [
            'two copies' => [
                "{\$s}: [^n]\n\n{{ \$s }} and {{ \$s }}.\n\n[^n]: Note",
                [], [],
            ],
            'unused copy' => [
                "{\$s}: [^n]\n\n[^n]: Note",
                [], [],
            ],
            'unresolved reference in unused definition' => [
                '{$s}: [^n]',
                [], [['unresolved-footnote', '']],
            ],
            'parent note used by included copy' => [
                "[^n]: Note\n\n{{ child.crv }}",
                ['child.crv' => "{\$s}: [^n]\n\n{{ \$s }}"], [],
            ],
            'child note not visible to parent snippet' => [
                "{\$s}: [^n]\n\n{{ \$s }}\n\n{{ child.crv }}",
                ['child.crv' => '[^n]: Note'], [
                    ['unused-footnote-definition', 'child.crv'],
                    ['unresolved-footnote', ''],
                ],
            ],
            'unused child snippet does not use parent note' => [
                "[^n]: Note\n\n{{ child.crv }}",
                ['child.crv' => '{$s}: [^n]'], [['unused-footnote-definition', '']],
            ],
            'use before included definition' => [
                "[^n]: Note\n\n{{ \$s }}\n\n{{ child.crv }}",
                ['child.crv' => '{$s}: [^n]'], [],
            ],
            'child note wins its own scope' => [
                "[^n]: Root\n\n{{ \$s }}\n\n{{ child.crv }}",
                ['child.crv' => "{\$s}: [^n]\n\n[^n]: Child"],
                [['unused-footnote-definition', '']],
            ],
            'nested copies use parent note' => [
                "[^n]: Note\n\n{{ child.crv }}",
                ['child.crv' => "{\$a}: [^n]\n\n{\$b}: {{ \$a }}\n\n{{ \$b }}"], [],
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     * @param list<array{string, string}> $expected
     */
    #[DataProvider('cases')]
    public function testFootnoteLint(string $source, array $files, array $expected): void
    {
        $options = $files === [] ? [] : [
            'includes' => static fn (array $request): ?array => isset($files[$request['path']])
                ? ['text' => $files[$request['path']], 'id' => $request['path']] : null,
        ];
        $actual = array_map(static fn (Diagnostic $d): array => [$d->code, $d->file ?? ''], Carve::lint($source, $options));
        $codes = ['unresolved-footnote', 'unused-footnote-definition'];
        $actual = array_values(array_filter($actual, static fn (array $entry): bool => in_array($entry[0], $codes, true)));

        self::assertSame($expected, $actual);
    }
}
