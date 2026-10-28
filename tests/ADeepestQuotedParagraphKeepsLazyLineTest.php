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
                "+\ns",
            ],
            'sibling list type and lazy list marker' => [
                "> > 1. > +\n> > * > +\n>  - sibling\n",
                "+\n- sibling",
            ],
            'list marker at the ancestor quote column' => [
                "> > - > +\n> > - > +\n> - sibling\n",
                "+\n- sibling",
            ],
            'three quote levels and an inline link' => [
                "> > > - > +\n> > > 1. > +\n> >  some [link](x)\n",
                "+\nsome <a href=\"x\">link</a>",
            ],
        ];
    }

    #[DataProvider('missedQuotePrefixes')]
    public function testDeepestParagraphTakesTheLazyLine(string $source, string $expected): void
    {
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<blockquote><p>$expected</p></blockquote>", $html);
    }

    public function testProseAtTheAncestorQuoteColumnIsAnAttachedBlock(): void
    {
        $html = CarveConverter::create()->convert("> > - > +\n> > - > +\n> s\n");

        self::assertStringContainsString('<blockquote><p>s</p></blockquote>', $html);
        self::assertStringNotContainsString("<blockquote><p>+\ns</p></blockquote>", $html);
    }
}
