<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Next2AttributeContainerEndTest extends TestCase
{
    /**
     * @return array<string, array{string, int, int}>
     */
    public static function endedContainers(): array
    {
        return [
            'quote before heading' => ["> {.c}\n# H", 2, 6],
            'quote before div' => ["> {.c}\n:::", 2, 6],
            'quote before code fence' => ["> {.c}\n```\nx\n```", 2, 6],
            'quote before list' => ["> {.c}\n- a", 2, 6],
            'item before heading' => ["- {.c}\n# H", 2, 6],
            'description before heading' => [":: t\n: {.c}\n# H", 7, 11],
            'nested item before comment' => ["2) a. {#id .c k=v}\n  %% #region r", 6, 18],
            'nested item before div' => ["* * {#id}\n:::", 4, 9],
        ];
    }

    #[DataProvider('endedContainers')]
    public function testEndedContainerReportsAttribute(string $source, int $start, int $end): void
    {
        $findings = array_values(array_filter(
            Carve::lint($source),
            static fn ($diagnostic): bool => $diagnostic->code === 'unattached-block-attribute',
        ));
        self::assertCount(1, $findings);
        self::assertSame([$start, $end], [$findings[0]->startOffset, $findings[0]->endOffset]);
        self::assertStringStartsWith('This attribute line reaches no block:', $findings[0]->message);
    }

    public function testAttributeStillReachesBlockInsideItsContainer(): void
    {
        foreach (["> {.c}\n> # H", "- {.c}\n  # H", "{.c}\n# H"] as $source) {
            $findings = array_filter(
                Carve::lint($source),
                static fn ($diagnostic): bool => $diagnostic->code === 'unattached-block-attribute',
            );
            self::assertCount(0, $findings, $source);
        }
    }
}
