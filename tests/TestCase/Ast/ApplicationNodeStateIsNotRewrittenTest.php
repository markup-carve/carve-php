<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Node\Document;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ApplicationNodeStateIsNotRewrittenTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['registered' => [], 'classMap' => null, 'packageTypes' => null] as $name => $value) {
            (new ReflectionProperty(AstCodec::class, $name))->setValue(null, $value);
        }
    }

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

    public function testRegisteringTheClassDoesNotMoveTheBoundary(): void
    {
        AstCodec::register(ApplicationNodeWithNodeShapedState::class);

        $this->assertTrue(AstCodec::isApplicationType('app_node_with_node_shaped_state'));

        $document = new Document();
        $document->appendChild(new ApplicationNodeWithNodeShapedState());

        $this->assertSame(
            ['type' => 'section', 'content' => 'x'],
            (new AstCodec())->encode($document)['children'][0]['data'],
        );
    }

    public function testReplacingARegisteredTypeUsesTheNewClassState(): void
    {
        $codec = new AstCodec();
        AstCodec::register(ApplicationNodeWithNodeShapedState::class);
        $original = new Document();
        $original->appendChild(new ApplicationNodeWithNodeShapedState());
        $codec->encode($original);

        AstCodec::register(ReplacementApplicationNode::class);
        $replacement = new Document();
        $replacement->appendChild(new ReplacementApplicationNode());
        $encoded = $codec->encode($replacement);

        $this->assertSame('replacement', $encoded['children'][0]['marker']);
        $this->assertSame($encoded, $codec->encode($codec->decode($encoded)));
    }

    public function testACanonicalWireNameIsNotAnApplicationType(): void
    {
        foreach (['document', 'paragraph', 'autolink', 'admonition', 'tag'] as $type) {
            $this->assertFalse(AstCodec::isApplicationType($type), $type);
        }
        $this->assertTrue(AstCodec::isApplicationType('app_node_with_node_shaped_state'));
        $this->assertTrue(AstCodec::isApplicationType('never_registered_widget'));
    }
}
