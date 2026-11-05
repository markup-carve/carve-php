<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Lint\LintParse;
use MarkupCarve\Carve\Lint\SourceLinter;
use PHPUnit\Framework\TestCase;

/**
 * The linters of one DocumentLinter run read one parse instead of one each (carve-php#4651).
 */
class LintSharesOneParseTest extends TestCase
{
    public function testOneSourceIsParsedOnceInsideAShare(): void
    {
        [$first, $again, $other] = LintParse::share('a *b*', static fn (): array => [
            LintParse::parse('a *b*'),
            LintParse::parse('a *b*'),
            LintParse::parse('c'),
        ]);

        $this->assertSame($first[1], $again[1]);
        $this->assertSame($first[0], $again[0]);
        $this->assertNotSame($first[1], $other[1]);
    }

    public function testNothingIsSharedOutsideAShare(): void
    {
        $this->assertNotSame(LintParse::parse('a')[1], LintParse::parse('a')[1]);
    }

    public function testANestedShareRestoresTheOuterOne(): void
    {
        [$before, $after] = LintParse::share('a', static function (): array {
            $before = LintParse::parse('a');
            LintParse::share('b', static fn (): array => LintParse::parse('b'));

            return [$before, LintParse::parse('a')];
        });

        $this->assertSame($before[1], $after[1]);
    }

    public function testASharedRunReportsWhatTheLinterReportsAlone(): void
    {
        $source = "para\n\n{.orphan}\n";
        $alone = (new SourceLinter())->lint($source);
        $rules = array_map(static fn ($warning): string => $warning->rule, (new DocumentLinter())->lint($source));

        $this->assertNotSame([], $alone);
        foreach ($alone as $warning) {
            $this->assertContains($warning->rule, $rules);
        }
    }
}
