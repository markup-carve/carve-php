<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A note definition between a taken `+` and its block keeps its folded lines; none of them lands in the host.
 */
class ANoteSkippedByAPlusKeepsItsBodyTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'indented continuation' => ["- a\n+\n[^n]: note\n  continuation\n# H\n\nx[^n]\n", 'continuation'],
            'lazy continuation' => ["- a\n+\n[^n]: note\ncontinuation\n# H\n\nx[^n]\n", 'continuation'],
            'description separator' => ["- a\n+\n[^n]: note\n: d2\n\nx[^n]\n", ': d2'],
            'in a description' => [":: t\n: d\n+\n[^n]: note\n  continuation\n# H\n\nx[^n]\n", 'continuation'],
        ];
    }

    #[DataProvider('documents')]
    public function testTheNoteKeepsItsBody(string $source, string $folded): void
    {
        $html = (new CarveConverter())->convert($source);
        $notes = strstr($html, '<section role="doc-endnotes"');
        $this->assertIsString($notes);
        $this->assertStringContainsString($folded, $notes);
        $this->assertStringNotContainsString($folded, substr($html, 0, strlen($html) - strlen($notes)));
    }
}
