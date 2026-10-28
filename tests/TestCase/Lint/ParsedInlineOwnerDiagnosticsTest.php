<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\AuthoringLinter;
use PHPUnit\Framework\TestCase;

final class ParsedInlineOwnerDiagnosticsTest extends TestCase
{
    public function testDeniedAutolinkIsNotBareHtml(): void
    {
        self::assertSame([], $this->findings("<vbscript:msgbox>\n", 'bare-html-tag'));
        self::assertSame([1], $this->findings("<b>bold</b>\n", 'bare-html-tag'));
    }

    public function testParsedDestinationsAndCodeDoNotSupplyEmphasisDelimiters(): void
    {
        foreach (["[![a](/i)](/u)\n", "a ![t[z]](/i.png) b\n"] as $source) {
            self::assertSame([], $this->findings($source, 'unclosed-inline-emphasis'), $source);
        }

        self::assertSame([1], $this->findings("*a ` b*\n", 'unclosed-inline-emphasis'));
        self::assertSame([3], $this->findings("x /a </#nothing> y\n", 'unclosed-inline-emphasis'));
        self::assertSame([4], $this->findings("[x /y](/u)\n", 'unclosed-inline-emphasis'));
    }

    /**
     * @return list<int>
     */
    private function findings(string $source, string $rule): array
    {
        return array_values(array_map(
            static fn ($warning): int => $warning->column,
            array_filter(
                (new AuthoringLinter())->lint($source),
                static fn ($warning): bool => $warning->rule === $rule,
            ),
        ));
    }
}
