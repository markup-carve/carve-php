<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Html\CoreLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The direct writer of the core subset renders exactly what the full pipeline renders, and leaves every other
 * document to it. Any non-empty option forces the full pipeline.
 */
final class CoreLayoutTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'tight list' => ["- a\n- b\n"],
            'nested items' => ["- item 0 alpha *em* `c0`\n  - nested alpha\n  - nested two\n- item 1\n"],
            'nested then top' => ["- a\n  - b\n- c\n  - d\n  - e"],
            'paragraph lines' => ["para one\nline two\n\nlast para, end."],
            'list between paragraphs' => ["a\n\n- b *c d*, e `f g`.\n\n\nh\n"],
            'digit first word' => ["2024 a\n1 b"],
            'leading blank lines' => ["\n\n- a\n\n"],
        ];
    }

    #[DataProvider('acceptedProvider')]
    public function testAcceptedMatchesFullPipeline(string $source): void
    {
        $html = CoreLayout::render($source);
        self::assertNotNull($html);
        self::assertSame(Carve::render($source, ['strictLosses' => false]), $html);
        self::assertSame($html, Carve::render($source));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fallbackProvider(): array
    {
        return [
            'empty' => [''],
            'blank between items' => ["- a\n\n- b"],
            'second list after blank' => ["- a\n\n\n- b"],
            'list after paragraph line' => ["x\n- a"],
            'lazy line' => ["- a\nb"],
            'deeper nesting' => ["- a\n  - b\n    - c"],
            'nested without item' => ['  - a'],
            'star marker' => ['* a'],
            'ordered marker word' => ['a. b'],
            'item starting with a digit' => ['- 1 a'],
            'trailing space' => ['a '],
            'double space' => ['a  b'],
            'ellipsis' => ['a.. b'],
            'hyphen in text' => ['a-b'],
            'intraword star' => ['a*b*'],
            'unclosed code' => ['a `b'],
            'code with outer space' => ['a ` b`'],
            'escapable byte' => ['a & b'],
            'tab' => ["- a\tb"],
            'crlf' => ["a\r\nb"],
            'non-ascii' => ["caf\u{00E9}"],
            'thematic break' => ['---'],
        ];
    }

    #[DataProvider('fallbackProvider')]
    public function testFallback(string $source): void
    {
        self::assertNull(CoreLayout::render($source));
    }

    public function testGeneratedVariantsMatchFullPipeline(): void
    {
        mt_srand(5823);
        $words = ['a', 'Ab', 'item', 'x1', '2024', 'e.g', 'a,b', 'end.', '*em*', '*a b*', '`c0`', '`c d`', '`x,y`'];
        $edits = ['*', '`', '.', '..', ',', '-', ' ', 'x', '1.', "\n", "\n\n", '- ', '  - ', '    '];
        $accepted = 0;
        for ($d = 0; $d < 600; $d++) {
            $lines = [];
            $count = mt_rand(1, 10);
            for ($k = 0; $k < $count; $k++) {
                $text = ['alpha'];
                $len = mt_rand(0, 6);
                for ($w = 0; $w < $len; $w++) {
                    $text[] = $words[mt_rand(0, count($words) - 1)];
                }
                $prefix = ['', '', '- ', '- ', '  - ', ''][mt_rand(0, 5)];
                $lines[] = mt_rand(0, 4) === 0 ? '' : $prefix . implode(' ', $text);
            }
            $source = implode("\n", $lines) . "\n";
            if (mt_rand(0, 1) === 1) {
                $source = substr_replace($source, $edits[mt_rand(0, count($edits) - 1)], mt_rand(0, strlen($source) - 1), 0);
            }
            $html = CoreLayout::render($source);
            if ($html === null) {
                continue;
            }
            $accepted++;
            self::assertSame(Carve::render($source, ['strictLosses' => false]), $html, json_encode($source) ?: '');
        }
        self::assertGreaterThan(50, $accepted);
    }
}
