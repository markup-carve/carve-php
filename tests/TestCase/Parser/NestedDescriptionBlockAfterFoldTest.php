<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NestedDescriptionBlockAfterFoldTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function hosts(): array
    {
        return [
            'description host' => [":: s\n: :::\n  :: t\n  : t\n  x\n    # u\n"],
            'list host' => ["- :::\n  :: t\n  : t\n  x\n    # u\n"],
        ];
    }

    #[DataProvider('hosts')]
    public function testInterruptingBlockAtNestedBodyColumnStaysInDescription(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        $this->assertStringContainsString("<dd>\n          <p>t\nx</p>\n          <h1 id=\"u\">u</h1>\n        </dd>", $html);
    }
}
