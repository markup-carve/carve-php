<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\TestCase;

final class ReportedCommentSiblingTagTest extends TestCase
{
    public function testSiblingBracesStayBareAfterTheReportedComment(): void
    {
        $cases = [
            ['{\\#a#} {#z}', '\\{\\#a\\#} {\\#z}'],
            ['x {\\#ab#} {#tag}', 'x \\{\\#ab\\#} {\\#tag}'],
            ['{\\#a#} {#z} {#y}', '\\{\\#a\\#} {\\#z} {\\#y}'],
            ['> {\\#a#} {#z}', '> \\{\\#a\\#} {\\#z}'],
            ['- {\\#a#} {#z}', '- \\{\\#a\\#} {\\#z}'],
        ];
        foreach ($cases as [$source, $expected]) {
            $written = Carve::formatWithDiagnostics($source);
            self::assertSame($expected . "\n", $written->source);
            self::assertSame([], $written->diagnostics);
            self::assertTrue(TreeEquality::equal(
                TreeEncoder::decodedForWriter(Carve::parse($source)),
                TreeEncoder::decodedForWriter(Carve::parse($written->source)),
            ));
        }
    }
}
