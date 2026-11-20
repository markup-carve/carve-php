<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Citations;
use MarkupCarve\Carve\Extension\Glossary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Next2OwnerAndVoidRulesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string>}>
     */
    public static function ownerCases(): array
    {
        return [
            'div opener inside an admonition' => ["::: note\n  ::: references\n  x\n  :::\n:::\n", ['block-opener-overindented']],
            'heading inside a div' => ["::: box\n # h\n:::\n", ['block-opener-overindented']],
            'heading inside a quote' => [">  # h\n", ['block-opener-overindented']],
            'raw fence inside a document' => ["  ```=html\n<b>\n  ```\n", ['block-opener-overindented']],
            'term at quoted content column' => ["> :: a\n", []],
            'heading at quoted content column' => ["> # h\n", []],
            'item owns its opener' => ["- x\n\n    # h\n", ['list-item-block-overindented']],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $expected
     */
    #[DataProvider('ownerCases')]
    public function testOwnerColumns(string $source, array $expected): void
    {
        self::assertSame($expected, self::codes($source));
    }

    public function testVoidReferencesRuleWithAndWithoutExtensions(): void
    {
        $source = "> ::: references\n> :::\n";
        $citations = Citations::bundle()->definitions();
        $glossary = Glossary::bundle()->definitions();
        foreach ([[], $citations, $glossary, [...$citations, ...$glossary]] as $extensions) {
            self::assertSame([], self::codes($source, ['extensions' => $extensions]));
            self::assertSame([], self::codes($source, [
                'extensions' => $extensions,
                'rules' => ['references-placement-in-container' => 'on'],
            ]));
        }
    }

    public function testDivInsideListItemKeepsOverindentedOpenerWithItem(): void
    {
        $source = "1. ::::\n    >\n";
        $actual = array_values(array_map(
            static fn ($diagnostic): array => [$diagnostic->code, $diagnostic->startOffset, $diagnostic->endOffset],
            array_filter(Carve::lint($source), static fn ($diagnostic): bool => in_array(
                $diagnostic->code,
                ['list-item-block-overindented', 'block-opener-overindented'],
                true,
            )),
        ));
        self::assertSame([['list-item-block-overindented', 12, 13]], $actual);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return array<string>
     */
    private static function codes(string $source, array $options = []): array
    {
        return array_map(static fn ($diagnostic): string => $diagnostic->code, Carve::lint($source, $options));
    }
}
