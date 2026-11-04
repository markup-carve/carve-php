<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ADeepestQuotedParagraphKeepsLazyLineTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function missedQuotePrefixes(): array
    {
        return [
            'two quote levels' => [
                "> > - > +\n> > - > +\n>  s\n",
                "  </blockquote>\n  <p>s</p>\n</blockquote>",
            ],
            'sibling list type and lazy list marker' => [
                "> > 1. > +\n> > * > +\n>  - sibling\n",
                "  </blockquote>\n  <ul>\n    <li>sibling</li>\n  </ul>\n</blockquote>",
            ],
            'list marker at the ancestor quote column' => [
                "> > - > +\n> > - > +\n> - sibling\n",
                "  </blockquote>\n  <ul>\n    <li>sibling</li>\n  </ul>\n</blockquote>",
            ],
            'three quote levels and an inline link' => [
                "> > > - > +\n> > > 1. > +\n> >  some [link](x)\n",
                "    </blockquote>\n    <p>some <a href=\"x\">link</a></p>\n  </blockquote>\n</blockquote>",
            ],
        ];
    }

    #[DataProvider('missedQuotePrefixes')]
    public function testDeepestParagraphTakesTheLazyLine(string $source, string $expected): void
    {
        $html = CarveConverter::create()->convert($source);

        // The refused markers render nothing (carve#5013), so the line is read in the quote it reaches.
        self::assertStringEndsWith($expected, trim($html));
        self::assertStringNotContainsString('+', $html);
    }

    public function testProseAtTheAncestorQuoteColumnIsAnAttachedBlock(): void
    {
        $html = CarveConverter::create()->convert("> > - > +\n> > - > +\n> s\n");

        self::assertStringEndsWith("  </blockquote>\n  <p>s</p>\n</blockquote>", trim($html));
        self::assertStringNotContainsString('<blockquote><p>s</p></blockquote>', $html);
    }
}
