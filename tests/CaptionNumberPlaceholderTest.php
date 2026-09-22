<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CaptionNumberPlaceholderTest extends TestCase
{
    #[DataProvider('captions')]
    public function testOnlyABareHashIsNumbered(string $caption, string $expected): void
    {
        $html = trim((new CarveConverter())->convert("![p](p.png)\n^ {$caption}"));

        $this->assertStringContainsString("<figcaption>{$expected}</figcaption>", $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function captions(): iterable
    {
        yield 'word-glued bare hash' => ['Figure#* q', 'Figure1* q'];
        yield 'numeric tag' => ['Figure #1 q', 'Figure <span class="tag"><strong>#1</strong></span> q'];
        yield 'underscore tag' => ['Figure #_ q', 'Figure <span class="tag"><strong>#_</strong></span> q'];
        yield 'hyphenated tag' => ['Figure #-a q', 'Figure <span class="tag"><strong>#-a</strong></span> q'];
    }
}
