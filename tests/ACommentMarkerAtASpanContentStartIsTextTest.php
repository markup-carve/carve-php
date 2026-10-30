<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P9-041: an emphasis span's first content character does not start an
 * inline run, so a `%%` there is text. A link label does start one.
 */
class ACommentMarkerAtASpanContentStartIsTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'strong' => ["*%%*\n", '<p><strong>%%</strong></p>'],
            'emphasis' => ["/%%/\n", '<p><em>%%</em></p>'],
            'underline' => ["_%%_\n", '<p><u>%%</u></p>'],
            'strong with text' => ["*%% b*\n", '<p><strong>%% b</strong></p>'],
            'braced strong' => ["{*%% b*}\n", '<p><strong>%% b</strong></p>'],
            'braced highlight' => ["{=%% b=}\n", '<p><mark>%% b</mark></p>'],
            'combined token' => ["/*%%*/\n", '<p><strong><em>%%</em></strong></p>'],
            'after a space it is a comment' => ["*a %% b*\n", '<p>*a</p>'],
            'a link label starts a run' => ["[%% x](u) t\n", '<p><a href="u"></a> t</p>'],
        ];
    }

    #[DataProvider('cases')]
    public function testTheMarkerReadsAsTheRunItStandsIn(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
