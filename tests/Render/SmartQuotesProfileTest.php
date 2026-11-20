<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\SmartQuotes;
use PHPUnit\Framework\TestCase;

final class SmartQuotesProfileTest extends TestCase
{
    public function testLocaleQuotesFollowAllowedProfiles(): void
    {
        $source = "He said \"hi\" and 'bye'.\n";
        $extension = SmartQuotes::bundle('de');

        foreach (['full', 'article'] as $profile) {
            self::assertSame(
                '<p>He said „hi“ and ‚bye‘.</p>',
                Carve::render($source, ['profile' => $profile, 'extensions' => [$extension]]),
                $profile,
            );
        }
        foreach (['comment', 'minimal'] as $profile) {
            self::assertSame(
                '<p>He said “hi” and ‘bye’.</p>',
                Carve::render($source, ['profile' => $profile, 'extensions' => [$extension]]),
                $profile,
            );
        }
        self::assertSame(
            '<p>He said "hi" and \'bye\'.</p>',
            Carve::render($source, ['profile' => 'article', 'extensions' => [$extension], 'smartTypography' => false]),
        );
    }

    public function testLocaleQuotesInNestedAllowedNodes(): void
    {
        $extension = SmartQuotes::bundle('fr');
        foreach (['"x"', '*"x"*', '["x"](/x)', '> "x"'] as $source) {
            self::assertSame(
                Carve::render($source, ['extensions' => [$extension]]),
                Carve::render($source, ['profile' => 'article', 'extensions' => [$extension]]),
                $source,
            );
        }
    }
}
