<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\AuthoringLinter;
use PHPUnit\Framework\TestCase;

final class FoldedListMarkerOwnerTest extends TestCase
{
    public function testMarkerInsideAnItemOrInvisibleFootnoteIsQuiet(): void
    {
        foreach (
            [
                ":: t\n: - para\n - li\n",
                ":: t\n:  [^f]: note\n     - nested\n   tail\n",
                ". d\n   [^n]: e\n     - a\n",
            ] as $source
        ) {
            self::assertSame([], $this->findings($source), $source);
        }
    }

    public function testMarkerFoldedIntoAnOrdinaryParagraphStillWarns(): void
    {
        self::assertSame([2], $this->findings("para\n- li\n"));
        self::assertSame([4], $this->findings("x[^n]\n\n[^n]: para\n  - li\n"));
    }

    /**
     * @return list<int>
     */
    private function findings(string $source): array
    {
        return array_values(array_map(
            static fn ($warning): int => $warning->line,
            array_filter(
                (new AuthoringLinter())->lint($source),
                static fn ($warning): bool => $warning->rule === 'list-marker-without-blank-line',
            ),
        ));
    }
}
