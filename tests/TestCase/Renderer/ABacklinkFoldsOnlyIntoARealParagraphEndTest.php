<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The endnote backlink folds into the body only when the body ENDS in a paragraph
 * (carve-php#2680). Keying that on a trailing `</p>` in the rendered string instead
 * is satisfied by any trailing block whose HTML carries no text, which put the
 * backlink inside the paragraph above it.
 *
 * What separates the two lists is whether the trailing block REACHES THE OUTPUT
 * (markup-carve/carve#2570). An empty raw block whose format this target matches emits
 * the line it occupies, so it holds a slot and the paragraph above it is not the body's
 * end. A comment and a raw block whose format the target DROPS emit nothing at all, so
 * they cannot end the body and the backlink still folds.
 *
 * The rows vary the CONDITION rather than one spelling: the emptiness comes from an
 * empty raw body, from a payload written below its own column, and from a format this
 * target drops.
 *
 * Expectations measured against scripts/spec/layout.mjs with scripts/spec/html.mjs in
 * markup-carve/carve at 5b70a768. The oracle also writes an empty slot line for a body
 * whose every block renders nothing; that spacing is tracked separately and is not
 * asserted here.
 */
class ABacklinkFoldsOnlyIntoARealParagraphEndTest extends TestCase
{
    /**
     * @var string
     */
    protected const BACKLINK = '<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a>';

    /**
     * @return iterable<string, array<string>>
     */
    public static function bodiesThatDoNotEndInAParagraph(): iterable
    {
        yield 'an empty raw html block' => ["```=html\n```"];
        yield 'an empty tilde raw html block' => ["~~~=html\n~~~"];
        yield 'a raw block payload below its own column' => ["```=html\nZ<b>a</b>\n```"];
        yield 'a comment above an empty raw html block' => ["%%%\nh\n%%%\n\n```=html\n```"];
        yield 'an empty raw html block above a comment' => ["```=html\n```\n\n%%%\nh\n%%%"];
        yield 'a code block' => ["```\nz\n```"];
        yield 'a block quote' => ['>'];
    }

    /**
     * @return iterable<string, array<string>>
     */
    public static function bodiesThatEndInAParagraph(): iterable
    {
        yield 'a second paragraph' => ['b'];
        yield 'a trailing comment' => ["%%%\nh\n%%%"];
        yield 'two trailing comments' => ["%%%\nh\n%%%\n\n%%%\ni\n%%%"];
        yield 'a comment above a paragraph' => ["%%%\nh\n%%%\n\nb"];

        // Nothing of a dropped raw block reaches the output, so it holds no slot
        // and the paragraph above it is still the body's end (carve-php#2711).
        yield 'a raw block whose format this target drops' => ["```=latex\n\\x\n```"];
        yield 'a dropped raw block above a comment' => ["```=latex\n\\x\n```\n\n%%%\nh\n%%%"];
        yield 'a comment above a dropped raw block' => ["%%%\nh\n%%%\n\n```=latex\n\\x\n```"];
    }

    #[DataProvider('bodiesThatDoNotEndInAParagraph')]
    public function testTheBacklinkTakesItsOwnParagraph(string $tail): void
    {
        $html = $this->endnote($tail);

        $this->assertStringContainsString('      <p>' . static::BACKLINK . "</p>\n", $html);
        $this->assertStringContainsString("<p>a</p>\n", $html);
        $this->assertStringNotContainsString('<p>a' . static::BACKLINK, $html);
    }

    #[DataProvider('bodiesThatEndInAParagraph')]
    public function testTheBacklinkStaysInsideTheClosingParagraph(string $tail): void
    {
        $html = $this->endnote($tail);

        $this->assertStringNotContainsString('<p>' . static::BACKLINK . '</p>', $html);
        $this->assertMatchesRegularExpression(
            '/<p>[ab]' . preg_quote(static::BACKLINK, '/') . '<\/p>/',
            $html,
        );
    }

    /**
     * An inline footnote carries no block children, so its body is whatever the
     * registered renderer returned and the fold still keys on the string alone.
     *
     * @return void
     */
    public function testAnInlineFootnoteStillFolds(): void
    {
        $html = (new CarveConverter())->convert("Text^[inline body]\n");

        $this->assertStringContainsString('<p>inline body' . static::BACKLINK . '</p>', $html);
    }

    /**
     * Renders the endnote item for a note whose body is `a` followed by $tail.
     *
     * A `Z` at the head of a tail line writes that line at column 1 instead of the
     * note's own content column, which is how the ticket's sample spells a payload
     * sitting below its block.
     *
     * @param string $tail
     *
     * @return string
     */
    protected function endnote(string $tail): string
    {
        $body = '';
        foreach (explode("\n", $tail) as $line) {
            if (str_starts_with($line, 'Z')) {
                $body .= ' ' . substr($line, 1) . "\n";

                continue;
            }
            $body .= $line === '' ? "\n" : '    ' . $line . "\n";
        }

        return (new CarveConverter())->convert("[^1]: a\n\n" . $body . "\n[^1]\n");
    }
}
