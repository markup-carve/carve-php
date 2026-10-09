<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\DjotEmphasis;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class DjotStructuralPrefixScansTest extends TestCase
{
    public function testPrefixGrammarAtEveryAsterisk(): void
    {
        $reader = new ReflectionMethod(DjotEmphasis::class, 'structuralPrefixEnd');
        $chunks = ['* ', '+ ', '- ', '12. ', "3)\t", '[x] ', "[ ]\t", '>', '> ', ' ', "\t", '\\*', '*a*', '1.', '[X]', '[*]', 'é', 'x'];
        $pattern = '/^(?:[ \t]*>)*[ \t]*(?:(?:[-*+]|[0-9]+[.)])[ \t]+(?:\[[ xX-]\][ \t]+)?)*[ \t]*$/D';
        $seed = 7;
        for ($row = 0; $row < 1000; $row++) {
            $line = '';
            for ($at = 0; $at < 12; $at++) {
                $seed = (1664525 * $seed + 1013904223) & 0xffffffff;
                $line .= $chunks[$seed % count($chunks)];
            }
            $end = $reader->invoke(null, $line);
            for ($at = 0, $length = strlen($line); $at < $length; $at++) {
                if ($line[$at] === '*') {
                    self::assertSame(preg_match($pattern, substr($line, 0, $at)) === 1, $at <= $end, $line . ' at ' . $at);
                }
            }
        }
    }

    public function testLongMarkerChainsPreserveTrailingEmphasis(): void
    {
        foreach ([1000, 4000, 16000] as $count) {
            $markers = str_repeat('* ', $count);
            $source = $markers . '_a_';
            self::assertSame($markers . '/a/', DjotEmphasis::convert($source, $source, static fn (string $plain): string => $plain));
        }
    }

    public function testQuotedNonThematicLinesAvoidBacktrackingAndJitErrors(): void
    {
        $pattern = (new ReflectionClass(DjotEmphasis::class))->getConstant('THEMATIC_STAR_LINE');
        self::assertIsString($pattern);
        foreach ([16, 1000, 16000] as $count) {
            $source = str_repeat('>  ', $count) . 'x * * *';
            self::assertSame(0, preg_match($pattern, $source));
            self::assertSame(PREG_NO_ERROR, preg_last_error());
            self::assertSame(str_repeat('>  ', $count) . 'x \\* \\* \\*', DjotEmphasis::convert($source, $source, static fn (string $plain): string => $plain));
            self::assertSame(PREG_NO_ERROR, preg_last_error());
            $source = str_repeat('>  ', $count) . str_repeat('* ', $count);
            self::assertSame(1, preg_match($pattern, $source));
            self::assertSame(PREG_NO_ERROR, preg_last_error());
            self::assertSame($source, DjotEmphasis::convert($source, $source, static fn (string $plain): string => $plain));
            self::assertSame(PREG_NO_ERROR, preg_last_error());
        }
    }

    public function testLineFactsResetAfterAThematicBreak(): void
    {
        $source = "***\n* _a_\n> * _b_";
        self::assertSame("***\n* /a/\n> * /b/", DjotEmphasis::convert($source, $source, static fn (string $plain): string => $plain));
    }
}
