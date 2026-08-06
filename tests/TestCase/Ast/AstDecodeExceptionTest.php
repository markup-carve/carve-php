<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * PART 12 §9(b) and §11: an ingest rejects with A TYPED, DOCUMENTED FAILURE -
 * "not truncation, not a crash, and not whatever its JSON library happened to
 * raise".
 *
 * The decoder already refused each of these and already named what was wrong.
 * What a caller could not do was SAY SO: a bare `RuntimeException` is not
 * distinguishable from a bug in their own callback or an extension throwing
 * mid-decode, so "this payload is not a Carve AST" and "something went wrong"
 * were one catch (carve-php#912).
 *
 * Asserted per REASON rather than once, because a single payload passes even
 * if six of the seven refusals were left alone.
 */
class AstDecodeExceptionTest extends TestCase
{
    protected function codec(): AstCodec
    {
        return new AstCodec();
    }

    protected function tree(string $source = "# H\n\ntext\n"): array
    {
        return $this->codec()->encode((new BlockParser(false, false, false, true))->parse($source));
    }

    public function testAPropertyTheSchemaDoesNotNameIsRefusedWithTheType(): void
    {
        $tree = $this->tree();
        $tree['children'][0]['bogusXyz'] = 'leak';

        $this->expectException(AstDecodeException::class);
        // Still NAMES it: the type must not cost the caller the detail §11 asks
        // for, which is the property and where it sat.
        $this->expectExceptionMessageMatches('/bogusXyz/');
        $this->codec()->decode($tree);
    }

    public function testAForeignRootIsRefusedWithTheType(): void
    {
        $this->expectException(AstDecodeException::class);
        $this->codec()->decode(['type' => 'doc', 'srcByteLength' => 0, 'children' => []]);
    }

    public function testANodeWithoutAStringTypeIsRefusedWithTheType(): void
    {
        $this->expectException(AstDecodeException::class);
        $this->codec()->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['children' => []]],
        ]);
    }

    public function testAnUnknownNodeTypeIsRefusedWithTheType(): void
    {
        $this->expectException(AstDecodeException::class);
        $this->codec()->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'no_such_node', 'children' => []]],
        ]);
    }

    public function testAWrongEncodingVersionIsRefusedWithTheType(): void
    {
        $tree = $this->tree();
        $tree['ast'] = 1;

        $this->expectException(AstDecodeException::class);
        $this->codec()->decode($tree);
    }

    public function testATooDeepPayloadIsRefusedWithTheType(): void
    {
        // §9(b)'s own case, which is where the requirement is written.
        $json = str_repeat('{"type":"block_quote","children":[', 900)
            . '{"type":"paragraph","children":[]}'
            . str_repeat(']}', 900);

        $this->expectException(AstDecodeException::class);
        $this->codec()->decodeJson('{"type":"document","srcByteLength":0,"children":[' . $json . ']}');
    }

    public function testTheTypeExtendsRuntimeExceptionSoExistingCatchesStillWork(): void
    {
        // Gaining the type is additive, not a break: code that already caught
        // the decoder's refusal keeps catching it.
        $this->assertInstanceOf(RuntimeException::class, new AstDecodeException('x'));
    }

    public function testAValidTreeStillDecodes(): void
    {
        // The control. Every assertion above passes for a decoder that refuses
        // everything.
        $document = $this->codec()->decode($this->tree());

        $this->assertCount(2, $document->getChildren());
    }
}
