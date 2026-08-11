<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use InvalidArgumentException;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstPatch;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AstPatchTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function ast(string $source): array
    {
        return (new AstCodec())->encode(CarveConverter::create()->parse($source));
    }

    public function testPatchRoundTripsThroughJsonAndReplaysRevision(): void
    {
        $before = $this->ast("# Title\n\nSee [docs](/a).\n");
        $after = $this->ast("## Title\n\nSee [docs](/b).\n\nAdded.\n");
        $wire = json_encode(AstPatch::create($before, $after), JSON_THROW_ON_ERROR);
        $operations = json_decode($wire, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(AstPatch::apply($after, []), AstPatch::apply($before, $operations));
    }

    public function testScalarEditProducesNarrowPatch(): void
    {
        $patch = AstPatch::create($this->ast("See [docs](/a).\n"), $this->ast("See [docs](/b).\n"));

        $this->assertCount(1, $patch);
        $this->assertSame('replace', $patch[0]['op']);
        $this->assertStringEndsWith('/href', $patch[0]['path']);
        $this->assertSame('/b', $patch[0]['value']);
    }

    public function testInvalidPointerIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AstPatch::apply($this->ast("one\n"), [['op' => 'remove', 'path' => '/missing/value']]);
    }

    public function testObjectAndArrayOperationsReplayIndividually(): void
    {
        $document = ['type' => 'document', 'children' => [['type' => 'paragraph', 'children' => []]], 'old' => true];
        $operations = [
            ['op' => 'add', 'path' => '/children/1', 'value' => ['type' => 'paragraph', 'children' => []]],
            ['op' => 'replace', 'path' => '/children/0', 'value' => ['type' => 'heading', 'level' => 1, 'children' => []]],
            ['op' => 'remove', 'path' => '/children/1'],
            ['op' => 'add', 'path' => '/a~1b~0c', 'value' => 'escaped'],
            ['op' => 'remove', 'path' => '/old'],
        ];

        $result = AstPatch::apply($document, $operations);

        $this->assertSame('heading', $result['children'][0]['type']);
        $this->assertSame('escaped', $result['a/b~c']);
        $this->assertArrayNotHasKey('old', $result);
    }

    public function testCreateEmitsObjectAddAndRemoveOperations(): void
    {
        $before = ['type' => 'document', 'children' => [], 'remove' => true];
        $after = ['type' => 'document', 'children' => [], 'add' => ['nested' => true]];

        $patch = AstPatch::create($before, $after);

        $this->assertSame($after + ['srcByteLength' => 0], AstPatch::apply($before, $patch));
        $this->assertSame(['remove', 'add'], array_column($patch, 'op'));
    }

    public function testRootReplacementIsValidatedAndCleaned(): void
    {
        $document = $this->ast("old\n");
        $replacement = $this->ast("new\n");
        $replacement['pos'] = ['startOffset' => 0];

        $result = AstPatch::apply($document, [['op' => 'replace', 'path' => '', 'value' => $replacement]]);

        $this->assertArrayNotHasKey('pos', $result);
        $this->assertSame(0, $result['srcByteLength']);
    }

    public function testAuthorAttributesNamedLikeMetadataSurvive(): void
    {
        $before = ['type' => 'document', 'children' => [], 'attrs' => ['keyValues' => ['pos' => 'before', 'srcByteLength' => 'before']]];
        $after = ['type' => 'document', 'children' => [], 'attrs' => ['keyValues' => ['pos' => 'after', 'srcByteLength' => 'after']]];

        $result = AstPatch::apply($before, AstPatch::create($before, $after));

        $this->assertSame($after['attrs'], $result['attrs']);
    }

    /**
     * @param array<string, mixed> $document
     * @param list<array<string, mixed>> $operations
     */
    #[DataProvider('invalidPatchProvider')]
    public function testMalformedPatchesAreRejected(array $document, array $operations): void
    {
        $this->expectException(InvalidArgumentException::class);
        AstPatch::apply($document, $operations);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<array<string, mixed>>}>
     */
    public static function invalidPatchProvider(): iterable
    {
        $document = ['type' => 'document', 'children' => []];

        yield 'remove root' => [$document, [['op' => 'remove', 'path' => '']]];
        yield 'scalar root' => [$document, [['op' => 'replace', 'path' => '', 'value' => 'no']]];
        yield 'invalid root' => [$document, [['op' => 'replace', 'path' => '', 'value' => ['type' => 'paragraph']]]];
        yield 'invalid pointer' => [$document, [['op' => 'remove', 'path' => 'children']]];
        yield 'missing direct property' => [$document, [['op' => 'remove', 'path' => '/missing']]];
        yield 'non-container parent' => [$document, [['op' => 'remove', 'path' => '/type/value']]];
        yield 'non-numeric array index' => [$document, [['op' => 'remove', 'path' => '/children/no']]];
        yield 'leading-zero array index' => [$document, [['op' => 'remove', 'path' => '/children/00']]];
        yield 'array index out of range' => [$document, [['op' => 'remove', 'path' => '/children/1']]];
        yield 'numeric document property' => [['type' => 'document', 'children' => [], 0 => true], []];
        yield 'unknown operation' => [$document, [['op' => 'test', 'path' => '/type', 'value' => 'paragraph']]];
        yield 'missing value' => [$document, [['op' => 'replace', 'path' => '/type']]];
    }
}
