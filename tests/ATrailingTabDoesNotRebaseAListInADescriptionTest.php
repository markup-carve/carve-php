<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ATrailingTabDoesNotRebaseAListInADescriptionTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{string}>
     */
    public static function lineFinalWhitespace(): array
    {
        return [
            'tab' => ["\t"],
            'two tabs' => ["\t\t"],
            'space then tab' => [" \t"],
        ];
    }

    #[DataProvider('lineFinalWhitespace')]
    public function testContentWhitespaceDoesNotChangeListOwnership(string $tail): void
    {
        $source = ":: term\n: - a\n\n   - c{$tail}\n";
        $plain = ":: term\n: - a\n\n   - c\n";

        $converter = new CarveConverter();
        $this->assertSame($converter->convert($plain), $converter->convert($source));
        $this->assertSame(2, substr_count($converter->convert($source), '<ul>'));
        $this->assertStringNotContainsString('<li><p>', $converter->convert($source));
    }
}
