<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\AuthoringLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DestinationOpenerLintTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<array{int, int}>}>
     */
    public static function emphasisCases(): iterable
    {
        yield 'image destination in a link label' => ["[![a](/i)](/u)\n", []];
        yield 'image destination in text' => ["x ![a](/a/b) y\n", []];
        yield 'image title across a soft wrap' => ["![a](/i \"t\nu\")\n", []];
        yield 'narrow no-break space before an opener' => ["[r]: https://e.com\u{202F}/path\n[text][r]\n", [[1, 20]]];
        yield 'ideographic space before an opener' => ["a\u{3000}*p end\n", [[1, 3]]];
        yield 'non-ASCII letter after an opener' => ["a *über end\n", [[1, 3]]];
        yield 'closed emphasis' => ["a /b/ c\n", []];
    }

    /**
     * @param string $source
     * @param list<array{int, int}> $expected
     */
    #[DataProvider('emphasisCases')]
    public function testUnclosedEmphasisReadsOnlyRenderedText(string $source, array $expected): void
    {
        $warnings = array_values(array_filter(
            (new AuthoringLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === 'unclosed-inline-emphasis',
        ));
        self::assertSame($expected, array_map(static fn ($warning): array => [$warning->line, $warning->column], $warnings), $source);
    }
}
