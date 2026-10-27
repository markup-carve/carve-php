<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AHostedContinuationDivOpensShiftedBlocksTest extends TestCase
{
    public function testShiftedOpenersInListHostedBoxes(): void
    {
        $kinds = [
            'colon' => [[':::: inner', 'x', '::::'], '<div class="inner"><p>x</p></div>'],
            'line' => [['::: |', 'x', ':::'], '<div class="line-block"><p>x</p></div>'],
            'backtick' => [['```', 'x', '```'], '<pre><code>x</code></pre>'],
            'tilde' => [['~~~', 'x', '~~~'], '<pre><code>x</code></pre>'],
            'heading' => [['# h'], '<h1 id="h">h</h1>'],
            'quote' => [['> x'], '<blockquote><p>x</p></blockquote>'],
            'break' => [['---'], '<hr>'],
            'table' => [['| x |'], '<table><tbody><tr><td>x</td></tr></tbody></table>'],
        ];
        $converter = new CarveConverter();
        foreach (['marker', 'continuation'] as $host) {
            foreach ([0, 1, 2] as $depth) {
                foreach ($kinds as $kind => [$lines, $expected]) {
                    foreach ([1, 2, 4] as $offset) {
                        foreach ([false, true] as $paragraph) {
                            $start = $host === 'marker' ? ['- ::: box'] : ['- lead', '  ::: box'];
                            $sourceLines = [
                                ...$start,
                                ...($paragraph ? ['  text'] : []),
                                ...array_map(
                                    static fn (string $line): string => str_repeat(' ', 2 + $offset) . $line,
                                    $lines,
                                ),
                                '  :::',
                            ];
                            $source = implode("\n", array_map(
                                static fn (string $line): string => str_repeat('> ', $depth) . $line,
                                $sourceLines,
                            )) . "\n";
                            $html = self::compact($converter->convert($source));
                            $prefix = $paragraph ? '<div class="box"><p>text</p>' : '<div class="box">';
                            self::assertStringContainsString(
                                $prefix . $expected . '</div>',
                                $html,
                                "$host/$depth/$kind/$offset/" . (int)$paragraph . "\n" . $source,
                            );
                        }
                    }
                }
            }
        }
    }

    public function testRootAndRootBoxKeepShiftedOpenersAsText(): void
    {
        $kinds = [
            'colon' => [[':::: inner', 'x', '::::'], '<p>:::: innerx::::</p>'],
            'line' => [['::: |', 'x', ':::'], '<p>::: |x:::</p>'],
            'backtick' => [['```', 'x', '```'], '<p><code>x</code></p>'],
            'tilde' => [['~~~', 'x', '~~~'], '<p>~~~x~~~</p>'],
            'heading' => [['# h'], '<p># h</p>'],
            'quote' => [['> x'], '<p>&gt; x</p>'],
            'break' => [['---'], '<p>—</p>'],
            'table' => [['| x |'], '<p>| x |</p>'],
        ];
        $converter = new CarveConverter();
        foreach ($kinds as $kind => [$lines, $expected]) {
            foreach ([1, 2, 4] as $offset) {
                $body = implode("\n", array_map(
                    static fn (string $line): string => str_repeat(' ', $offset) . $line,
                    $lines,
                )) . "\n";
                self::assertSame(preg_replace('/\n\z/', '', $expected), self::compact($converter->convert($body)), "$kind/$offset/root");
                self::assertSame(
                    preg_replace('/\n\z/', '', '<div class="box">' . $expected . '</div>'),
                    self::compact($converter->convert("::: box\n" . $body . ":::\n")),
                    "$kind/$offset/box",
                );
            }
        }
    }

    private static function compact(string $html): string
    {
        return preg_replace('/\n */', '', trim($html)) ?? $html;
    }
}
