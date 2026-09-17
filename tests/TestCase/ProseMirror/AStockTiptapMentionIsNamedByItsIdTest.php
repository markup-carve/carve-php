<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
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
