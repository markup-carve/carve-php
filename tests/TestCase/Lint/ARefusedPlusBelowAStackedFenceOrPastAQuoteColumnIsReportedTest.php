<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Lint\LintWarning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+` below a stacked item's fence, left of the fence's content column (corpus 650-3), and a quoted `+` past the
 * quote's content column (corpus 630-5, docs/validation.md) are refused and reported; a `+` at the fence's content
 * column is payload.
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
            'quote, past its column' => ["> q\n>   +\n> para\n", ['2:5']],
            'quote, past its column at the end' => ["> q\n>   +\n", ['2:5']],
            'nested quote, past its column' => ["> > q\n> >   +\n> > para\n", ['2:7']],
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

    public function testTheMessageSaysNoContainerClaimsTheColumn(): void
    {
        $warnings = (new DocumentLinter())->lint("> q\n>   +\n> para\n");

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('no open container has a marker at its column', $warnings[0]->message);
        $this->assertStringContainsString('remains text in the block quote', $warnings[0]->message);
    }
}
