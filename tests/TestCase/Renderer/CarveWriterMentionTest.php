<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Mention;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The canonical writer must not invent a mention name.
 *
 * `escapeName()` was named escape and DELETED: every character outside
 * `[\w.-]` was dropped, so a label of `o'brien` was written as `@obrien` - a
 * different mention, pointing at a different user, with nothing reported. A
 * mention name has no escape syntax, so the honest move is to stop using the
 * mention spelling when the label does not fit it.
 */
class CarveWriterMentionTest extends TestCase
{
    private function mention(string $label, string $destination = '/u/1'): string
    {
        // The constructor already hangs the label on as the text child.
        $node = new Mention('mention', $destination, $label);

        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild($node);
        $document->appendChild($paragraph);

        return trim(CarveConverter::carve()->render($document));
    }

    /**
     * @param string $label
     * @param string $expected
     */
    #[DataProvider('spellableProvider')]
    public function testASpellableLabelStaysAMention(string $label, string $expected): void
    {
        $this->assertSame($expected, $this->mention($label));
    }

    public static function spellableProvider(): array
    {
        return [
            'plain' => ['markus', '@markus'],
            'interior dot' => ['john.doe', '@john.doe'],
            'hyphen and dot' => ['release-1.0', '@release-1.0'],
            'underscore' => ['a_b', '@a_b'],
            'digits' => ['user42', '@user42'],
            'already sigilled' => ['@markus', '@markus'],
            'tag' => ['#release', '#release'],
        ];
    }

    /**
     * The label survives verbatim, and so does the destination and the class -
     * the anchor is the same one, spelled with the syntax that can hold it.
     *
     * @param string $label
     */
    #[DataProvider('unspellableProvider')]
    public function testAnUnspellableLabelBecomesALinkInsteadOfLosingCharacters(string $label): void
    {
        $written = $this->mention($label);

        $this->assertStringStartsWith('[', $written);
        $this->assertStringContainsString('](/u/1){.mention}', $written);
        // Whatever escaping the label needs, no character is DELETED.
        $this->assertSame($label, str_replace('\\', '', substr($written, 1, (int)strpos($written, '](') - 1)));
    }

    public static function unspellableProvider(): array
    {
        return [
            'apostrophe' => ["o'brien"],
            'space' => ['Mark Scherer'],
            'plus' => ['user+tag'],
            'slash' => ['a/b'],
            'leading dot' => ['.lead'],
            'trailing dot' => ['trail.'],
            // The parser's name rule is ASCII, so a non-ASCII letter has to
            // take the link form too: `@Jörg` would re-read as `@J` plus text.
            'non-ascii' => ['Jörg'],
        ];
    }

    /**
     * A name this writer emits has to be one this engine's own parser reads
     * back as the same mention - the property the deletion broke.
     */
    public function testAnEmittedNameSurvivesItsOwnParser(): void
    {
        foreach (['markus', 'john.doe', 'release-1.0'] as $label) {
            $written = $this->mention($label);
            $this->assertSame('@' . $label, $written);
        }

        // And the ones that cannot: no `@name` is emitted at all.
        foreach (["o'brien", 'Jörg', 'Mark Scherer'] as $label) {
            $this->assertStringStartsNotWith('@', $this->mention($label));
        }
    }

    /**
     * A mention with no destination was already written as plain text; that
     * path is unchanged, since there is no link to degrade to.
     */
    public function testAMentionWithoutADestinationStaysPlainText(): void
    {
        $this->assertSame("o'brien", $this->mention("o'brien", ''));
    }
}
