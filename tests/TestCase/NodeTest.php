<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use InvalidArgumentException;
use MarkupCarve\Carve\Node\Block\BlockExtension;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\Ruby;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\ProseMirror\SchemaMap;
use MarkupCarve\Carve\Renderer\EscapeWindows;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Node manipulation methods
 */
class NodeTest extends TestCase
{
    public function testPreviousSiblingFollowsMutationsMovesAndClones(): void
    {
        $parent = new Paragraph();
        $a = new Text('a');
        $b = new Text('b');
        $c = new Text('c');
        $parent->setChildren([$a, $b, $c]);
        self::assertSame($b, $c->getPreviousSibling());
        $parent->removeChild($b);
        self::assertSame($a, $c->getPreviousSibling());
        $parent->prependChild($b);
        self::assertSame($b, $a->getPreviousSibling());
        $parent->replaceChild(1, $b);
        self::assertSame($b, $c->getPreviousSibling());
        $parent->replaceChildWithMany($b, [$a, $b]);
        self::assertSame($a, $b->getPreviousSibling());
        $parent->removeChildAt(0);
        self::assertNull($b->getPreviousSibling());
        $parent->appendChild($a);
        self::assertSame($c, $a->getPreviousSibling());
        $other = new Paragraph();
        $other->setChildren([$c, $a]);
        self::assertNull($b->getPreviousSibling());
        self::assertSame($c, $a->getPreviousSibling());
        $copy = clone $other;
        self::assertSame($copy->getChildren()[0], $copy->getChildren()[1]->getPreviousSibling());
        self::assertNull($parent->getPreviousSibling());
    }

    public function testPreviousSiblingCacheDoesNotChangeMarkIdentity(): void
    {
        $left = new Emphasis();
        $left->setChildren([new Text('a'), new Text('b')]);
        $right = clone $left;
        $left->getChildren()[1]->getPreviousSibling();
        self::assertTrue(SchemaMap::isSameMark($left, $right));
    }

    public function testPreviousSiblingFollowsTemporaryRenderWindows(): void
    {
        $parent = new Paragraph();
        $parent->setChildren([new Text('a'), new Text('b'), new Text('c')]);
        [$a, $b, $c] = $parent->getChildren();
        self::assertSame($b, $c->getPreviousSibling());
        $windows = new EscapeWindows(new Document());
        $windows->renderPruned([['owner' => $parent, 'lo' => 1, 'hi' => 2]], static function () use ($b, $c): string {
            self::assertNull($b->getPreviousSibling());
            self::assertSame($b, $c->getPreviousSibling());

            return '';
        });
        self::assertSame($a, $b->getPreviousSibling());
    }

    public function testSpecialNodeClonesKeepTheOriginalParents(): void
    {
        $ruby = new Ruby([['base' => [new Text('a'), new Text('b')], 'annotation' => [new Text('c')]]]);
        $ruby->getChildren()[1]->getPreviousSibling();
        $copy = clone $ruby;
        self::assertSame($ruby, $ruby->getChildren()[0]->getParent());
        self::assertSame($copy->getChildren()[0], $copy->getChildren()[1]->getPreviousSibling());
        $fallback = new Paragraph();
        $block = new BlockExtension('example', $fallback);
        $copy = clone $block;
        self::assertSame($block, $fallback->getParent());
        self::assertSame($copy, $copy->getFallback()->getParent());
    }

    public function testPreviousSiblingFollowsReplacementWithoutSiblingMoves(): void
    {
        $parent = new Paragraph();
        $parent->setChildren([new Text('a'), new Text('b')]);
        [$a, $b] = $parent->getChildren();
        self::assertSame($a, $b->getPreviousSibling());
        $c = new Text('c');
        $parent->replaceChildNode($a, $c);
        self::assertSame($c, $b->getPreviousSibling());
        $d = new Text('d');
        $parent->replaceChildWithMany($c, [$a, $d]);
        self::assertSame($d, $b->getPreviousSibling());
    }

    public function testPreviousSiblingSurvivesSerialization(): void
    {
        $parent = new Paragraph();
        $parent->setChildren([new Text('a'), new Text('b')]);
        $parent->getChildren()[1]->getPreviousSibling();
        $copy = unserialize(serialize($parent));
        self::assertInstanceOf(Paragraph::class, $copy);
        self::assertSame($copy->getChildren()[0], $copy->getChildren()[1]->getPreviousSibling());
    }

