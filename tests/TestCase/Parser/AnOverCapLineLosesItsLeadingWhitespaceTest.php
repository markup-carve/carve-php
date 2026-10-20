<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnOverCapLineLosesItsLeadingWhitespaceTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function indentation(): array
    {
        return ['spaces' => [''], 'tabs' => ["\t"]];
    }

    #[DataProvider('indentation')]
    public function testOverCapLinesBecomeUnindentedParagraphLines(string $extraIndent): void
    {
        $source = '';
        for ($i = 0; $i < 203; $i++) {
            $source .= str_repeat('  ', $i) . ($i >= 200 ? $extraIndent : '') . "- item {$i}\n";
        }

        $html = (new CarveConverter())->convert($source);
        $lines = explode("\n", $html);
        $lastItem = null;
        foreach ($lines as $index => $line) {
            if (str_contains($line, '<li>item 199')) {
                $lastItem = $index;

                break;
            }
        }
        self::assertNotNull($lastItem);
        $rendererIndent = strlen($lines[$lastItem]) - strlen(ltrim($lines[$lastItem], ' '));

        self::assertSame(str_repeat(' ', $rendererIndent) . '<li>item 199', $lines[$lastItem]);
        self::assertSame(str_repeat(' ', $rendererIndent + 2) . '- item 200', $lines[$lastItem + 1]);
        self::assertSame('- item 201', $lines[$lastItem + 2]);
        self::assertSame('- item 202', $lines[$lastItem + 3]);
        self::assertSame(str_repeat(' ', $rendererIndent) . '</li>', $lines[$lastItem + 4]);
    }
}
