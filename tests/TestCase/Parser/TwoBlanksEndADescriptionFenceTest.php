<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expectations checked against the spec oracle at 9b938e8a (carve-php#2681).
 */
class TwoBlanksEndADescriptionFenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fenceCases(): iterable
    {
        foreach (['```', '~~~', '```=html', '~~~=html'] as $opener) {
            $run = substr($opener, 0, 3);
            foreach ([3, 4] as $column) {
                $indent = str_repeat(' ', $column);
                foreach ([1, 2, 3] as $blanks) {
                    foreach ([0, 1, 2, 3] as $offset) {
                        $past = str_repeat(' ', $offset);
                        $source = ":: term\n:  desc\n\n{$indent}{$opener}\n{$indent}a\n"
                            . str_repeat("\n", $blanks) . "{$indent}{$past}{$run}\n";
                        $payload = "a\n";
                        if ($blanks === 1) {
                            $payload .= "\n";
                            if ($offset > 0) {
                                $payload .= "{$past}{$run}\n";
                            }
                        }
                        $block = str_ends_with($opener, '=html')
                            ? $payload
                            : "<pre><code>{$payload}</code></pre>\n";
                        $expected = "<dl>\n  <dt>term</dt>\n  <dd>\n    <p>desc</p>\n    {$block}  </dd>\n</dl>\n";
                        if ($blanks > 1) {
                            $outside = $run === '```' ? '<code></code>' : '~~~';
                            $expected .= "<p>{$outside}</p>\n";
                        }

                        yield "{$opener} column {$column} blanks {$blanks} offset {$offset}" => [$source, $expected];
                    }
                }
            }
        }
    }

    public function testACloserBeyondTwoBlanksCannotInterruptTheParagraph(): void
    {
        $source = ":: term\n:  desc\n   ```\nlazy\n\n\n   ```\n";
        $expected = "<dl>\n  <dt>term</dt>\n  <dd>desc\n<code>\nlazy</code></dd>\n</dl>\n<p><code></code></p>\n";
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testACloserBeyondOneBlankCanInterruptTheParagraph(): void
    {
        $source = ":: term\n:  desc\n   ```\nlazy\n\n   ```\n";
        $expected = "<dl>\n  <dt>term</dt>\n  <dd>\n    <p>desc</p>\n    <pre><code></code></pre>\n  </dd>\n</dl>\n"
            . "<p>lazy</p>\n<p><code></code></p>\n";
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testTheSameBoundaryAppliesWithoutAFence(): void
    {
        $source = ":: term\n:  desc\n\n\n   tail\n";
        $expected = "<dl>\n  <dt>term</dt>\n  <dd>desc</dd>\n</dl>\n<p>tail</p>\n";
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    #[DataProvider('fenceCases')]
    public function testFenceCannotExtendItsDescription(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }
}