    public function testCustomParentReadsItsCurrentChildView(): void
    {
        $parent = new class extends Paragraph {
            public function reverseChildren(): void
            {
                $this->children = array_reverse($this->children);
            }
        };
        $parent->setChildren([new Text('a'), new Text('b')]);
        [$a, $b] = $parent->getChildren();
        self::assertSame($a, $b->getPreviousSibling());
        $parent->reverseChildren();
        self::assertNull($b->getPreviousSibling());
        self::assertSame($b, $a->getPreviousSibling());
    }

    public function testRemoveChild(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $text2 = new Text('Second');
        $paragraph->appendChild($text1);
        $paragraph->appendChild($text2);

        $this->assertCount(2, $paragraph->getChildren());

        $result = $paragraph->removeChild($text1);

        $this->assertTrue($result);
        $this->assertCount(1, $paragraph->getChildren());
        $this->assertNull($text1->getParent());
        $this->assertSame($text2, $paragraph->getChildren()[0]);
    }

    public function testRemoveChildNotFound(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $text2 = new Text('Not a child');
        $paragraph->appendChild($text1);

        $result = $paragraph->removeChild($text2);

        $this->assertFalse($result);
        $this->assertCount(1, $paragraph->getChildren());
    }

    public function testRemoveChildAt(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $text2 = new Text('Second');
        $text3 = new Text('Third');
        $paragraph->appendChild($text1);
        $paragraph->appendChild($text2);
        $paragraph->appendChild($text3);

        $removed = $paragraph->removeChildAt(1);

        $this->assertSame($text2, $removed);
        $this->assertNull($text2->getParent());
        $this->assertCount(2, $paragraph->getChildren());
        $this->assertSame($text1, $paragraph->getChildren()[0]);
        $this->assertSame($text3, $paragraph->getChildren()[1]);
    }

    public function testRemoveChildAtInvalidIndex(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $paragraph->appendChild($text1);

        $removed = $paragraph->removeChildAt(5);

        $this->assertNull($removed);
        $this->assertCount(1, $paragraph->getChildren());
    }

    public function testReplaceChildNode(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $text2 = new Text('Second');
        $replacement = new Text('Replacement');
        $paragraph->appendChild($text1);
        $paragraph->appendChild($text2);

        $result = $paragraph->replaceChildNode($text1, $replacement);

        $this->assertTrue($result);
        $this->assertSame($paragraph, $replacement->getParent());
        $this->assertNull($text1->getParent());
        $this->assertSame($replacement, $paragraph->getChildren()[0]);
        $this->assertSame($text2, $paragraph->getChildren()[1]);
    }

    public function testReplaceChildNodeNotFound(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $notChild = new Text('Not a child');
        $replacement = new Text('Replacement');
        $paragraph->appendChild($text1);

        $result = $paragraph->replaceChildNode($notChild, $replacement);

        $this->assertFalse($result);
        $this->assertCount(1, $paragraph->getChildren());
        $this->assertSame($text1, $paragraph->getChildren()[0]);
    }

    public function testReplaceChildWithMany(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $text2 = new Text('Second');
        $paragraph->appendChild($text1);
        $paragraph->appendChild($text2);

        $replacements = [
            new Text('A'),
            new Text('B'),
            new Text('C'),
        ];

        $result = $paragraph->replaceChildWithMany($text1, $replacements);

        $this->assertTrue($result);
        $this->assertCount(4, $paragraph->getChildren());
        $this->assertNull($text1->getParent());

        $children = $paragraph->getChildren();
        $this->assertEquals('A', $children[0]->getContent());
        $this->assertEquals('B', $children[1]->getContent());
        $this->assertEquals('C', $children[2]->getContent());
        $this->assertSame($text2, $children[3]);

        // Check parents are set
        foreach ($replacements as $r) {
            $this->assertSame($paragraph, $r->getParent());
        }
    }

    public function testReplaceChildWithManyNotFound(): void
    {
        $paragraph = new Paragraph();
        $text1 = new Text('First');
        $notChild = new Text('Not a child');
        $paragraph->appendChild($text1);

        $result = $paragraph->replaceChildWithMany($notChild, [new Text('A')]);

        $this->assertFalse($result);
        $this->assertCount(1, $paragraph->getChildren());
    }

