<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Raw HTML of Markdown stays raw, as the 0.1 importer kept it: a block behind a placeholder, inline HTML as `data-carve-raw`.
 */
final class RawHtmlRenderer implements NodeRendererInterface
{
    public function __construct(private Sink $sink)
    {
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if ($node instanceof HtmlBlock) {
            return $this->sink->rawBlock('html', $node->getLiteral());
        }

        return Sink::rawInline('html', $node instanceof HtmlInline ? $node->getLiteral() : '');
    }
}
