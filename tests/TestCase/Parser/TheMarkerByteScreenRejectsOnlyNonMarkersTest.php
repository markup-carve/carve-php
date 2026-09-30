<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Block\ListParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The byte screen in front of the list-marker patterns only skips work.
 *
 * The oracle is the same parser with the screen switched off, so every line the
 * pattern cascade accepts must still be accepted, with the same result.
 */
class TheMarkerByteScreenRejectsOnlyNonMarkersTest extends TestCase
{
    /**
     * Every string up to three bytes over the bytes marker heads are built
     * from, followed by tails that make a marker a marker or not.
     *
     * @return array<string>
     */
    private static function lines(): array
    {
        $alphabet = ['-', '*', '+', '.', ')', '1', '9', 'a', 'i', 'v', 'Z', 'q', ' ', "\t", '[', ']', 'x', '{', '}', '>', '%'];
        $heads = [''];
        $frontier = [''];
        for ($length = 1; $length <= 3; $length++) {
            $next = [];
            foreach ($frontier as $prefix) {
                foreach ($alphabet as $byte) {
                    $next[] = $prefix . $byte;
                }
            }
            $heads = [...$heads, ...$next];
            $frontier = $next;
        }

        $lines = [];
        foreach ($heads as $head) {
            foreach (['', ' x', ' [x] y', '{.k} x', "\n"] as $tail) {
                $lines[] = $head . $tail;
            }
        }

        return $lines;
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function bulletModeProvider(): array
    {
        return ['default bullets' => [false], 'with the plus bullet' => [true]];
    }

    #[DataProvider('bulletModeProvider')]
    public function testTheScreenedParserAgreesWithTheUnscreenedOne(bool $plusBullet): void
    {
        $screened = new ListParser();
        $screened->allowPlusBullet($plusBullet);
        $unscreened = new class extends ListParser {
            protected function markerTokenCanStartAt(string $line, int $at): bool
            {
                return true;
            }
        };
        $unscreened->allowPlusBullet($plusBullet);

        $accepted = 0;
        foreach (self::lines() as $line) {
            $expected = $unscreened->parseListItemMarker($line);
            $this->assertSame($expected, $screened->parseListItemMarker($line), 'parse: ' . var_export($line, true));
            $accepted += $expected === null ? 0 : 1;

            foreach ([0, 1, 2] as $from) {
                $this->assertSame(
                    $unscreened->markerHeadAt($line, $from),
                    $screened->markerHeadAt($line, $from),
                    'head at ' . $from . ': ' . var_export($line, true),
                );
            }
        }

        // Without accepted lines the comparison would prove nothing.
        $this->assertGreaterThan(500, $accepted);
    }

    public function testTheCommentOpenerScreenAgreesWithTheTrimmedRead(): void
    {
        $parser = new FencedBlockParser();
        foreach (['', ' ', '  ', "\t", " \t "] as $indent) {
            foreach (['%%%', '%%%% tail', '%%', '% %%', 'x%%%', '', "\n%%%", ' '] as $body) {
                $line = $indent . $body;
                $this->assertSame(
                    $parser->parseFencedCommentOpener(ltrim($line, " \t")),
                    $parser->parseFencedCommentOpenerAnyColumn($line),
                    var_export($line, true),
                );
            }
        }
    }

    public function testAnOverriddenMarkerGrammarKeepsItsTokens(): void
    {
        $parser = new class extends ListParser {
            protected function markerTokens(?string $bulletClass = null): array
            {
                return parent::markerTokens('@');
            }
        };
        $this->assertNotNull($parser->parseListItemMarker('@ item'));
        $this->assertNotNull($parser->markerHeadAt('@ item'));
    }

    public function testAnOverriddenCommentOpenerKeepsItsDispatch(): void
    {
        $parser = new class extends FencedBlockParser {
            public function parseFencedCommentOpener(string $line): ?array
            {
                return $line === '!!!' ? parent::parseFencedCommentOpener('%%%') : null;
            }
        };
        $this->assertSame($parser->parseFencedCommentOpener('!!!'), $parser->parseFencedCommentOpenerAnyColumn(" \t!!!"));
    }
}
