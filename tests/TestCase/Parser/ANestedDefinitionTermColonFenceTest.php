<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ANestedDefinitionTermColonFenceTest extends TestCase
{
    public function testAColonFenceAtTheNestedTermsContentColumnStartsABlock(): void
    {
        $html = (new CarveConverter())->convert(":: a\n: b\n  :: c\n    :::\n");

        self::assertStringContainsString('<dt>c</dt>', $html);
        self::assertStringNotContainsString('<dt>c :::</dt>', $html);
        self::assertStringContainsString('<div>', $html);
    }
}
