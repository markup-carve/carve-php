<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\IncludeLinter;
use MarkupCarve\Carve\Lint\LintWarning;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\TestCase;

final class IncludedHeadingCrossReferenceTest extends TestCase
{
    public function testHeadingSuppliedByAnIncludeIsACrossReferenceTarget(): void
    {
        $warnings = (new IncludeLinter())->lint("# T\n\n{{ part.crv }}\n\nSee </#Inc>. And </#Nope>.\n", [], $this->resolver(), 'page.crv');

        self::assertSame(
            [['broken-crossref', 5, 18]],
            array_map(static fn (LintWarning $warning): array => [$warning->rule, $warning->line, $warning->column], $warnings),
        );
    }

    public function testWithoutAResolverTheReferenceStaysBroken(): void
    {
        $warnings = (new IncludeLinter())->lint("# T\n\n{{ part.crv }}\n\nSee </#Inc>.\n");

        self::assertSame(['broken-crossref'], array_map(static fn (LintWarning $warning): string => $warning->rule, $warnings));
    }

    private function resolver(): IncludeResolverInterface
    {
        return new class implements IncludeResolverInterface {
            public function resolve(string $path, IncludeContext $context): ?string
            {
                return $path === 'part.crv' ? "# Inc\n\nBody.\n" : null;
            }
        };
    }
}
