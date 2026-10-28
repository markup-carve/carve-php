<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class InvalidBodyMetadataTableTest extends TestCase
{
    public function testInvalidBodyMetadataKeepsAllRowsInOneBody(): void
    {
        $converter = new CarveConverter();
        $count = 0;
        foreach (['body-rows=x', 'body-rows=-1', 'body-rows=1.5', 'body-rows=9999999999999999999', 'body-header-rows=1'] as $metadata) {
            foreach (range(1, 4) as $width) {
                foreach (range(0, 4) as $shape) {
                    $header = '|= ' . implode(' |= ', array_fill(0, $width, 'H')) . " |\n";
                    $body = $shape === 0 ? '' : '| ' . implode(' | ', array_fill(0, $shape, 'D')) . " |\n";
                    $table = "{{$metadata}}\n{$header}{$body}";
                    foreach (range(0, 4) as $host) {
                        $nested = match ($host) {
                            1 => '> ' . str_replace("\n", "\n> ", rtrim($table, "\n")) . "\n",
                            2 => "- item\n  " . str_replace("\n", "\n  ", rtrim($table, "\n")) . "\n",
                            3 => "::: box\n{$table}:::\n",
                            4 => "::: note\n{$table}:::\n",
                            default => $table,
                        };
                        foreach ([false, true] as $sibling) {
                            $source = $nested . ($sibling ? "\nTail\n" : '');
                            $html = $converter->convert($source);
                            self::assertStringContainsString('<tbody>', $html, "variant {$count}");
                            self::assertStringNotContainsString('<thead>', $html, "variant {$count}");
                            self::assertStringContainsString('<th scope="row">H</th>', $html, "variant {$count}");
                            $count++;
                        }
                    }
                }
            }
        }
        self::assertSame(1000, $count);
    }
}
