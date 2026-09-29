<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\SourceLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A raw block's `=FORMAT` is a well-formed info string, so a raw fence below its
 * container's content column is a column defect and not an info string defect
 * (markup-carve/carve-php#2764).
 *
 * `parseCodeFenceOpener()` refuses `=FORMAT` by design, because
 * `parseRawBlockOpener()` owns that spelling. Asking only the first of the two
 * made every indented raw fence report `fence-opener-fallback`, which
 * `docs/validation.md` reserves for an invalid info string.
 */
class ARawBlockOpenerIsAValidInfoStringTest extends TestCase
{
    /**
     * @return array<string, array{string, array<int, string>}>
     */
    public static function fences(): array
    {
        return [
            'raw fence under a quote, below its content column' => [
                "> q\n ```=html\n <b>r</b>\n ```\n",
                ['fence-delimiter-indentation 2:2', 'fence-delimiter-indentation 4:2'],
            ],
            'tilde raw fence under a quote' => [
                "> q\n ~~~=html\n <b>r</b>\n ~~~\n",
                ['fence-delimiter-indentation 2:2', 'fence-delimiter-indentation 4:2'],
            ],
            'raw fence indented at the top level' => [
                "x\n\n   ```=latex\n   \\r\n   ```\n",
                ['fence-delimiter-indentation 3:4', 'fence-delimiter-indentation 5:4'],
            ],
            'raw fence at column 0' => [
                "x\n\n```=latex\n\\r\n```\n",
                [],
            ],
            'raw opener with trailing text still falls back' => [
                "x\n\n   ```=latex x\n   y\n   ```\n",
                ['fence-opener-fallback 3:4', 'fence-delimiter-indentation 5:4'],
            ],
            'a format that does not start with a letter still falls back' => [
                "x\n\n   ```=1bad\n   y\n   ```\n",
                ['fence-opener-fallback 3:4', 'fence-delimiter-indentation 5:4'],
            ],
            'an invalid code fence info string still falls back' => [
                "x\n\n   ```js title=\"x\"\n   y\n   ```\n",
                ['fence-opener-fallback 3:4', 'fence-delimiter-indentation 5:4'],
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<int, string> $expected
     */
    #[DataProvider('fences')]
    public function testTheFenceRuleFollowsTheDefect(string $source, array $expected): void
    {
        $actual = array_map(
            fn ($warning): string => $warning->rule . ' ' . $warning->line . ':' . $warning->column,
            (new SourceLinter())->lint($source),
        );
        $this->assertSame($expected, $actual, $source);
    }
}
