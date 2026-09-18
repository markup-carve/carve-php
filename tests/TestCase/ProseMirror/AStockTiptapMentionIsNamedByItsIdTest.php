<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\ContentNodeInterface;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tiptap 3.29.2's mention node always sends `id`, `label` (default `null`) and
 * `mentionSuggestionChar` (default `@`, set by the suggestion command). The
 * bridge names the mention by `id`, falls back to `label` when `id` is missing,
 * and never writes the two editor attributes as Carve attributes
 * (markup-carve/carve-php#2154).
 */
class AStockTiptapMentionIsNamedByItsIdTest extends TestCase
{
    /**
     * @var string
     */
    private const LABEL_DROPPED = 'the mention name is its id, so a different display label is not carried';

    /**
     * @var string
     */
    private const MENTION_AS_TEXT = 'the name is not a Carve mention name, so the mention is written as text';

    /**
     * @var string
     */
    private const TAG_AS_TEXT = 'the name is not a Carve tag name, so the tag is written as text';

    /**
     * @var string
     */
    private const ATTRIBUTE_AS_TEXT = 'the mention is written as text, which holds no attribute';

    /**
     * @return array<string, array{string, array<string, mixed>, string, string, array<string, string>}>
     */
    public static function stockShapes(): array
    {
        return [
            'id with a null label' => ['mention', ['id' => 'alice', 'label' => null, 'mentionSuggestionChar' => '@'], "ping @alice\n", '@alice', []],
            'id and an equal label' => ['mention', ['id' => 'alice', 'label' => 'alice', 'mentionSuggestionChar' => '@'], "ping @alice\n", '@alice', []],
            'id and a different label' => ['mention', ['id' => 'u123', 'label' => 'Alice', 'mentionSuggestionChar' => '@'], "ping @u123\n", '@u123', ['label' => self::LABEL_DROPPED]],
            'a null id and a label' => ['mention', ['id' => null, 'label' => 'Alice', 'mentionSuggestionChar' => '@'], "ping @Alice\n", '@Alice', []],
            'id alone' => ['mention', ['id' => 'alice'], "ping @alice\n", '@alice', []],
            'label alone' => ['mention', ['label' => 'Alice'], "ping @Alice\n", '@Alice', []],
            'an id that carries its sigil' => ['mention', ['id' => '@alice', 'label' => null], "ping @alice\n", '@alice', []],
            'a dotted id' => ['mention', ['id' => 'john.doe', 'label' => null], "ping @john.doe\n", '@john.doe', []],
            'a tag with a null label' => ['carveTag', ['id' => 'release', 'label' => null, 'mentionSuggestionChar' => '#'], "ping #release\n", '#release', []],
            'a tag with a different label' => ['carveTag', ['id' => 'release', 'label' => 'Release', 'mentionSuggestionChar' => '#'], "ping #release\n", '#release', ['label' => self::LABEL_DROPPED]],
        ];
    }

    /**
     * @param string $type
     * @param array<string, mixed> $attrs
     * @param string $carve
     * @param string $name
     * @param array<string, string> $dropped
     */
    #[DataProvider('stockShapes')]
    public function testTheMentionWritesItsNameAndReadsBack(string $type, array $attrs, string $carve, string $name, array $dropped): void
    {
        $converter = new ProseMirrorToCarve();
        $document = $converter->convert(self::paragraph($type, $attrs));

        $written = CarveConverter::carve()->render($document);
        $this->assertSame($carve, $written);
        $this->assertSame($dropped, $converter->droppedAttributes());

        $mention = self::firstMention(CarveConverter::carve()->parse($written));
        $this->assertInstanceOf(Mention::class, $mention);
        $this->assertSame($name, $mention->getChildren()[0]->getContent());
        $this->assertSame([], $mention->getAttributes());
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string, string, array<string, string>}>
     */
    public static function unspellableNames(): array
    {
        return [
            'the Tiptap docs example' => ['mention', ['id' => 'Lea Thompson', 'label' => null], "ping \\@Lea Thompson\n", 'ping @Lea Thompson', ['id' => self::MENTION_AS_TEXT]],
            'the docs example with the suggestion char' => ['mention', ['id' => 'Lea Thompson', 'label' => null, 'mentionSuggestionChar' => '@'], "ping \\@Lea Thompson\n", 'ping @Lea Thompson', ['id' => self::MENTION_AS_TEXT]],
            'a spaced label with no id' => ['mention', ['id' => null, 'label' => 'Lea Thompson'], "ping \\@Lea Thompson\n", 'ping @Lea Thompson', ['label' => self::MENTION_AS_TEXT]],
            'an apostrophe' => ['mention', ['id' => "o'brien", 'label' => null], "ping \\@o\\'brien\n", "ping @o'brien", ['id' => self::MENTION_AS_TEXT]],
            'a trailing dot' => ['mention', ['id' => 'a.', 'label' => null], "ping \\@a.\n", 'ping @a.', ['id' => self::MENTION_AS_TEXT]],
            'a doubled dot' => ['mention', ['id' => 'a..b', 'label' => null], "ping \\@a..b\n", 'ping @a..b', ['id' => self::MENTION_AS_TEXT]],
            'a non-ASCII letter' => ['mention', ['id' => 'Zoë', 'label' => null], "ping \\@Zoë\n", 'ping @Zoë', ['id' => self::MENTION_AS_TEXT]],
            'an id that cannot be spelled beside a label' => ['mention', ['id' => 'u 1', 'label' => 'Lea'], "ping \\@Lea\n", 'ping @Lea', ['id' => self::MENTION_AS_TEXT]],
            'a tag name with a space' => ['carveTag', ['id' => 'big release', 'label' => null, 'mentionSuggestionChar' => '#'], "ping \\#big release\n", 'ping #big release', ['id' => self::TAG_AS_TEXT]],
        ];
    }

    /**
     * @param string $type
     * @param array<string, mixed> $attrs
     * @param string $carve
     * @param string $text
     * @param array<string, string> $dropped
     */
    #[DataProvider('unspellableNames')]
    public function testANameTheGrammarRejectsIsWrittenAsText(string $type, array $attrs, string $carve, string $text, array $dropped): void
    {
        $converter = new ProseMirrorToCarve();
        $document = $converter->convert(self::paragraph($type, $attrs));

        $written = CarveConverter::carve()->render($document);
        $this->assertSame($carve, $written);
        $this->assertSame($dropped, $converter->droppedAttributes());

        $parsed = CarveConverter::carve()->parse($written);
        $this->assertNull(self::firstMention($parsed));
        $this->assertSame($text, self::plainText($parsed));
    }

    public function testAnUnspellableIdBesideALabelThatIsNotTextReportsBoth(): void
    {
        $converter = new ProseMirrorToCarve();
        $document = $converter->convert(self::paragraph('mention', ['id' => 'Lea Thompson', 'label' => ['Lea']]));

        $this->assertSame("ping \\@Lea Thompson\n", CarveConverter::carve()->render($document));
        $this->assertSame(
            ['id' => self::MENTION_AS_TEXT, 'label' => 'a Carve attribute holds a string, and this value is of type array'],
            $converter->droppedAttributes(),
        );
    }

    public function testAMentionWithADestinationStaysAMention(): void
    {
        $converter = new ProseMirrorToCarve();
        $document = $converter->convert(self::paragraph('mention', ['id' => 'Lea Thompson', 'label' => null, 'href' => '/u/lea']));

        $this->assertInstanceOf(Mention::class, self::firstMention($document));
        $this->assertSame([], $converter->droppedAttributes());
    }

    public function testAMentionWithARealAttributeStillHasNoSpelling(): void
    {
        $document = (new ProseMirrorToCarve())->convert(
            self::paragraph('mention', ['id' => 'alice', 'label' => null, 'mentionSuggestionChar' => '@', 'data-team' => 'core']),
        );

        $this->expectException(SourceUnspellableException::class);
        CarveConverter::carve()->render($document);
    }

    public function testALabelThatIsNotTextIsReportedAsUncarried(): void
    {
        $converter = new ProseMirrorToCarve();
        $document = $converter->convert(self::paragraph('mention', ['id' => 'alice', 'label' => ['Alice'], 'mentionSuggestionChar' => '@']));

        $this->assertSame("ping @alice\n", CarveConverter::carve()->render($document));
        $this->assertSame(
            ['label' => 'a Carve attribute holds a string, and this value is of type array'],
            $converter->droppedAttributes(),
        );
    }

    /**
     * @param string $type
     * @param array<string, mixed> $attrs
     *
     * @return array<string, mixed>
     */
    private static function paragraph(string $type, array $attrs): array
    {
        return [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'ping '], ['type' => $type, 'attrs' => $attrs]],
                ],
            ],
        ];
    }

    public function testTheTextPathReportsTheMentionSOwnAttributes(): void
    {
        $converter = new ProseMirrorToCarve();
        $document = $converter->convert(
            self::paragraph('mention', ['id' => 'Lea Thompson', 'label' => null, 'data-team' => 'core', 'class' => 'vip']),
        );

        $this->assertSame("ping \\@Lea Thompson\n", CarveConverter::carve()->render($document));
        $this->assertSame(
            [
                'id' => self::MENTION_AS_TEXT,
                'data-team' => self::ATTRIBUTE_AS_TEXT,
                'class' => self::ATTRIBUTE_AS_TEXT,
            ],
            $converter->droppedAttributes(),
        );
    }

    public function testEditorBookkeepingIsNotReportedAsALostAttribute(): void
    {
        $converter = new ProseMirrorToCarve();
        $converter->convert(self::paragraph('mention', [
            'id' => 'Lea Thompson',
            'label' => null,
            'mentionSuggestionChar' => '@',
            'cssClass' => 'mention',
            'carveAttrOrder' => 'data-team',
            'data-team' => ['core'],
        ]));

        $this->assertSame(
            ['id' => self::MENTION_AS_TEXT, 'data-team' => 'a Carve attribute holds a string, and this value is of type array'],
            $converter->droppedAttributes(),
        );
    }

    public function testASpellableNameStillCarriesTheAttributeToTheWriter(): void
    {
        // CONTROL: the attribute is not lost on this path, so the writer is
        // where the caller finds out (markup-carve/carve-php#2083).
        $converter = new ProseMirrorToCarve();
        $document = $converter->convert(self::paragraph('mention', ['id' => 'lea', 'data-team' => 'core']));

        $this->assertSame([], $converter->droppedAttributes());
        $this->expectException(SourceUnspellableException::class);
        CarveConverter::carve()->render($document);
    }

    private static function plainText(Node $node): string
    {
        if ($node instanceof ContentNodeInterface) {
            return $node->getContent();
        }
        $text = '';
        foreach ($node->getChildren() as $child) {
            $text .= self::plainText($child);
        }

        return $text;
    }

    private static function firstMention(Node $node): ?Mention
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Mention) {
                return $child;
            }
            $found = self::firstMention($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
