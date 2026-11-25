<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5973, C2-I-118: past the depth cap the rounds escape one brace of a run per round. The run is placed at
 * once and cut to the escapes the rounds would have placed.
 */
final class CappedBraceRunLinearityTest extends TestCase
{
    public function testTheRunPastTheCapReadsBackWithTheSameEscapes(): void
    {
        $source = Writer::write(self::pairs(256));
        $this->assertSame(56, substr_count($source, '[r]{}\\{=x}'));
        $this->assertSame(200, substr_count($source, '[r]{}{=x}'));
        $this->assertStringEndsWith("[r]{}\\{=x} [r]{}{=x}\n", $source);
        $read = json_decode(Carve::toJson($source), true);
        $this->assertIsArray($read);
        $this->assertCount(512, $read['children'][0]['children']);
    }

    public function testBelowTheCapNoBraceIsEscaped(): void
    {
        $this->assertSame(rtrim(str_repeat('[r]{}{=x} ', 190)) . "\n", Writer::write(self::pairs(190)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function pairs(int $count): array
    {
        $children = [];
        for ($i = 0; $i < $count; $i++) {
            $children[] = ['type' => 'span', 'children' => [['type' => 'text', 'value' => 'r']]];
            $children[] = ['type' => 'text', 'value' => '{=x} '];
        }

        return ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'paragraph', 'children' => $children]]];
    }
}
