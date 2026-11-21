<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\PublicApi;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\OptionError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionPointerTest extends TestCase
{
    /**
     * @return array<string, array{0: \Closure(): mixed, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'stage mismatch CC-506' => [static fn (): string => Carve::render('x', ['alignTables' => true]), '/alignTables'],
            'unknown key with pointer escapes' => [static fn (): mixed => Carve::parse('x', ['bad/name~' => true]), '/bad~1name~0'],
            'invalid value' => [static fn (): string => Carve::render('x', ['profile' => 'not-a-profile']), '/profile'],
            'invalid nested value' => [static fn (): string => Carve::render('x', ['urlPolicy' => ['unknown' => []]]), '/urlPolicy'],
        ];
    }

    #[DataProvider('refusals')]
    public function testOptionRefusalNamesItsOption(Closure $call, string $path): void
    {
        try {
            $call();
            self::fail('Expected an option refusal');
        } catch (OptionError $error) {
            self::assertSame($path, $error->path);
        }
    }
}
