<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourceUnicodeMarkerExhaustionTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        $pool = '';
        for ($code = 0xE000; $code <= 0xF8FF; $code++) {
            $pool .= mb_chr($code, 'UTF-8');
        }
        $payload = $pool . "\u{E009}" . str_repeat("\u{E008}", 24) . "\u{E010}\u{E00A}";

        return [
            'text' => ["a{$payload}b\n", $payload],
            'code' => ["`{$payload}`\n", $payload],
            'literal' => ["!`{$payload}`\n", $payload],
            'block' => ["```\na{$payload}  \n\nb\t\n```\n", $payload],
            'image' => ["![{$payload}](image)\n", $payload],
            'link' => ["[{$payload}](/target)\n", $payload],
            'verse' => ["| a{$payload}\n| b\n", $payload],
            'quote' => ["> a{$payload}\n> b\n", $payload],
            'list' => ["- a{$payload}\n  b\n", $payload],
            'footnote label' => ["a{$pool}b\n\n[^\u{E009}\u{E008}\u{E008}\u{E010}\u{E00A}]: definition\n", $pool],
        ];
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function semanticTree(array $value): array
    {
        unset($value['pos'], $value['srcByteLength']);
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::semanticTree($child);
            }
        }

        return $value;
    }

    #[DataProvider('sources')]
    public function testSourceAndAstPreserveTheOccupiedPool(string $source, string $payload): void
    {
        $writer = CarveConverter::carve();
        $codec = new AstCodec();
        $document = $writer->parse($source);
        $expected = self::semanticTree($codec->encode($document));
        foreach ([$writer->convertWithReport($source, strictLosses: true), $writer->renderWithReport($document, strictLosses: true)] as $result) {
            self::assertStringContainsString($payload, $result->value);
            self::assertSame($expected, self::semanticTree($codec->encode($writer->parse($result->value))));
            self::assertSame([], $result->losses);
            self::assertSame(0, $result->totalLosses);
            self::assertFalse($result->truncated);
            self::assertSame($result->value, $writer->convert($result->value));
        }
        self::assertSame("ordinary\n", $writer->convert("ordinary\n"));
    }
}
