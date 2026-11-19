<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Include;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class IncludeScalingTest extends TestCase
{
    public function testC2N067RepeatedIncludesStayLinear(): void
    {
        $resolver = static fn (array $request): array => ['text' => 'One.', 'id' => 'one.crv'];
        $times = [];
        foreach ([100, 200, 400, 800] as $count) {
            $source = str_repeat("{{ one.crv }}\n\n", $count);
            $samples = [];
            for ($round = 0; $round < 3; $round++) {
                $start = hrtime(true);
                Carve::toHtml($source, ['includes' => $resolver]);
                $samples[] = (hrtime(true) - $start) / 1e6;
            }
            sort($samples);
            $times[] = $samples[1];
        }
        $this->assertLessThanOrEqual(max(10.0, $times[0] * 20), $times[3]);
    }

    public function testC2N064LongChainAndC2N046BigTable(): void
    {
        $chain = static function (array $request): array {
            $n = (int)substr($request['path'], 1);

            return ['text' => $n === 16 ? 'End.' : '{{ f' . ($n + 1) . ' }}', 'id' => $request['path']];
        };
        $this->assertSame('<p>End.</p>', Carve::toHtml('{{ f1 }}', ['includes' => $chain]));
        $table = "Name,Value\n" . str_repeat("A,1\n", 3000);
        $resolver = static fn (array $request): array => ['text' => $table, 'id' => 't.csv'];
        $html = Carve::toHtml('{table}' . "\n" . '{{ t.csv @rows:2990-2995 @code:csv }}', ['includes' => $resolver]);
        $this->assertSame(6, substr_count($html, '<tr><td>A</td><td>1</td></tr>'));
    }
}
