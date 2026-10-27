<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvalidIncludeLinesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function invalidRanges(): array
    {
        return [
            'zero' => ['0-2'],
            'inverted' => ['2-1'],
            'leading zero' => ['01-2'],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function testInvalidRangeKeepsResolvedDependencyAndLiteralSource(string $range): void
    {
        $resolver = new class implements IncludeResolverInterface {
            public function resolve(string $path, IncludeContext $context): string
            {
                return "one\ntwo\n";
            }
        };
        $source = "{{ data.txt @lines:{$range} }}";
        $converter = new CarveConverter();
        $expander = new IncludeExpander($resolver);
        $html = $converter->render($converter->transform($converter->parse($source), $expander));

        self::assertStringContainsString('{{ data.txt ', $html);
        self::assertStringContainsString(":{$range} }}", $html);
        self::assertStringNotContainsString('one', $html);
        self::assertCount(1, $expander->getWarnings());
        self::assertSame(IncludeExpander::RULE_LINES_INVALID, $expander->getWarnings()[0]->getRule());
        self::assertCount(1, $expander->getDependencies());
        self::assertSame('data.txt', $expander->getDependencies()[0]->getTarget());
        self::assertTrue($expander->getDependencies()[0]->isResolved());
    }
}
