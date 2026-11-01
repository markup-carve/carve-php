<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Lint\LintWarning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A plus below a stacked item's fence is refused. A plus past a quote's marker column is a quiet letter under
 * PART 0 S2 and the #4648 ruling.
 */
final class ARefusedPlusBelowAStackedFenceOrPastAQuoteColumnIsReportedTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function provider(): array
    {
        return [
            'stacked fence, end of input' => ["- - ```\n  +\n", ['2:3']],
            'stacked fence, column-0 follower' => ["- - ```\n  +\n{.c\n}\n~~~\ny\n~~~\n", ['2:3']],
            'stacked fence, payload column' => ["- - ```\n    +\n", []],
            'quote, past its column' => ["> q\n>   +\n> para\n", []],
            'quote, past its column at the end' => ["> q\n>   +\n", []],
            'nested quote, past its column' => ["> > q\n> >   +\n> > para\n", []],
            'document, past column 0' => ["q\n  +\npara\n", []],
            'item, past its column' => ["- a\n   +\n  b\n", []],
        ];
    }

    /**
     * @param string $source
     * @param list<string> $expected
     */
    #[DataProvider('provider')]
    public function testTheRefusedPlusIsReported(string $source, array $expected): void
    {
        $found = array_values(array_map(
            static fn (LintWarning $warning): string => $warning->line . ':' . $warning->column,
            array_filter(
                (new DocumentLinter())->lint($source),
                static fn (LintWarning $warning): bool => $warning->rule === 'continuation-marker-refused',
            ),
        ));

        $this->assertSame($expected, $found);
    }

    public function testAPlusPastTheQuoteColumnIsQuiet(): void
    {
        $warnings = (new DocumentLinter())->lint("> q\n>   +\n> para\n");

        $this->assertSame([], $warnings);
    }
}
