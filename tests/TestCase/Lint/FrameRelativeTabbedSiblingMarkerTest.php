<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FrameRelativeTabbedSiblingMarkerTest extends TestCase
{
    /**
     * @return array<string, array{string, list<array{int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'same-kind sibling' => ["- a\n    - b\n \t- c\n", [[3, 3]]],
            'different-kind sibling' => ["- a\n    - b\n \t* c\n", [[3, 3]]],
            'ordered sibling' => ["- a\n    - b\n \t1. c\n", [[3, 3]]],
            'repeated siblings' => ["- a\n    - b\n \t- c\n \t- d\n", [[3, 3], [4, 3]]],
            'outer column shifted once' => [" - a\n    - b\n \t- c\n", [[3, 3]]],
            'outer column at tab stop' => ["  - a\n    - b\n \t- c\n", []],
            'tab followed by a space' => ["  - a\n    - b\n \t - c\n", [[3, 4]]],
            'quote frame' => ["> - a\n>     - b\n>  \t- c\n", []],
            'active quote owner is not a detached host' => [">  - a\n>     - b\n>  \t* c\n", []],
        ];
    }

    #[DataProvider('cases')]
    public function testListColumnWarningUsesTheItemFrame(string $source, array $expected): void
    {
        $warnings = array_values(array_filter(
            (new DocumentLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === 'list-marker-below-content-column',
        ));
        $this->assertSame($expected, array_map(static fn ($warning): array => [$warning->line, $warning->column], $warnings));
    }
}
