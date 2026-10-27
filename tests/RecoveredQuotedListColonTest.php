<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class RecoveredQuotedListColonTest extends TestCase
{
    public function testRecoveredControlsKeepTheirTextAndBlockOwners(): void
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/fixtures/recovered-quoted-list-colon.json'), true, 512, JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        $normalize = static fn (string $html): string => trim(str_replace(['> ', ' <'], ['>', '<'], (string)preg_replace('/\s+/', ' ', (string)preg_replace('/>\s+</', '><', $html))));
        foreach ($cases as $case) {
            self::assertSame($normalize($case['html']), $normalize($converter->convert($case['source'])), $case['source']);
            self::assertSame($normalize($case['html']), $normalize($converter->convert($converter->toCarve($case['source']))), $case['source']);
        }
    }
}
