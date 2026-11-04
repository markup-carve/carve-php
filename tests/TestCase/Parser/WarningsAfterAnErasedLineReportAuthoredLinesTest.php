<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\ParseException;
use MarkupCarve\Carve\Exception\ParseWarning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A document carve#5012 parses as its visible lines still reports warnings
 * on the authored lines.
 */
class WarningsAfterAnErasedLineReportAuthoredLinesTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string>}>
     */
    public static function sources(): array
    {
        return [
            'broken anchor below a comment' => ["%% c\n[hi](#missing)\n", ["2:Broken anchor link '#missing' — no element with this ID exists"]],
            'unused erased reference' => ["%% c\n[r]: /u\nx\n", ["2:Reference 'r' defined but never used"]],
            'unused erased reference last' => ["x\n%% c\n[r]: /u\n", ["3:Reference 'r' defined but never used"]],
            'unclosed fence below a comment' => ["%% c\n```\nx\n", ['2:Unclosed code fence']],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $expected
     */
    #[DataProvider('sources')]
    public function testTheWarningNamesTheAuthoredLine(string $source, array $expected): void
    {
        $converter = new CarveConverter(warnings: true);
        $converter->convert($source);

        $this->assertSame($expected, array_map(
            static fn (ParseWarning $warning): string => $warning->getLine() . ':' . $warning->getMessage(),
            $converter->getWarnings(),
        ));
    }

    public function testAStrictModeErrorNamesTheAuthoredLine(): void
    {
        try {
            (new CarveConverter(strict: true))->convert("x\n%% c\n%% d\n```\n");
            $this->fail('Expected a ParseException');
        } catch (ParseException $exception) {
            $this->assertSame(4, $exception->getSourceLine());
            $this->assertSame('Unclosed code fence at line 4, column 1', $exception->getMessage());
        }
    }
}
