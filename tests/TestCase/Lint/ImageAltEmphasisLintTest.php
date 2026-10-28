<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\AuthoringLinter;
use PHPUnit\Framework\TestCase;

final class ImageAltEmphasisLintTest extends TestCase
{
    public function testImageAltAndDestinationAreAtomicForEmphasisLint(): void
    {
        $alts = [
            'x *y', 'x /y', '*x y', '/x y', 'x `]` *y',
            'x [*y]', 'x ![*y](/nested)', 'x \\*y', 'x *y [z]', 'x /y [z]',
        ];
        $hosts = [
            '%s', '> %s', '* [ ] %s', "::: note\n%s\n:::", ":: term\n: %s",
        ];
        $contexts = [
            '%s', 'before %s after', '[%s](/outer)', '*closed* %s', '/closed/ %s',
        ];
        $count = 0;
        $linter = new AuthoringLinter();
        foreach ($alts as $alt) {
            foreach ($hosts as $host) {
                foreach (range(0, 3) as $shift) {
                    foreach ($contexts as $context) {
                        $image = "![{$alt}](/image)";
                        $source = sprintf($host, str_repeat(' ', $shift) . sprintf($context, $image)) . "\n";
                        self::assertSame([], $this->emphasisColumns($linter, $source), $source);
                        ++$count;
                    }
                }
            }
        }
        self::assertSame(1000, $count);
    }

    public function testDelimiterOutsideImageStillWarns(): void
    {
        $linter = new AuthoringLinter();
        self::assertSame([1], $this->emphasisColumns($linter, "/before ![x *y](/image) after\n"));
        self::assertSame([13], $this->emphasisColumns($linter, "![x *y](/u) /outside\n"));
        self::assertSame([], $this->emphasisColumns($linter, "* [ ] ![x *y](/u)\n"));
        self::assertSame([], $this->emphasisColumns($linter, "a ![t`]`z](/i.png) b\n"));
        self::assertSame([], $this->emphasisColumns($linter, "![x *y][ref]\n\n[ref]: /u\n"));
    }

    /**
     * Return the columns reported for unclosed emphasis.
     *
     * @return list<int>
     */
    private function emphasisColumns(AuthoringLinter $linter, string $source): array
    {
        return array_values(array_map(
            static fn ($warning): int => $warning->column,
            array_filter($linter->lint($source), static fn ($warning): bool => $warning->rule === 'unclosed-inline-emphasis'),
        ));
    }
}
