<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
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

    /**
     * The label as TYPED, read back off a parsed tree.
     *
     * Escapes come back as `EscapedText` and typography as a node carrying both
     * halves, so taking the source run rather than the glyph is what makes this
     * a comparison against the input instead of against a presentation choice
     * made in between.
     */
    private function typedText(Node $node): string
    {
        $out = '';
        foreach ($node->getChildren() as $child) {
            $out .= match (true) {
                $child instanceof Text, $child instanceof EscapedText => $child->getContent(),
                $child instanceof SmartPunctuation => $child->getContent(),
                default => $this->typedText($child),
            };
        }

        return $out;
    }

    private function firstLink(Node $node): ?Link
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Link) {
                return $child;
            }
            $found = $this->firstLink($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function write(Node $inline): string
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild($inline);
        $document->appendChild($paragraph);

        return trim(CarveConverter::carve()->render($document));
    }

    /**
     * A trailing `{.x}` after a mention stays literal text - the parser leaves
     * it outside the node - so a mention carrying attributes has no short form
     * either, however spellable its name is. They were dropped, not deleted,
     * which is why the name test alone did not catch it.
     */
    public function testAnAttributeIsCarriedRatherThanDropped(): void
    {
        $mention = new Mention('mention', '/u/1', '@user');
        $mention->setAttribute('id', 'x');

        $written = $this->write($mention);

        $link = $this->firstLink((new CarveConverter())->parse($written));
        $this->assertNotNull($link, "no link parsed back out of: $written");
        $this->assertSame('x', $link->getAttribute('id'));
        $this->assertSame('mention', $link->getAttribute('class'));
        $this->assertSame('@user', $this->typedText($link));
    }

    /**
     * `@*user*` is not a mention, so a mention whose label carries markup has no
     * short form: writing `@user` dropped the emphasis and reported nothing.
     */
    public function testNestedMarkupIsCarriedRatherThanFlattened(): void
    {
        $mention = new Mention('mention', '/u/1', '');
        $mention->removeChild($mention->getChildren()[0]);
        $strong = new Strong();
        $strong->appendChild(new Text('user'));
        $mention->appendChild($strong);

        $written = $this->write($mention);

        $this->assertStringContainsString('*user*', $written);
        $link = $this->firstLink((new CarveConverter())->parse($written));
        $this->assertNotNull($link, "no link parsed back out of: $written");
        $this->assertSame('/u/1', $link->getDestination());
        $this->assertInstanceOf(Strong::class, $link->getChildren()[0] ?? null);
    }

    /**
     * One sigil, not a run of them: `ltrim($label, '@')` read `@@user` as the
     * name `user`, writing back one fewer than it was handed.
     */
    public function testADoubledSigilIsNotEaten(): void
    {
        $written = $this->write(new Mention('mention', '/u/1', '@@user'));

        $link = $this->firstLink((new CarveConverter())->parse($written));
        $this->assertNotNull($link, "no link parsed back out of: $written");
        $this->assertSame('@@user', $this->typedText($link));
    }

    /**
     * The renderer is handed a tree it does not own.
     *
     * Building the fallback link by appending the mention's children REPARENTS
     * them, so writing a document left every label child pointing at a throwaway
     * node. Nothing in the output changes, which is why only the tree can show
     * it.
     */
    public function testWritingDoesNotReparentTheLabel(): void
    {
        $mention = new Mention('mention', '/u/1', "o'brien");
        $child = $mention->getChildren()[0];

        $this->write($mention);

        $this->assertSame($mention, $child->getParent());
    }

    /**
     * A bare `@name` has nowhere to hang an attribute - the parser leaves a
     * trailing `{.x}` outside the node - and the link form is unavailable with
     * no destination, so the writer used to emit the bare spelling and drop the
     * attribute without a word (carve-php#567).
     *
     * The bracketed form keeps it. The cost is an extra wrapper `<span>` on
     * re-parse, because `[@alice]{#x}` is a span AROUND a mention rather than a
     * mention carrying the attribute: losing nothing is worth more than an exact
     * HTML match for a state the parser cannot produce in the first place.
     */
    public function testAnAttributeOnADestinationlessMentionIsNotDropped(): void
    {
        $mention = new Mention('mention', '', '@alice');
        $mention->setAttribute('id', 'x');

        $written = $this->write($mention);

        $this->assertStringContainsString('[@alice]{#x}', $written);

        // Both survive the round trip: the id on the wrapper, the mention inside.
        $html = (new CarveConverter())->render((new CarveConverter())->parse($written));
        $this->assertStringContainsString('id="x"', $html);
        $this->assertStringContainsString('class="mention"', $html);
    }

    /**
     * The attribute case must not swallow the ordinary one: an unattributed
     * bare mention still writes as `@alice`, not as a bracketed span.
     */
    public function testADestinationlessMentionWithoutAttributesStaysBare(): void
    {
        $this->assertStringContainsString('@alice', $this->write(new Mention('mention', '', '@alice')));
        $this->assertStringNotContainsString('[', $this->write(new Mention('mention', '', '@alice')));
    }
}
