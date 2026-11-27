<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class LeadingBomPositionTest extends TestCase
{
    public function testPublicAndCliPositionsSkipOnlyTheLeadingBom(): void
    {
        $source = "\xEF\xBB\xBF# é\n\xEF\xBB\xBFx";
        self::assertSame(['line' => 1, 'column' => 1], Carve::positionOf($source, 0));
        self::assertSame(['line' => 1, 'column' => 1], Carve::positionOf($source, 3));
        self::assertSame(['line' => 1, 'column' => 3], Carve::positionOf($source, 5));
        self::assertSame(['line' => 2, 'column' => 2], Carve::positionOf($source, 11));

        $state = ['offset' => 0, 'line' => 1, 'column' => 1];
        self::assertSame([1, 1], Command::location($source, 3, $state));
        self::assertSame([1, 3], Command::location($source, 5, $state));
        self::assertSame([2, 1], Command::location($source, 8, $state));
        self::assertSame([2, 2], Command::location($source, 11, $state));
        self::assertSame([1, 1], Command::location($source, 3, $state));
    }

    public function testLintWritesBomFreeLineOneColumn(): void
    {
        $input = "\xEF\xBB\xBF[x]()\n";
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $input);
        rewind($in);
        self::assertSame(1, Command::main(['lint', '-'], $in, $out, $err));
        rewind($out);
        self::assertStringContainsString(':1:1: warning empty-link-destination', (string)stream_get_contents($out));

        $tree = Carve::encodeTree(Carve::parse("\xEF\xBB\xBF# é\n"));
        self::assertStringContainsString('"startColumn":1,"startLine":1,"startOffset":3', $tree);
        self::assertStringContainsString('"startColumn":3,"startLine":1,"startOffset":5', $tree);
    }

    public function testC2T019LintPositionAfterTextMatchesClauseCase2509(): void
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, "\xEF\xBB\xBFa **b**\n");
        rewind($in);
        self::assertSame(1, Command::main(['lint', '-'], $in, $out, $err));
        rewind($out);
        self::assertStringContainsString(
            '<stdin>:1:3: warning markdown-strong-double-star',
            (string)stream_get_contents($out),
        );
    }
}
