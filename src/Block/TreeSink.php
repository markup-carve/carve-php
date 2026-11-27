<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Block;

use MarkupCarve\Carve\Diagnostics\Findings;
use MarkupCarve\Carve\Diagnostics\ParseMessages;
use MarkupCarve\Carve\Node;

/**
 * The direct shape: the block pass builds the tree nodes as it goes.
 */
final class TreeSink implements Sink
{
    public ?Node $root = null;

    public Findings $findings;

    public bool $hasInclude = false;

    /**
     * Whether a block node with a definition key (a link, footnote or abbreviation definition) was made.
     */
    public bool $hasDefinition = false;

    /**
     * Whether a block snippet use was made (C2-B-237).
     */
    public bool $hasSnippetUse = false;

    /**
     * Whether a snippet definition was made (C2-B-236).
     */
    public bool $hasSnippetDefinition = false;

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
        if (isset($node->f['key'])) {
            $this->hasDefinition = true;
        }
        $frame->handle = $node;
        if ($frame === $parent) {
            $this->root = $node;

            return;
        }
        /** @var \MarkupCarve\Carve\Node $parentNode */
        $parentNode = $parent->handle;
        $parentNode->children[] = $node;
    }

    /**
     * @inheritDoc
     */
    public function leaf(Frame $frame, Frame $parent): void
    {
        /** @var \MarkupCarve\Carve\Node $parentNode */
        $parentNode = $parent->handle;
        $node = NodeFactory::make($frame, $this->s);
        if ($node->type === 'include') {
            $this->hasInclude = true;
        }
        if (isset($node->f['key'])) {
            $this->hasDefinition = true;
        }
        if ($frame->kind === Frame::SNIPPET_USE) {
            $this->hasSnippetUse = true;
        } elseif ($frame->kind === Frame::SNIPPET_DEF) {
            $this->hasSnippetDefinition = true;
        }
        $frame->handle = $node;
        $this->note($frame, $node);
        if (!$frame->closed && isset(self::UNCLOSED[$frame->kind])) {
            $code = self::UNCLOSED[$frame->kind];
            $this->findings->add($code, $node, message: ParseMessages::of($code, $this->s, $node, -1));
        }
        if ($frame->kind === Frame::LINE_BLOCK) {
            $this->unclosedLineBlockComments($frame, $node);
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
     * C2-B-111, C2-B-123: a comment block in a line block body that is still open when the line block ends.
     *
     * @param \MarkupCarve\Carve\Block\Frame $frame
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return void
     */
    private function unclosedLineBlockComments(Frame $frame, Node $node): void
    {
        $open = [];
        foreach ([...$frame->hiddenComments, $frame->lineComment] as $comment) {
            if ($comment !== null && !$comment->closed) {
                $open[$comment->start] = true;
            }
        }
        if ($open === []) {
            return;
        }
        $code = 'unclosed-comment-fence';
        foreach ($node->children ?? [] as $child) {
            if ($child->type === 'comment' && isset($open[$child->start])) {
                $this->findings->add($code, $child, message: ParseMessages::of($code, $this->s, $child, -1));
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function exit(Frame $frame): void
    {
        /** @var \MarkupCarve\Carve\Node $node */
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
        if ($node->type === 'figure_group' || ($node->type === 'table' && $target->delimited === '')) {
            // C2-A-082, C2-B-138: a pipe table or a figure group takes the comments above its caption; behind a
            // delimited table, whose fence has closed, and any other block they stay siblings.
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
     * @param \MarkupCarve\Carve\Block\Frame $frame
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return void
     */
    private function note(Frame $frame, Node $node): void
    {
        foreach ($frame->findings as [$code, $from]) {
            $end = $from >= 0 ? $from + 1 : -1;
            $start = $from;
            if ($code === 'fence-title-syntax') {
                $end = strpos($this->s, "\n", $from);
                $end = $end === false ? strlen($this->s) : $end;
                if ($end > $from && $this->s[$end - 1] === "\r") {
                    $end--;
                }
                // C2-B-122: the finding starts at the further words; only a tab glued to a code fence run is itself reported.
                if ($node->type !== 'code_block' || $this->s[$from] !== "\t" || ($this->s[$from - 1] ?? '') !== '`') {
                    $start += strspn($this->s, " \t", $from, $end - $from);
                }
            }
            if ($code === 'fence-opener-fallback' && $from >= 0 && $this->s[$from] === '{') {
                $close = strpos($this->s, '}', $from);
                if ($close !== false) {
                    $end = $close + 1;
                }
            }
            $this->findings->add(
                $code,
                $node,
                start: $start,
                end: $end,
                message: ParseMessages::of($code, $this->s, $node, $from),
            );
        }
    }
}
