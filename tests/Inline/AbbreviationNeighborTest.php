<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6683, C2-I-098, C2-I-099: the character after a term is the next one the flow shows, read
 * through literal text that a brace run leaves and through braced comments.
 */
final class AbbreviationNeighborTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        $abbr = '<abbr title="Hyper">HTML</abbr>';

        return [
            'unattached brace run, emphasis later' => ['HTML{.c} and *b*', '<p>' . $abbr . '{.c} and <strong>b</strong></p>'],
            'unattached id block, slash emphasis later' => ['HTML{#t} and /b/', '<p>' . $abbr . '{#t} and <em>b</em></p>'],
            'unattached brace run, link later' => ['HTML{.c} [l](u)', '<p>' . $abbr . '{.c} <a href="u">l</a></p>'],
            'unattached brace run, term later' => ['HTML{.c}, *HTML*', '<p>' . $abbr . '{.c}, <strong>' . $abbr . '</strong></p>'],
            'comment before a word' => ['HTML{% c %}x and *b*', '<p>HTMLx and <strong>b</strong></p>'],
            'comment before a word after emphasis' => ['*HTML*{% c %}[l](u)', '<p><strong>HTML</strong><a href="u">l</a></p>'],
            'comment before a space' => ['HTML{% c %} and *b*', '<p>' . $abbr . ' and <strong>b</strong></p>'],
        ];
    }

    #[DataProvider('sources')]
    public function testTheNextShownCharacterBoundsTheTerm(string $line, string $expected): void
    {
        $this->assertSame($expected, Carve::toHtml("*[HTML]: Hyper\n\n" . $line . "\n"));
    }
}
