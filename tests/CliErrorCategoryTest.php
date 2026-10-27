<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CliErrorCategoryTest extends TestCase
{
    /**
     * @return array<string, array{list<string>, string, string}>
     */
    public static function failures(): array
    {
        return [
            'unknown option' => [['--unknown'], '', 'usage'],
            'invalid option value' => [['--smart-typography', 'nope'], '', 'usage'],
            'invalid AST JSON' => [['--from-json', '--html'], '{bad', 'ast-json-invalid'],
            'invalid AST shape' => [['--from-json'], '{}', 'ast-json-invalid'],
            'unreadable input' => [['--html', '/no-such-carve-input-4086.crv'], '', 'input-unreadable'],
            'lint option' => [['lint', '--unknown'], '', 'usage'],
            'lint input' => [['lint', '/no-such-carve-input-4086.crv'], '', 'input-unreadable'],
            'fmt option' => [['fmt', '--unknown'], '', 'usage'],
            'fmt input' => [['fmt', '/no-such-carve-input-4086.crv'], '', 'input-unreadable'],
            'migrate source' => [['migrate', '--from', 'nope'], '', 'usage'],
            'migrate mode' => [['migrate', '--from', 'html', '--mode', 'nope'], '', 'usage'],
            'migrate input' => [['migrate', '--from', 'html', '/no-such-carve-input-4086.crv'], '', 'input-unreadable'],
            'merge option' => [['merge', '--unknown'], '', 'usage'],
            'merge input' => [['merge', '/no-such-carve-input-4086.crv', 'a.crv', 'b.crv'], '', 'input-unreadable'],
            'flatten option' => [['flatten', '--unknown'], '', 'usage'],
            'flatten input' => [['flatten', '/no-such-carve-input-4086.crv'], '', 'input-unreadable'],
        ];
    }

    /**
     * @param list<string> $args
     * @param string $input
     * @param string $category
     */
    #[DataProvider('failures')]
    public function testErrorsHaveStableCategoriesAndStatus(array $args, string $input, string $category): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../bin/carve', ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(2, $status, $err ?: 'No error message');
        self::assertSame('', $out);
        self::assertStringStartsWith("carve[$category]: ", $err);
    }
}
