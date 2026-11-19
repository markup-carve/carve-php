<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\OptionError;
use PHPUnit\Framework\TestCase;

final class FormatOptionsTest extends TestCase
{
    public function testFormatRefusesAnOptionFromAnotherCall(): void
    {
        foreach (['format', 'formatWithDiagnostics'] as $call) {
            try {
                Carve::$call("a\n", ['profile' => 'full']);
                $this->fail("{$call} accepted a render option");
            } catch (OptionError $error) {
                $this->assertStringContainsString('profile', $error->getMessage());
            }
        }
    }

    public function testFormatStillReadsItsOwnOptions(): void
    {
        $this->assertSame("a\n", Carve::format("a\n", ['alignTables' => false, 'normalize' => false]));
    }
}
