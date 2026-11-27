<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\PublicApi;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * C2-A-013 (carve#6888): decodeTree appends the envelope declarations the root lacks to the root `extensions`.
 */
final class EnvelopeDeclarationLiftTest extends TestCase
{
    /**
     * @var string
     */
    private const ENVELOPE = '{"astVersion":"2.0","extensions":[{"name":"x.note","version":"1","required":false,"slots":[]}],'
        . '"document":{"type":"document","astVersion":"2.0","children":[{"type":"paragraph","data":{"x.note":{"by":"a"}},'
        . '"children":[{"type":"text","value":"Hello"}]}]}}';

    /**
     * @var string
     */
    private const SIDECAR = '{"ranges":[{"id":"r1","start":{"path":"/children/0/children/0","offset":0},'
        . '"end":{"path":"/children/0/children/0","offset":5},"data":{"x.note":{"by":"b"}}}]}';

    /**
     * @var string
     */
    private const LIFTED = '{"astVersion":"2.0","children":[{"children":[{"type":"text","value":"Hello"}],'
        . '"data":{"x.note":{"by":"a"}},"type":"paragraph"}],"extensions":[{"name":"x.note","required":false,"slots":[],'
        . '"version":"1"}],"type":"document"}';

    public function testDecodedTreeCarriesEnvelopeDeclarations(): void
    {
        $sidecar = json_decode(self::SIDECAR, false, 512, JSON_THROW_ON_ERROR);
        foreach ([[], ['annotations' => $sidecar]] as $options) {
            $tree = Carve::decodeTree(self::ENVELOPE, $options);
            self::assertSame(self::LIFTED, Carve::encodeTree($tree));
        }
    }

    public function testLaterCallsAcceptTheDecodedTree(): void
    {
        $tree = Carve::decodeTree(self::ENVELOPE);
        $sidecar = json_decode(self::SIDECAR, false, 512, JSON_THROW_ON_ERROR);
        Carve::validateTree($tree);
        Carve::validateTree($tree, ['annotations' => $sidecar]);
        self::assertSame(self::LIFTED, Carve::encodeTree(Carve::decodeTree(Carve::encodeEnvelope($tree))));
        self::assertSame(self::LIFTED, Carve::encodeTree(Carve::decodeTree(Carve::encodeTree($tree))));
        $patched = Carve::applyPatch($tree, [['op' => 'test', 'path' => '/astVersion', 'value' => '2.0']]);
        self::assertSame(self::LIFTED, Carve::encodeTree($patched));
        $document = '"document":{"type":"document","astVersion":"2.0","extensions":[{"name":"x.note"';
        self::assertStringContainsString($document, Carve::encodeEnvelope($tree));
    }

    public function testRootEntriesFirstAndNoDuplicate(): void
    {
        $declaration = static fn (string $name): array => ['name' => $name, 'version' => '1', 'required' => false, 'slots' => []];
        $envelope = [
            'astVersion' => '2.0',
            'extensions' => [$declaration('x.c'), $declaration('x.a'), $declaration('x.b')],
            'document' => [
                'type' => 'document',
                'astVersion' => '2.0',
                'extensions' => [$declaration('x.a')],
                'children' => [],
            ],
        ];
        $tree = Carve::decodeTree(json_encode($envelope, JSON_THROW_ON_ERROR));
        preg_match_all('/"name":"(x\.\w)"/', Carve::encodeTree($tree), $names);
        self::assertSame(['x.a', 'x.c', 'x.b'], $names[1]);
    }

    public function testRootDeclaringEverythingIsUnchanged(): void
    {
        $declarations = '[{"name":"x.note","required":false,"slots":[],"version":"1"}]';
        $bare = '{"astVersion":"2.0","children":[],"extensions":' . $declarations . ',"type":"document"}';
        $envelope = '{"astVersion":"2.0","extensions":' . $declarations . ',"document":' . $bare . '}';
        self::assertSame($bare, Carve::encodeTree(Carve::decodeTree($envelope)));
        $empty = '{"astVersion":"2.0","children":[],"type":"document"}';
        self::assertSame($empty, Carve::encodeTree(Carve::decodeTree('{"astVersion":"2.0","document":' . $empty . '}')));
    }
}
