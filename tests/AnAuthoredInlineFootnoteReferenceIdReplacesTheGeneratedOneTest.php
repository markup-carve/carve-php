<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AnAuthoredInlineFootnoteReferenceIdReplacesTheGeneratedOneTest extends TestCase
{
    public function testTheAuthoredIdControlsTheReferenceAndBacklink(): void
    {
        foreach (['^[n]{#x}' => 'x', 'before ^[second]{#note-ref} after' => 'note-ref'] as $source => $id) {
            $html = (new CarveConverter())->convert($source);
            self::assertStringContainsString('<a id="' . $id . '" href="#fn1" role="doc-noteref">', $html);
            self::assertStringContainsString('<a href="#' . $id . '" role="doc-backlink"', $html);
        }
    }

    public function testTheAuthoredIdWorksInsideALink(): void
    {
        $html = (new CarveConverter())->convert('[text ^[n]{#inside}](/u)');
        self::assertStringContainsString('<sup id="inside">1</sup>', $html);
        self::assertStringContainsString('<a href="#inside" role="doc-backlink"', $html);
    }
}
