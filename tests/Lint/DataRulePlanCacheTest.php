<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class DataRulePlanCacheTest extends TestCase
{
    public function testPlanFollowsTheActiveRulesOfEachCall(): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv('CARVE_LINT_RULES=data');
        try {
            $codes = static fn (array $options): array => array_map(
                static fn ($finding): string => $finding->code,
                Carve::lint("*bold*\n\n= H ##\n", $options),
            );
            $on = ['rules' => ['emphasis-as-heading' => 'on']];
            $off = ['rules' => ['heading-closing-hashes' => 'off']];
            self::assertSame(['emphasis-as-heading', 'heading-closing-hashes'], $codes($on));
            self::assertSame([], $codes($off));
            self::assertSame(['heading-closing-hashes'], $codes([]));
            self::assertSame(['emphasis-as-heading', 'heading-closing-hashes'], $codes($on));
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
