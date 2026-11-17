<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

use MarkupCarve\Carve2\Diagnostics\Findings;
use MarkupCarve\Carve2\Diagnostics\ParseMessages;
use MarkupCarve\Carve2\Node;

/**
 * The direct shape: the block pass builds the tree nodes as it goes.
 */
final class TreeSink implements Sink
{
    public ?Node $root = null;

    public Findings $findings;

    public bool $hasInclude = false;

    /**
     * C2-B-111, C2-B-163: the leaves that report a missing closer.
     *
     * @var array<int, string>
     */
    private const UNCLOSED = [
        Frame::CODE => 'unclosed-code-fence',
        Frame::COMMENT_BLOCK => 'unclosed-comment-fence',
        Frame::LINE_BLOCK => 'unclosed-container-fence',
        Frame::FRONTMATTER => 'unclosed-frontmatter',
    ];

    /**
     * @param string $s
     */
    public function __construct(private string $s)
    {
        $this->findings = new Findings();
    }

    /**
     * @inheritDoc
     */
    public function enter(Frame $frame, Frame $parent): void
    {
        $node = NodeFactory::make($frame, $this->s);
        if ($node->type === 'include') {
            $this->hasInclude = true;
        }
        $frame->handle = $node;
        if ($frame === $parent) {
            $this->root = $node;

            return;
        }
        /** @var \MarkupCarve\Carve2\Node $parentNode */
        $parentNode = $parent->handle;
        $parentNode->children[] = $node;
    }

    /**
     * @inheritDoc
     */
    public function leaf(Frame $frame, Frame $parent): void
    {
        /** @var \MarkupCarve\Carve2\Node $parentNode */
        $parentNode = $parent->handle;
        $node = NodeFactory::make($frame, $this->s);
        if ($node->type === 'include') {
            $this->hasInclude = true;
        }
        $frame->handle = $node;
        $this->note($frame, $node);
        if (!$frame->closed && isset(self::UNCLOSED[$frame->kind])) {
            $code = self::UNCLOSED[$frame->kind];
            $this->findings->add($code, $node, message: ParseMessages::of($code, $this->s, $node, -1));
        }
        $at = count($parentNode->children ?? []);
        // C2-B-091: a block placed by its own start, not by the attribute lines it took across a comment line.
        $key = $frame->own >= 0 ? $frame->own : $node->start;
        if ($at === 0 || $parentNode->children[$at - 1]->start <= $key) {
            $parentNode->children[] = $node;

            return;
        }
        $children = $parentNode->children;
        while ($at > 0 && $children[$at - 1]->start > $key) {
            $at--;
        }
        array_splice($children, $at, 0, [$node]);
        $parentNode->children = $children;
    }

    /**
     * @inheritDoc
     */
    public function exit(Frame $frame): void
    {
        /** @var \MarkupCarve\Carve2\Node $node */
        $node = $frame->handle;
        NodeFactory::finish($node, $frame);
        if ($frame->kind === Frame::DIV) {
            $this->note($frame, $node);
            if (!$frame->closed) {
                // C2-B-111, C2-B-132
                $code = 'unclosed-container-fence';
                $this->findings->add($code, $node, message: ParseMessages::of($code, $this->s, $node, -1));
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function caption(Frame $caption, Frame $parent, Frame $target): void
    {
        $parentNode = $parent->handle;
        $node = $target->handle;
        assert($parentNode instanceof Node && $node instanceof Node && $parentNode->children !== null);
        $children = $parentNode->children;
        $at = count($children) - 1;
        while ($at >= 0 && $children[$at] !== $node) {
            $at--;
        }
        if ($at < 0) {
            return;
        }
        $after = array_slice($children, $at + 1);
        $cap = Node::at('caption', $caption->start, $caption->end);
        $para = Node::at('paragraph', $caption->spans[0], $caption->end);
        $para->lines = $caption->spans;
        $cap->children = [$para];
        if ($node->type !== 'code_block') {
            // C2-A-082: comments below an open block stay inside that block.
            foreach ($after as $comment) {
                $node->children[] = $comment;
            }
            $parentNode->children = array_slice($children, 0, $at + 1);
        }
        $node->f['caption'] = $cap;
        $node->end = $caption->end;
        $node->ownStart = $target->own;
    }

    /**
     * @param \MarkupCarve\Carve2\Block\Frame $frame
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private function note(Frame $frame, Node $node): void
    {
        foreach ($frame->findings as [$code, $from]) {
            $this->findings->add($code, $node, message: ParseMessages::of($code, $this->s, $node, $from));
        }
    }
}
