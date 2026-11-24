<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\WriterGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportedBracketAndLengtheningTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function forms(): array
    {
        return [
            'reported bracket in note text' => ['\\^[][^]', "^\\[]\\[^]\n"],
            'reported bracket in reference text' => ['[^][^a\\]', "\\[^]\\[^a]\n"],
            'trial behind strong' => ['.*{*b*\\**}x*z', ".{*\\{*b*}\\**}x*z\n"],
            'unreported bracket beside link' => ['x[[^](/u)', "x[[^](/u)\n"],
            'authored unreported bracket escape' => ['x[\\[^](/u)', "x[[^](/u)\n"],
        ];
    }

    #[DataProvider('forms')]
    public function testReportedKindsAndLengtheningTrials(string $source, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($source);
        self::assertSame([], $result->codes());
        self::assertSame($expected, $result->source);
        self::assertNull(WriterGate::checkFormat($source));
        self::assertNull(WriterGate::check($source));
        self::assertSame($expected, Carve::format($expected));
    }
}
