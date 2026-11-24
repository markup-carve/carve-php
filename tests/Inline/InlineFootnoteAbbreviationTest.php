<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6626, C2-I-098, C2-I-109: an inline footnote is a flow of its own, so the text around it is no word edge
 * for a term inside it, and its text none for a term after it.
 */
final class InlineFootnoteAbbreviationTest extends TestCase
{
    /**
     * @var string
     */
    private const ABBR = '<abbr title="HyperText">HTML</abbr>';

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function sources(): array
    {
        return [
            'term first in the note' => ['See^[HTML is nice].', 'See', self::ABBR . ' is nice'],
            'term in emphasis first in the note' => ['See^[*HTML* is].', 'See', '<strong>' . self::ABBR . '</strong> is'],
            'term after the note' => ['x^[aHTML]HTML', 'x', 'aHTML'],
            'term glued to a word inside the note' => ['aHTML^[HTMLb]', 'aHTML', 'HTMLb'],
        ];
    }

    #[DataProvider('sources')]
    public function testTheNoteIsAFlowOfItsOwn(string $line, string $before, string $note): void
    {
        $html = Carve::toHtml("*[HTML]: HyperText\n\n" . $line . "\n");

        $this->assertStringContainsString('<p>' . $before . '<a id="fnref1"', $html);
        $this->assertStringContainsString('<p>' . $note . '<a href="#fnref1"', $html);
    }

    public function testATermRightAfterTheNoteIsMarked(): void
    {
        $this->assertStringContainsString(
            '<sup>1</sup></a>' . self::ABBR . '</p>',
            Carve::toHtml("*[HTML]: HyperText\n\nx^[aHTML]HTML\n"),
        );
    }
}