    public function testReplacementDetachesOldChildAndPreservesSelfReplacement(): void
    {
        $parent = new Paragraph();
        $old = new Text('old');
        $new = new Text('new');
        $parent->appendChild($old);
        $parent->replaceChild(0, $new);
        $this->assertNull($old->getParent());
        $this->assertSame($parent, $new->getParent());
        $this->assertTrue($parent->replaceChildNode($new, $new));
        $this->assertSame([$new], $parent->getChildren());
        $this->assertSame($parent, $new->getParent());
    }

    public function testMovingAChildRepairsBothParents(): void
    {
        $first = new Paragraph();
        $second = new Paragraph();
        $child = new Text('move');
        $first->appendChild($child);
        $second->prependChild($child);
        $this->assertSame([], $first->getChildren());
        $this->assertSame([$child], $second->getChildren());
        $this->assertSame($second, $child->getParent());
        $first->appendChild($child);
        $this->assertSame([], $second->getChildren());
        $this->assertSame($first, $child->getParent());
    }

    public function testAppendingAnExistingChildMovesItWithoutDuplicatingIt(): void
    {
        $parent = new Paragraph();
        $first = new Text('first');
        $second = new Text('second');
        $parent->setChildren([$first, $second]);
        $parent->appendChild($first);
        $this->assertSame([$second, $first], $parent->getChildren());
        $parent->prependChild($first);
        $this->assertSame([$first, $second], $parent->getChildren());
    }

    public function testBulkReplacementMovesAndDetachesChildren(): void
    {
        $first = new Paragraph();
        $second = new Paragraph();
        $a = new Text('a');
        $b = new Text('b');
        $c = new Text('c');
        $first->setChildren([$a, $b]);
        $second->appendChild($c);
        $second->setChildren([$b, $a]);
        $this->assertSame([], $first->getChildren());
        $this->assertSame([$b, $a], $second->getChildren());
        $this->assertNull($c->getParent());
        $this->assertSame($second, $a->getParent());
        $this->assertSame($second, $b->getParent());
        $second->setChildren([]);
        $this->assertNull($a->getParent());
        $this->assertNull($b->getParent());
    }

    public function testUnwrappingAChildMovesItsChildren(): void
    {
        $root = new Document();
        $wrapper = new Paragraph();
        $text = new Text('text');
        $wrapper->appendChild($text);
        $root->appendChild($wrapper);
        $this->assertTrue($root->replaceChildWithMany($wrapper, $wrapper->getChildren()));
        $this->assertSame([$text], $root->getChildren());
        $this->assertSame([], $wrapper->getChildren());
        $this->assertNull($wrapper->getParent());
        $this->assertSame($root, $text->getParent());
    }

    public function testReplacementCanMoveASiblingFromBeforeTheTarget(): void
    {
        $parent = new Paragraph();
        $a = new Text('a');
        $b = new Text('b');
        $c = new Text('c');
        $parent->setChildren([$a, $b, $c]);
        $parent->replaceChild(2, $a);
        $this->assertSame([$b, $a], $parent->getChildren());
        $this->assertNull($c->getParent());
        $this->assertSame($parent, $a->getParent());
    }

    public function testDuplicateBulkChildrenAreRejectedBeforeMutation(): void
    {
        $parent = new Paragraph();
        $child = new Text('child');
        $parent->appendChild($child);
        try {
            $parent->setChildren([$child, $child]);
            $this->fail('Duplicate children were accepted');
        } catch (InvalidArgumentException) {
            $this->assertSame([$child], $parent->getChildren());
            $this->assertSame($parent, $child->getParent());
        }
    }

    public function testCyclesAreRejectedBeforeMovingChildren(): void
    {
        $root = new Document();
        $parent = new Paragraph();
        $text = new Text('child');
        $root->appendChild($parent);
        $parent->appendChild($text);
        foreach ([$parent, $root] as $invalid) {
            try {
                $parent->setChildren([$invalid]);
                $this->fail('A cycle was accepted');
            } catch (InvalidArgumentException) {
                $this->assertSame([$parent], $root->getChildren());
                $this->assertSame([$text], $parent->getChildren());
                $this->assertSame($root, $parent->getParent());
            }
        }
    }
}
