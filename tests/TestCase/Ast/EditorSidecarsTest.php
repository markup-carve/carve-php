<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use InvalidArgumentException;
use MarkupCarve\Carve\Ast\AnnotationRanges;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\NodeIdentitySession;
use MarkupCarve\Carve\Ast\Provenance;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class EditorSidecarsTest extends TestCase
{
    public function testIdentitySurvivesExplicitRebindAfterSiblingInsertion(): void
    {
        $converter = CarveConverter::create();
        $session = new NodeIdentitySession();
        $before = $converter->parseWithNodeIdentity("alpha\n\nbeta\n", $session);
        NodeIdentitySession::read($before['ast'], $before['identity']);
        $old = array_column($before['identity']['nodes'], 'path', 'id');
        $betaId = array_search('/children/1', $old, true);
        self::assertIsString($betaId);
        self::assertSame($before['identity'], $session->emit($before['ast']));

        $afterAst = (new AstCodec())->encode($converter->parse("new\n\nalpha\n\nbeta\n"));
        $rebound = $session->emit($afterAst, [$betaId => '/children/2']);
        NodeIdentitySession::read($afterAst, $rebound);
        self::assertSame('/children/2', array_column($rebound['nodes'], 'path', 'id')[$betaId]);
        self::assertSame($before['identity']['session'], $rebound['session']);
    }

    public function testIdentityRejectsUnknownVersion(): void
    {
        $parsed = CarveConverter::create()->parseWithNodeIdentity("one\n", new NodeIdentitySession());
        $parsed['identity']['version'] = 2;
        $this->expectException(InvalidArgumentException::class);
        NodeIdentitySession::read($parsed['ast'], $parsed['identity']);
    }

    public function testIdentityDoesNotTreatAuthorAttributesAsNodes(): void
    {
        $parsed = CarveConverter::create()->parseWithNodeIdentity("[x]{type=foo}\n", new NodeIdentitySession());
        self::assertNotContains('/children/0/children/0/attrs/keyValues', array_column($parsed['identity']['nodes'], 'path'));
    }

    public function testIdentityRejectsDuplicatePath(): void
    {
        $parsed = CarveConverter::create()->parseWithNodeIdentity("one\n", new NodeIdentitySession());
        $parsed['identity']['nodes'][] = $parsed['identity']['nodes'][0];
        $this->expectException(InvalidArgumentException::class);
        NodeIdentitySession::read($parsed['ast'], $parsed['identity']);
    }

    public function testSeparateIdentitySessionsHaveDifferentTokens(): void
    {
        self::assertNotSame((new NodeIdentitySession())->session, (new NodeIdentitySession())->session);
    }

    public function testIdentityIgnoresMovedSourcePositions(): void
    {
        $converter = CarveConverter::create();
        $session = new NodeIdentitySession();
        $beforeAst = $converter->parseWithSourceLayout("one\n\ntwo\n")['ast'];
        $before = $session->emit($beforeAst);
        $twoId = array_search('/children/1', array_column($before['nodes'], 'path', 'id'), true);
        self::assertIsString($twoId);

        $afterAst = $converter->parseWithSourceLayout("ones\n\ntwo\n")['ast'];
        $after = $session->emit($afterAst);
        self::assertSame('/children/1', array_column($after['nodes'], 'path', 'id')[$twoId]);
    }

    public function testIdentityAcceptsPartialSidecar(): void
    {
        $parsed = CarveConverter::create()->parseWithNodeIdentity("one\n", new NodeIdentitySession());
        array_pop($parsed['identity']['nodes']);
        NodeIdentitySession::read($parsed['ast'], $parsed['identity']);
        self::assertCount(2, $parsed['identity']['nodes']);
    }

    public function testIdentityRejectsInvalidRetainedPathType(): void
    {
        $converter = CarveConverter::create();
        $session = new NodeIdentitySession();
        $parsed = $converter->parseWithNodeIdentity("one\n", $session);
        $id = $parsed['identity']['nodes'][0]['id'];
        $this->expectException(InvalidArgumentException::class);
        $session->emit($parsed['ast'], [$id => ['wrong']]);
    }

    public function testOverlappingRangesAndCodepointOffsets(): void
    {
        $ast = CarveConverter::create()->parseWithNodeIdentity("äbc\n", new NodeIdentitySession())['ast'];
        $ranges = AnnotationRanges::create($ast, [
            ['id' => 'one', 'kind' => 'example:comment', 'start' => ['path' => '/children/0/children/0', 'offset' => 0], 'end' => ['path' => '/children/0/children/0', 'offset' => 2]],
            ['id' => 'two', 'kind' => 'example:search', 'start' => ['path' => '/children/0/children/0', 'offset' => 1], 'end' => ['path' => '/children/0/children/0', 'offset' => 3], 'data' => ['match' => 'bc']],
        ]);
        AnnotationRanges::read($ast, $ranges);
        self::assertCount(2, $ranges['ranges']);

        $ranges['ranges'][1]['end']['offset'] = 4;
        $this->expectException(InvalidArgumentException::class);
        AnnotationRanges::read($ast, $ranges);
    }

    public function testRangeCannotEndBeforeItStarts(): void
    {
        $ast = CarveConverter::create()->parseWithNodeIdentity("abc\n", new NodeIdentitySession())['ast'];
        $this->expectException(InvalidArgumentException::class);
        AnnotationRanges::create($ast, [
            ['id' => 'reversed', 'kind' => 'example:comment', 'start' => ['path' => '/children/0/children/0', 'offset' => 2], 'end' => ['path' => '/children/0/children/0', 'offset' => 1]],
        ]);
    }

    public function testRangeCanAnchorToAParagraphText(): void
    {
        $ast = CarveConverter::create()->parseWithNodeIdentity("abc\n", new NodeIdentitySession())['ast'];
        $sidecar = AnnotationRanges::create($ast, [
            ['id' => 'block', 'kind' => 'example:comment', 'start' => ['path' => '/children/0', 'offset' => 1], 'end' => ['path' => '/children/0', 'offset' => 3]],
        ]);
        self::assertCount(1, $sidecar['ranges']);
    }

    public function testParentAndChildAnchorsUseTextPositions(): void
    {
        $ast = CarveConverter::create()->parseWithNodeIdentity("abc\n", new NodeIdentitySession())['ast'];
        $sidecar = AnnotationRanges::create($ast, [
            ['id' => 'valid', 'kind' => 'example:comment', 'start' => ['path' => '/children/0/children/0', 'offset' => 0], 'end' => ['path' => '/children/0', 'offset' => 3]],
        ]);
        self::assertCount(1, $sidecar['ranges']);

        $this->expectException(InvalidArgumentException::class);
        AnnotationRanges::create($ast, [
            ['id' => 'invalid', 'kind' => 'example:comment', 'start' => ['path' => '/children/0', 'offset' => 3], 'end' => ['path' => '/children/0/children/0', 'offset' => 0]],
        ]);
    }

    public function testRangeRejectsNullData(): void
    {
        $ast = CarveConverter::create()->parseWithNodeIdentity("abc\n", new NodeIdentitySession())['ast'];
        $this->expectException(InvalidArgumentException::class);
        AnnotationRanges::create($ast, [
            ['id' => 'bad', 'kind' => 'example:comment', 'start' => ['path' => '/children/0', 'offset' => 0], 'end' => ['path' => '/children/0', 'offset' => 1], 'data' => null],
        ]);
    }

    public function testRangeCanAnchorToCodeBlockContent(): void
    {
        $ast = CarveConverter::create()->parseWithNodeIdentity("```block\nabc\n```\n", new NodeIdentitySession())['ast'];
        $ranges = AnnotationRanges::create($ast, [
            ['id' => 'code', 'kind' => 'example:search', 'start' => ['path' => '/children/0', 'offset' => 1], 'end' => ['path' => '/children/0', 'offset' => 3]],
        ]);
        self::assertCount(1, $ranges['ranges']);
    }

    public function testProvenanceRecordsSourceBytesAndValidatesAncestry(): void
    {
        $parsed = CarveConverter::create()->parseWithProvenance("äbc\n", 'file:///book/index.crv');
        Provenance::read($parsed['ast'], $parsed['provenance']);
        self::assertSame('file:///book/index.crv', $parsed['provenance']['sources'][0]['uri']);
        self::assertSame(4, $parsed['provenance']['nodes'][1]['endByte']);

        $sidecar = Provenance::create($parsed['ast'], [
            ['id' => 's0', 'uri' => 'file:///book/index.crv'],
            ['id' => 's1', 'uri' => 'file:///book/ch1.md', 'format' => 'markdown', 'parent' => 's0'],
        ], [['path' => '/children/0', 'source' => 's1', 'origin' => 'authored', 'startByte' => 0, 'endByte' => 4]]);
        self::assertSame('s1', $sidecar['nodes'][0]['source']);
        $sidecar['sources'][0]['parent'] = 's1';
        $this->expectException(InvalidArgumentException::class);
        Provenance::read($parsed['ast'], $sidecar);
    }

    public function testProvenanceRejectsAOneSidedByteRange(): void
    {
        $ast = CarveConverter::create()->parseWithNodeIdentity("one\n", new NodeIdentitySession())['ast'];
        $this->expectException(InvalidArgumentException::class);
        Provenance::create($ast, [['id' => 's0']], [
            ['path' => '/children/0', 'source' => 's0', 'origin' => 'authored', 'startByte' => 0],
        ]);
    }

    public function testProvenanceRejectsNullByteEndpoints(): void
    {
        $ast = CarveConverter::create()->parseWithNodeIdentity("one\n", new NodeIdentitySession())['ast'];
        $this->expectException(InvalidArgumentException::class);
        Provenance::create($ast, [['id' => 's0']], [
            ['path' => '/children/0', 'source' => 's0', 'origin' => 'authored', 'startByte' => null, 'endByte' => null],
        ]);
    }
}
