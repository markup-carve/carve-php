<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function basename;
use function file_get_contents;
use function glob;

class CaptionListTightnessTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (glob(__DIR__ . '/fixtures/caption_list_tightness/*.crv') ?: [] as $source) {
            $cases[basename($source, '.crv')] = [
                (string)file_get_contents($source),
                (string)file_get_contents(substr($source, 0, -4) . '.html'),
            ];
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function testCaptionGapDeterminesListTightness(string $source, string $expected): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', $expected), CarveConverter::create()->convert($source));
    }
}
