<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Include;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\TestCase;

final class IncludeScalingTest extends TestCase
{
    public function testC2N067RepeatedIncludesStayLinear(): void
    {
        $resolver = static fn (array $request): array => ['text' => 'One.', 'id' => 'one.crv'];
        $small = str_repeat("{{ one.crv }}\n\n", 100);
        $large = str_repeat("{{ one.crv }}\n\n", 800);
        $excess = Linearity::excess(
            static fn () => Carve::toHtml($small, ['includes' => $resolver]),
            static fn () => Carve::toHtml($large, ['includes' => $resolver]),
            8,
        );
        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
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
