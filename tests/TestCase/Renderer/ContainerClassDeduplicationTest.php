<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContainerClassDeduplicationTest extends TestCase
{
    /**
     * @return array<array{string, string}>
     */
    public static function cases(): array
    {
        return [
            ["::: foo\nx\n:::\n", "<div class=\"foo\">\n  <p>x</p>\n</div>\n"],
            ["{.foo}\n::: foo\nx\n:::\n", "<div class=\"foo foo\">\n  <p>x</p>\n</div>\n"],
            ["{class=foo}\n::: foo\nx\n:::\n", "<div class=\"foo foo\">\n  <p>x</p>\n</div>\n"],
            ["{.foo .foo}\n::: foo\nx\n:::\n", "<div class=\"foo foo\">\n  <p>x</p>\n</div>\n"],
            ["{#id .other .foo}\n::: foo\nx\n:::\n", "<div class=\"foo other foo\" id=\"id\">\n  <p>x</p>\n</div>\n"],
            ["{class=\"foo x\" .foo}\n::: foo\nx\n:::\n", "<div class=\"foo foo x foo\">\n  <p>x</p>\n</div>\n"],
            ["{class=\"javascript:alert(1)\" .foo}\n::: foo\nx\n:::\n", "<div class=\"foo foo\">\n  <p>x</p>\n</div>\n"],
            ["::: note\nx\n:::\n", "<aside class=\"admonition note\" aria-label=\"Note\">\n  <p>x</p>\n</aside>\n"],
            ["{.note}\n::: note\nx\n:::\n", "<aside class=\"admonition note note\" aria-label=\"Note\">\n  <p>x</p>\n</aside>\n"],
            ["{class=note}\n::: note\nx\n:::\n", "<aside class=\"admonition note note\" aria-label=\"Note\">\n  <p>x</p>\n</aside>\n"],
            ["{.note .note}\n::: note\nx\n:::\n", "<aside class=\"admonition note note\" aria-label=\"Note\">\n  <p>x</p>\n</aside>\n"],
            ["{#id .other .note}\n::: note\nx\n:::\n", "<aside class=\"admonition note other note\" id=\"id\" aria-label=\"Note\">\n  <p>x</p>\n</aside>\n"],
            ["{class=\"note x\" .note}\n::: note\nx\n:::\n", "<aside class=\"admonition note note x note\" aria-label=\"Note\">\n  <p>x</p>\n</aside>\n"],
            ["{class=\"javascript:alert(1)\" .note}\n::: note\nx\n:::\n", "<aside class=\"admonition note note\" aria-label=\"Note\">\n  <p>x</p>\n</aside>\n"],
            ["::: |\nx\n:::\n", "<div class=\"line-block\">\n  <p>x</p>\n</div>\n"],
            ["{.line-block}\n::: |\nx\n:::\n", "<div class=\"line-block\">\n  <p>x</p>\n</div>\n"],
            ["{class=line-block}\n::: |\nx\n:::\n", "<div class=\"line-block\">\n  <p>x</p>\n</div>\n"],
            ["{.line-block .line-block}\n::: |\nx\n:::\n", "<div class=\"line-block\">\n  <p>x</p>\n</div>\n"],
            ["{#id .other .line-block}\n::: |\nx\n:::\n", "<div id=\"id\" class=\"other line-block\">\n  <p>x</p>\n</div>\n"],
            ["{class=\"line-block x\" .line-block}\n::: |\nx\n:::\n", "<div class=\"line-block x line-block\">\n  <p>x</p>\n</div>\n"],
            ["{class=\"javascript:alert(1)\" .line-block}\n::: |\nx\n:::\n", "<div class=\"line-block\">\n  <p>x</p>\n</div>\n"],
            ["::: \\\nx\n:::\n", "<div class=\"hardbreaks\">\n  <p>x</p>\n</div>\n"],
            ["{.hardbreaks}\n::: \\\nx\n:::\n", "<div class=\"hardbreaks\">\n  <p>x</p>\n</div>\n"],
            ["{class=hardbreaks}\n::: \\\nx\n:::\n", "<div class=\"hardbreaks\">\n  <p>x</p>\n</div>\n"],
            ["{.hardbreaks .hardbreaks}\n::: \\\nx\n:::\n", "<div class=\"hardbreaks\">\n  <p>x</p>\n</div>\n"],
            ["{#id .other .hardbreaks}\n::: \\\nx\n:::\n", "<div id=\"id\" class=\"other hardbreaks\">\n  <p>x</p>\n</div>\n"],
            ["{class=\"hardbreaks x\" .hardbreaks}\n::: \\\nx\n:::\n", "<div class=\"hardbreaks x hardbreaks\">\n  <p>x</p>\n</div>\n"],
            ["{class=\"javascript:alert(1)\" .hardbreaks}\n::: \\\nx\n:::\n", "<div class=\"hardbreaks\">\n  <p>x</p>\n</div>\n"],
            ["::: figure\nx\n:::\n", "<figure class=\"carve-figure-group\">\n  <p>x</p>\n</figure>\n"],
            ["{.carve-figure-group}\n::: figure\nx\n:::\n", "<figure class=\"carve-figure-group\">\n  <p>x</p>\n</figure>\n"],
            ["{class=carve-figure-group}\n::: figure\nx\n:::\n", "<figure class=\"carve-figure-group\">\n  <p>x</p>\n</figure>\n"],
            ["{.carve-figure-group .carve-figure-group}\n::: figure\nx\n:::\n", "<figure class=\"carve-figure-group\">\n  <p>x</p>\n</figure>\n"],
            ["{#id .other .carve-figure-group}\n::: figure\nx\n:::\n", "<figure class=\"carve-figure-group other\" id=\"id\">\n  <p>x</p>\n</figure>\n"],
            ["{class=\"carve-figure-group x\" .carve-figure-group}\n::: figure\nx\n:::\n", "<figure class=\"carve-figure-group carve-figure-group x\">\n  <p>x</p>\n</figure>\n"],
            ["{class=\"javascript:alert(1)\" .carve-figure-group}\n::: figure\nx\n:::\n", "<figure class=\"carve-figure-group\">\n  <p>x</p>\n</figure>\n"],
        ];
    }

    #[DataProvider('cases')]
    public function testStructuralClassPool(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
