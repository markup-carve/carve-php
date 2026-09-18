<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A mention or tag with no destination and a name `name_word {'.' name_word}`
 * rejects has no Carve spelling, so the writer refuses it
 * (markup-carve/carve-php#2159).
 */
class AnUnspellableMentionNameIsRefusedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function unspellable(): array
    {
        return [
            'a space' => ['mention', '@Lea Thompson'],
            'an apostrophe' => ['mention', "@o'brien"],
            'a trailing dot' => ['mention', '@lea.'],
            'a leading dot' => ['mention', '@.lea'],
            'a doubled dot' => ['mention', '@lea..t'],
            'a non-ASCII letter' => ['mention', '@Zoë'],
            'a second sigil' => ['mention', '@@lea'],
            'no name' => ['mention', '@'],
            'no sigil and a space' => ['mention', 'Lea Thompson'],
            'a tag with a space' => ['tag', '#big release'],
            'a tag with a trailing dot' => ['tag', '#v1.'],
            'a tag with a non-ASCII letter' => ['tag', '#café'],
        ];
    }

    #[DataProvider('unspellable')]
    public function testTheWriterRefusesAHandBuiltNode(string $class, string $label): void
    {
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('ping '));
        $paragraph->appendChild(new Mention($class, '', $label));
        $document = new Document();
        $document->appendChild($paragraph);

        try {
            $written = CarveConverter::carve()->render($document);
            $this->fail('No SourceUnspellableException was thrown; wrote ' . json_encode($written));
        } catch (SourceUnspellableException $exception) {
            $this->assertSame($class, $exception->nodeType);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function decoded(): array
    {
        return [
            'a mention' => [['type' => 'mention', 'user' => 'Lea Thompson'], 'mention'],
            'a tag' => [['type' => 'tag', 'name' => 'big release'], 'tag'],
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param string $type
     */
    #[DataProvider('decoded')]
    public function testTheWriterRefusesADecodedNode(array $node, string $type): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => [$node]]],
        ]);

        $this->expectException(SourceUnspellableException::class);
        CarveConverter::carve()->render($document);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function spellable(): array
    {
        return [
            'a plain name' => ['mention', '@lea'],
            'interior dots' => ['mention', '@john.doe.2'],
            'a hyphen and an underscore' => ['mention', '@a_b-c'],
            'a dotted tag' => ['tag', '#rel.1'],
        ];
    }

    #[DataProvider('spellable')]
    public function testASpellableNameIsWrittenAndReadsBack(string $class, string $label): void
    {
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Mention($class, '', $label));
        $document = new Document();
        $document->appendChild($paragraph);

        $written = CarveConverter::carve()->render($document);
        $this->assertSame($label . "\n", $written);
        $this->assertSame($written, CarveConverter::carve()->render(CarveConverter::carve()->parse($written)));
    }

    /**
     * The bridge names a stock mention by `id` and writes a name the grammar
     * rejects as text, so it never reaches the refusal.
     */
    public function testTheBridgeWritesAStockUnspellableNameAsText(): void
    {
        $converter = new ProseMirrorToCarve();
        $document = $converter->convert(self::proseMirror(['type' => 'mention', 'attrs' => ['id' => 'Lea Thompson', 'label' => null]]));

        $this->assertSame("ping \\@Lea Thompson\n", CarveConverter::carve()->render($document));
        $this->assertArrayHasKey('id', $converter->degradedAttributes());
    }

    /**
     * A bridge mention that holds its name in a child has no attribute to
     * report the loss under, so the refusal reaches the caller instead of
     * `@Lea Thompson` reading back as the mention `Lea`.
     */
    public function testABridgeMentionNamedByAChildIsRefused(): void
    {
        $document = (new ProseMirrorToCarve())->convert(
            self::proseMirror(['type' => 'mention', 'content' => [['type' => 'text', 'text' => '@Lea Thompson']]]),
        );

        $this->expectException(SourceUnspellableException::class);
        CarveConverter::carve()->render($document);
    }

    /**
     * `fmt` never reaches the refusal: every mention the parser builds carries
     * a name the writer accepts. Exhaustive over short runs of the characters
     * that end, split or break a name.
     */
    public function testFormattingParsedSourceNeverRefuses(): void
    {
        $alphabet = ['@', '#', 'a', '.', ' ', "'", 'é', '-', '\\'];
        $runs = [''];
        $all = [];
        for ($length = 1; $length <= 4; $length++) {
            $next = [];
            foreach ($runs as $run) {
                foreach ($alphabet as $character) {
                    $next[] = $run . $character;
                }
            }
            $runs = $next;
            array_push($all, ...$runs);
        }

        $mentions = 0;
        foreach ($all as $run) {
            foreach (['x ' . $run . ' y', $run . 'b'] as $source) {
                $converter = CarveConverter::carve();
                $document = $converter->parse($source);
                $mentions += self::countMentions($document);
                try {
                    $converter->render($document);
                } catch (SourceUnspellableException $exception) {
                    if (in_array($exception->nodeType, ['mention', 'tag'], true)) {
                        $this->fail('fmt refused ' . json_encode($source) . ': ' . $exception->getMessage());
                    }
                }
            }
        }

        $this->assertGreaterThan(1000, $mentions);
    }

    private static function countMentions(Node $node): int
    {
        $count = $node instanceof Mention ? 1 : 0;
        foreach ($node->getChildren() as $child) {
            $count += self::countMentions($child);
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $mention
     *
     * @return array<string, mixed>
     */
    private static function proseMirror(array $mention): array
    {
        return [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ping '], $mention]]],
        ];
    }
}
