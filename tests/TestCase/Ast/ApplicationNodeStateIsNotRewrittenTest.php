<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\StoredPayloadUpgrade;
use MarkupCarve\Carve\Node\Document;
use PHPUnit\Framework\TestCase;

/**
 * An application node's own state is DATA, not a subtree to rewrite.
 *
 * Two passes added with carve-php#1002 walk an encoded tree: the one that
 * publishes an internal type under its vocabulary name, and the stored-payload
 * upgrade. Both recurse through array-valued fields, and an application node's
 * fields are an array whose keys this package did not choose - so a field
 * holding `['type' => 'section']` or `['type' => 'raw_text', 'id' => 'q']` is
 * the application's data and rewriting it corrupts the node.
 *
 * PART 12 §12(d) already draws this line: a registered type and ITS SUBTREE are
 * outside the schema by construction. Both passes stop at the same boundary.
 *
 * The node below is deliberately NOT registered: `register()` writes a static
 * this package never clears, so a test that called it would change what
 * `AstCodec::schema()` reports for every test after it. Registration is a
 * DECODE requirement - the encoder reflects over the object it was handed - and
 * the boundary these passes draw is "not one of this package's classes", which
 * a registered type is not either.
 */
class ApplicationNodeStateIsNotRewrittenTest extends TestCase
{
    public function testAnApplicationFieldSpelledLikeANodeSurvivesEncoding(): void
    {
        $document = new Document();
        $document->appendChild(new ApplicationNodeWithNodeShapedState());

        $encoded = (new AstCodec())->encode($document);

        $this->assertSame(
            ['type' => 'section', 'content' => 'x'],
            $encoded['children'][0]['data'],
        );
    }

    public function testAnApplicationFieldSpelledLikeANodeSurvivesTheUpgrade(): void
    {
        $payload = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'app_node_with_node_shaped_state',
                    'data' => [
                        'type' => 'raw_text',
                        'content' => 'x',
                        'id' => 'q',
                    ],
                ],
            ],
        ];

        $this->assertSame($payload, StoredPayloadUpgrade::upgrade($payload));
        $this->assertSame([], StoredPayloadUpgrade::retiredShapesIn($payload));
    }

    /**
     * CONTROL. The same field spelled the same way on a CORE node IS rewritten,
     * or the two assertions above would pass on a pass that rewrites nothing.
     */
    public function testTheSameSpellingOnACoreNodeIsStillRewritten(): void
    {
        $payload = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'raw_text', 'content' => 'x']]],
            ],
        ];

        $this->assertSame(
            'text',
            StoredPayloadUpgrade::upgrade($payload)['children'][0]['children'][0]['type'],
        );
        $this->assertSame(['a `raw_text` node'], StoredPayloadUpgrade::retiredShapesIn($payload));
    }

    /**
     * The question is asked by WIRE name, and three of this engine's types are
     * published under a canonical name their class does not carry. Answering
     * those as application types would stop both passes at an ordinary node.
     */
    public function testACanonicalWireNameIsNotAnApplicationType(): void
    {
        foreach (['document', 'paragraph', 'autolink', 'admonition', 'tag'] as $type) {
            $this->assertFalse(AstCodec::isApplicationType($type), $type);
        }
        $this->assertTrue(AstCodec::isApplicationType('app_node_with_node_shaped_state'));
        // And an UNREGISTERED type answers the same way, so an encode never
        // reaches into a node this package has not heard of either.
        $this->assertTrue(AstCodec::isApplicationType('never_registered_widget'));
    }
}
