<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\SourceLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnOpenerInADescriptionInAnItemIsNotOverindentedTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function descriptionShapes(): array
    {
        return [
            'nested definition list after a blank' => ["- :: t\n  : d\n\n    :: u\n    : e\n"],
            'nested definition list without a blank' => ["- :: t\n  : d\n    :: u\n    : e\n"],
            'quote in a description below the item text' => ["- a\n\n  :: t\n  : d\n\n    > q\n"],
            'the same shape inside a quote' => ["> - :: t\n>   : d\n>\n>     :: u\n>     : e\n"],
        ];
    }

    #[DataProvider('descriptionShapes')]
    public function testTheDescriptionContentColumnPlacesTheBlock(string $source): void
    {
        self::assertSame([], $this->overindented($source));
    }

    public function testABlockPastTheItemColumnOutsideAnyDescriptionIsStillReported(): void
    {
        self::assertSame([[3, 5]], $this->overindented("- a\n\n    > q\n"));
    }

    /**
     * @return array<int, array{int, int}>
     */
    private function overindented(string $source): array
    {
        $found = [];
        foreach ((new SourceLinter())->lint($source) as $warning) {
            if ($warning->rule === 'list-item-block-overindented') {
                $found[] = [$warning->line, $warning->column];
            }
        }

        return $found;
    }
}
