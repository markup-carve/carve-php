<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

final class DjotMultilineCommentBoundariesTest extends TestCase
{
    public function testInvalidBlockCommentsKeepTheirTextAndHeadingOwnership(): void
    {
        foreach (["{% x\ny %}\n", "# a\n{% x\ny %}\n# b\n", "> {% x\n> y %}\n", "- {% x\n  y %}\n"] as $source) {
            $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));
            self::assertStringContainsString('x', $html);
            self::assertStringContainsString('y', $html);
            self::assertStringNotContainsString('<h1>b</h1>', $html);
            if (str_starts_with($source, '#')) {
                self::assertStringContainsString('<h1>a</h1>', $html);
            }
        }
    }

    public function testAnUnindentedRejectedCommentEndsTheList(): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert("- a\n{% x\ny %}\n"));
        self::assertStringContainsString('<li>a</li>', $html);
        self::assertStringContainsString("<p>{% x\ny %}</p>", $html);
    }

    public function testACommentInAFootnoteBodyIsInline(): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert("a[^n]\n\n[^n]: text\n  {% x\n  y %} z\n"));
        self::assertStringNotContainsString('{% x', $html);
        self::assertStringContainsString('text', $html);
        self::assertStringContainsString('z', $html);
    }

    public function testValidCommentsRemainConsumed(): void
    {
        foreach (["- a\n {% x\n y %}\n", "para\n# not a heading\n{% x\ny %}\n", "para\n- not an item\n{% x\ny %}\n", "p\n\na\n{% x\ny %} z\n", "- a\n\n  b\n  {% x\n  y %} z\n", "> p\n>\n> a\n> {% x\n> y %} z\n", "Hi. there\n{% x\ny %} z\n", "a\n{% x\ny %} z\n", "- a\n  {% x\n  y %} z\n", "{% x %}\n", "{% x\n  y %}\n"] as $source) {
            self::assertStringNotContainsString('x', (new DjotToCarve())->convert($source));
        }
        self::assertSame('<p>a  z</p>', trim((new CarveConverter())->convert((new DjotToCarve())->convert("a {% x\ny %} z\n"))));
    }
}
