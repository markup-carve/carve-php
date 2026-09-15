<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use MarkupCarve\Carve\Transform\ResolvedInclude;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §19: the byte budget "does not bound the WORK a processor does to
 * produce it, because a target is resolved before its size is known". So the
 * target that breaks the budget has already been READ when it is refused, and
 * the counter charges it.
 *
 * Counting only ADMITTED bytes left `bytesUsed` unable to tell "read nothing"
 * from "read a file and refused it", and reporting 0 for a file the resolver
 * had just handed over in full (carve-php#1953).
 *
 * The walk itself is not at issue: `resourcesSpent` latches on the same
 * directive either way, so the "every remaining directive MUST degrade to
 * literal without being resolved" rule is satisfied identically - which is why
 * the resolver-call assertions below are part of the pin rather than assumed.
 */
class TheByteBudgetChargesBytesReadTest extends TestCase
{
    /**
     * @param string $entry
     * @param array<string, string> $files
     * @param int $budget
     *
     * @return array{bytes: int, calls: array<string>}
     */
    protected function expand(string $entry, array $files, int $budget): array
    {
        $recorder = new class ($files) implements IncludeResolverInterface {
            /**
             * @var array<string>
             */
            public array $calls = [];

            /**
             * @param array<string, string> $files
             */
            public function __construct(protected array $files)
            {
            }

            public function resolve(string $path, IncludeContext $context): ResolvedInclude|string|null
            {
                $this->calls[] = $path;

                return array_key_exists($path, $this->files)
                    ? new ResolvedInclude($this->files[$path], $path)
                    : null;
            }
        };

        $expander = new class ($recorder, null, 8, $budget, $entry) extends IncludeExpander {
            public function chargedBytes(): int
            {
                return $this->bytesUsed;
            }
        };
        $expander->transform(CarveConverter::carve()->parse($entry));

        return ['bytes' => $expander->chargedBytes(), 'calls' => $recorder->calls];
    }

    /**
     * The clearest row: one 5-byte target under a 4-byte budget. Nothing is
     * admitted, so a counter of admitted bytes reports 0 for a file that was
     * read in full.
     */
    public function testTheTargetThatBreaksTheBudgetIsChargedForItsRead(): void
    {
        self::assertSame(5, $this->expand('{{ large }}', ['large' => '12345'], 4)['bytes']);
    }

    /**
     * The control that stops "charge everything, always" passing the case
     * above: a target within the budget is charged once and no more.
     */
    public function testATargetWithinTheBudgetIsChargedExactlyItsLength(): void
    {
        self::assertSame(5, $this->expand('{{ ok }}', ['ok' => '12345'], 64)['bytes']);
    }

    public function testTheChargeIsTakenAcrossTheTransitiveGraph(): void
    {
        $result = $this->expand('{{ a }}', ['a' => '{{ b }}', 'b' => '12345'], 8);
        self::assertSame(12, $result['bytes']);
    }

    public function testEachOccurrenceOfARepeatedTargetIsCharged(): void
    {
        $result = $this->expand('{{ a }} {{ a }} {{ a }}', ['a' => '1234'], 9);
        self::assertSame(12, $result['bytes']);
    }

    /**
     * Charging the read does not make the walk read more: the sibling after
     * exhaustion is still refused before its resolver runs.
     */
    public function testALaterDirectiveIsStillRefusedWithoutBeingResolved(): void
    {
        $result = $this->expand(
            '{{ large }} {{ must-not-read }}',
            ['large' => '12345', 'must-not-read' => 'secret'],
            4,
        );
        self::assertSame(['large'], $result['calls']);
    }

    /**
     * The transitive walk stops at the directive that spent the budget, so the
     * charge above is not bought with extra resolver work either.
     */
    public function testTheTransitiveWalkStopsWhereTheBudgetIsSpent(): void
    {
        $result = $this->expand('{{ a }}', ['a' => '{{ b }}', 'b' => '12345', 'c' => 'x'], 8);
        self::assertSame(['a', 'b'], $result['calls']);
    }
}
