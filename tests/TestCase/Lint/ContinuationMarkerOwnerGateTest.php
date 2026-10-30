<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\FenceAndContinuationLinter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContinuationMarkerOwnerGateTest extends TestCase
{
    use RuleATodo;

    /**
     * @return iterable<string, array{string, list<array{int, int}>}>
     */
    public static function cases(): iterable
    {
        yield 'list content column' => ["- a\n  +\n\nx\n", []];
        yield 'list content column before reference' => ["- a\n  +\n  [r]: /u\n\nsee [t][r]\n", []];
        yield 'between nested marker columns' => ["- x\n  - L\n +\n", []];
        yield 'between list and quote' => ["- x\n  > q\n +\n", [[3, 2]]];
        yield 'past quote column' => ["- x\n  > q\n   +\n", [[3, 4]]];
        yield 'past closed quote' => ["> q\n +\n- m\n", [[2, 2]]];
        yield 'past quoted paragraph' => ["> q\n>   +\n> para\n", [[2, 5]]];
        yield 'missing quote prefix' => ["> - a\n  +\n", []];
        yield 'missing inner quote prefix' => ["> > - a\n>   +\n>   - b\n", [[2, 5]]];
        yield 'quote lazy literal plus at end' => ["- x\n  > q\n  +\n", [[3, 3]]];
        yield 'nested list content column' => ["- a\n  - b\n    +\n    c\n", []];
        yield 'stacked first block quote at ancestor column' => ["- a\n  - - +\n  > b\n", [[2, 7]]];
        yield 'task checkbox column sibling' => ["- foo\n  - [ ] task\n    +\n  - x\n", [[3, 5]]];
        yield 'task checkbox column paragraph' => ["- foo\n  - [ ] task\n    +\n    q\n", [[3, 5]]];
        yield 'footnote blank follower' => ["x[^a]\n\n[^a]: note\n+\n\npara\n", [[4, 1]]];
        yield 'quoted footnote blank follower' => ["> [^a]: n\n> +\n\npara\n", [[2, 3]]];
        yield 'stacked quote follower at its line column' => ["- a\n  - > +\n  b\n", [[2, 7]]];
        yield 'stacked quote heading at its line column' => ["- a\n  - > +\n  # h\n", [[2, 7]]];
        yield 'stacked quote follower at an inner item column' => ["- - > +\n  b\n", [[1, 7]]];
        yield 'stacked ordered quote follower at its line column' => ["- a\n  1. > +\n  b\n", [[2, 8]]];
        yield 'stacked quote sibling at its line column' => ["- a\n  - > +\n  - c\n", [[2, 7]]];
        yield 'stacked quote follower right of its quote' => ["- a\n  - > +\n      b\n", [[2, 7]]];
    }

    /**
     * @param string $source
     * @param list<array{int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testLintFollowsTheMarkerOwner(string $source, array $expected): void
    {
        $warnings = array_values(array_filter(
            (new FenceAndContinuationLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === 'continuation-marker-refused',
        ));
        self::assertSame($expected, array_map(static fn ($warning): array => [$warning->line, $warning->column], $warnings), $source);
    }
}
