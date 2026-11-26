<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\AttributeParser;
use MarkupCarve\Carve\Attrs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `AttributeParser::$ranOut` tells a block cut short by the end of the input from one that already failed, so the
 * writer can stop spelling an attribute candidate once no longer input could make it a block.
 */
final class AttributeParserRanOutTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function prefixes(): array
    {
        return [
            'brace alone' => ['{', true],
            'spaces' => ['{  ', true],
            'id mark' => ['{#', true],
            'class name' => ['{.cls', true],
            'key' => ['{key', true],
            'underscore key' => ['{_k', true],
            'key and equals' => ['{k=', true],
            'unquoted value' => ['{k=v', true],
            'open quote' => ['{k="a}b', true],
            'quote with backslash' => ['{k="a\\', true],
            'language dash' => ['{:en-', true],
            'mark' => ['{*x', false],
            'underscore bare key' => ['{_k ', false],
            'id without name' => ['{# ', false],
            'glued part' => ['{.a.b', false],
            'pipe value' => ['{k=|', false],
            'language double dash' => ['{:en--', false],
        ];
    }

    #[DataProvider('prefixes')]
    public function testFailedReadSaysWhetherTheInputRanOut(string $prefix, bool $ranOut): void
    {
        $this->assertSame(-1, AttributeParser::read($prefix, 0, strlen($prefix), new Attrs()));
        $this->assertSame($ranOut, AttributeParser::$ranOut);
    }

    public function testRanOutPrefixesCanStillCloseAndFailedOnesCannot(): void
    {
        foreach (self::prefixes() as $name => [$prefix, $ranOut]) {
            $closable = false;
            foreach (['}', 'a}', '"}', 'a"}', '=a}'] as $tail) {
                $source = $prefix . $tail;
                $closable = $closable || AttributeParser::read($source, 0, strlen($source), new Attrs()) === strlen($source);
            }
            $this->assertSame($ranOut, $closable, $name);
        }
    }
}
