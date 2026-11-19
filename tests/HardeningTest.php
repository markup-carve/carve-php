<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Html\Escape;
use MarkupCarve\Carve\InputError;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Test\Support\DocGenerator;
use MarkupCarve\Carve\Test\Support\Linearity;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\TestCase;

final class HardeningTest extends TestCase
{
    public function testC2B218GeneratedDocumentsNeverThrow(): void
    {
        for ($seed = 1; $seed <= 600; $seed++) {
            $source = (new DocGenerator($seed))->document();
            $html = Carve::toHtml($source);
            $json = Carve::toJson($source);
            $this->assertTrue(mb_check_encoding($html, 'UTF-8'), (string)$seed);
            $this->assertNotNull(json_decode($json, true), (string)$seed);
        }
    }

    /**
     * C2-B-218: malformed byte sources still parse and render.
     *
     * @return void
     */
    public function testC2B218ByteMutationsNeverThrow(): void
    {
        mt_srand(218);
        $fixture = "# Heading\n\n| a | b |\n\n[a](https://example.org)\n\n::: note\nbody\n:::";
        for ($i = 0; $i < 1000; $i++) {
            $source = $fixture;
            for ($j = 0; $j < 8; $j++) {
                $at = mt_rand(0, strlen($source));
                $source = substr_replace($source, chr(mt_rand(0, 255)), $at, mt_rand(0, 2));
            }
            $html = Carve::toHtml($source);
            $json = Carve::toJson($source);
            $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
            $this->assertNotNull(json_decode($json, true));
        }
    }

    /**
     * C2-B-190, C2-I-118: the four fixtures and six stress shapes scale with source length.
     *
     * @return void
     */
    public function testSizeDoublingStaysLinear(): void
    {
        $units = [];
        foreach (['bench-commonmark-core', 'lists-tables', 'prose', 'reference-heavy'] as $name) {
            $path = __DIR__ . '/../bench/fixtures/' . $name . '.crv';
            $units[$name] = substr((string)file_get_contents($path), 0, 8192) . "\n\n";
        }
        $units['deep nesting'] = str_repeat('> ', 180) . "x\n";
        $units['delimiter run'] = str_repeat('*[', 400);
        $units['wide table'] = '|' . str_repeat(' x |', 200) . "\n";
        $units['percent table'] = str_repeat("| 5% | b |\n", 250);
        $units['references'] = str_repeat('[x][k] ', 100) . "\n\n[k]: /u\n";
        $units['long line'] = str_repeat('words and punctuation *x* ', 35);
        foreach ($units as $name => $unit) {
            $count = max(1, intdiv(8192, strlen($unit)));
            $small = str_repeat($unit, $count);
            $large = str_repeat($unit, 8 * $count);
            $excess = Linearity::excess(static fn () => Carve::toHtml($small), static fn () => Carve::toHtml($large), 8);
            $this->assertLessThanOrEqual(Linearity::LIMIT, $excess, $name);
        }
    }

    /**
     * C2-W-010: the writer's work on the stress shapes grows linearly with the length of the text.
     *
     * @return void
     */
    public function testC2W010WriterStaysLinear(): void
    {
        $units = [
            'escaped refs' => [str_repeat('\\[x] ', 250), "\n\n[x]: /u\n"],
            'stars' => [str_repeat('a*b ', 250), ''],
            'hyphen run' => [str_repeat('-', 1000), ''],
            'dot run' => [str_repeat('.', 1000), ''],
            'sigils' => [str_repeat('@a #b :c: <d ', 100), ''],
            'pipes in cells' => [str_repeat("| a\\|b | [c](/d\\|e) |\n", 60), ''],
            'emphasis nest' => [str_repeat('{*a {/b {_c_} d/} e*} ', 60), ''],
            'table rows' => [str_repeat("| a | `b | c |\n", 60), ''],
            'openers' => [str_repeat('*[', 500), ''],
        ];
        foreach ($units as $name => [$unit, $tail]) {
            $trees = [];
            foreach ([1, 8] as $factor) {
                $source = 'a' . str_repeat($unit, $factor) . $tail;
                $trees[$factor] = (new TreeEncoder())->encode(Carve::parse($source), $source);
            }
            $excess = Linearity::excess(
                static fn () => Writer::writeWithDiagnostics($trees[1]),
                static fn () => Writer::writeWithDiagnostics($trees[8]),
                8,
            );
            $this->assertLessThanOrEqual(Linearity::LIMIT, $excess, $name);
        }
    }

    public function testC2A158LongContinuationCellStaysLinear(): void
    {
        $small = "| a |\n" . str_repeat("+ | *x* y |\n", 250);
        $large = "| a |\n" . str_repeat("+ | *x* y |\n", 2000);
        $excess = Linearity::excess(static fn () => Carve::toHtml($small), static fn () => Carve::toHtml($large), 8);
        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    public function testC2B024LazyLineBlockCommentsStayLinear(): void
    {
        $small = "- ::: |\n  a\n" . str_repeat("%% c\nb\n", 1000);
        $large = "- ::: |\n  a\n" . str_repeat("%% c\nb\n", 8000);
        $excess = Linearity::excess(static fn () => Carve::parse($small), static fn () => Carve::parse($large), 8);
        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    public function testC2H163ProfileByteLengthLimits(): void
    {
        foreach (['minimal' => 10001, 'comment' => 100001] as $profile => $size) {
            try {
                Carve::toHtml(str_repeat('a', $size), ['profile' => $profile]);
                $this->fail('The oversized input was accepted.');
            } catch (InputError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('<p>a</p>', Carve::toHtml('a', ['profile' => 'minimal']));
    }

    public function testC2H002TextFastPathSkipsOnlyMarkupControlsAndNonAscii(): void
    {
        $plain = implode('', array_map('chr', array_merge([9, 10, 13], range(0x20, 0x25), range(0x27, 0x3b), [0x3d], range(0x3f, 0x7e))));
        $this->assertSame(strlen($plain), strcspn($plain, Escape::TEXT_SPECIAL));
        foreach (['&', '<', '>', "\x00", "\x7f", "\x80", "\xff"] as $special) {
            $this->assertSame(0, strcspn($special, Escape::TEXT_SPECIAL));
        }
    }
}
