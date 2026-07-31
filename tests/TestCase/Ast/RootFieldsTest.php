<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 section 2 fixes the document root's fields: `type`, `children` and
 * `srcByteLength` always, `frontmatter` and `footnoteDefs` exactly when the
 * document has them, and nothing else.
 *
 * This engine models both as BLOCK NODES, which is a reasonable internal choice
 * and the wrong thing to publish. `children` is an ORDER, and neither has a
 * position in the document's flow: a footnote definition renders where the
 * reference to it appears, not where it was written, and frontmatter renders
 * nowhere at all. A consumer walking `children` to render therefore had to know
 * to skip two of the types it found there (carve#411).
 *
 * The tree is untouched - this is the map-on-the-way-out PART 12 section 1 asks
 * for.
 */
class RootFieldsTest extends TestCase
{
    private AstCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(string $source): array
    {
        return $this->codec->encode((new CarveConverter())->parse($source));
    }

    public function testFrontmatterIsPublishedOnTheRootNotInChildren(): void
    {
        $encoded = $this->encode("---\ntitle: x\n---\n\nbody\n");

        $this->assertSame(
            ['format' => 'yaml', 'content' => 'title: x'],
            $encoded['frontmatter'],
        );
        $this->assertSame(
            ['paragraph'],
            array_column($encoded['children'], 'type'),
            'the frontmatter block must not also appear in the order of the document',
        );
    }

    public function testATypedBlockKeepsItsFormat(): void
    {
        $encoded = $this->encode("---json\n{\"title\": \"x\"}\n---\n\nbody\n");

        $this->assertSame('json', $encoded['frontmatter']['format']);
        $this->assertSame('{"title": "x"}', $encoded['frontmatter']['content']);
    }

    public function testFootnoteDefinitionsArePublishedOnTheRootKeyedByLabel(): void
    {
        $encoded = $this->encode("a[^r]\n\n[^r]: the note\n");

        $this->assertArrayHasKey('footnoteDefs', $encoded);
        $this->assertSame(['r'], array_keys($encoded['footnoteDefs']));
        $this->assertSame(
            ['paragraph'],
            array_column($encoded['children'], 'type'),
            'a definition renders where its reference is, so it has no place in the order',
        );
    }

    public function testARootFieldIsAbsentWhenTheDocumentDoesNotHaveIt(): void
    {
        $encoded = $this->encode("just a paragraph\n");

        $this->assertArrayNotHasKey('frontmatter', $encoded);
        $this->assertArrayNotHasKey('footnoteDefs', $encoded);
    }

    public function testTheRootCarriesNothingElse(): void
    {
        $keys = array_keys($this->encode("---\na: b\n---\n\nx[^r]\n\n[^r]: n\n"));
        sort($keys);

        $this->assertSame(
            ['children', 'footnoteDefs', 'frontmatter', 'srcByteLength', 'type'],
            $keys,
        );
    }

    public function testBothSurviveARoundTrip(): void
    {
        // The decoder has to put them back, or the codec loses on its own
        // output - which is what the loss check caught while this was built.
        $source = "---json\n{\"a\": 1}\n---\n\nx[^r]\n\n[^r]: n\n";
        $decoded = $this->codec->decode($this->encode($source));

        $reencoded = $this->codec->encode($decoded);
        $this->assertSame($this->encode($source), $reencoded);

        $converter = new CarveConverter();
        $this->assertSame(
            $converter->render($converter->parse($source)),
            $converter->render($decoded),
            'a decoded document must render identically to the parsed one',
        );
    }

    public function testTheParserStillModelsThemAsBlocks(): void
    {
        // The wire form is a mapping, not a change to the tree. Asserted so a
        // later refactor cannot quietly turn the publishing rule into a parser
        // change.
        $document = (new CarveConverter())->parse("---\na: b\n---\n\nx\n");
        $types = array_map(
            static fn (object $child): string => $child->getType(),
            $document->getChildren(),
        );

        $this->assertContains('frontmatter', $types);
    }
}
