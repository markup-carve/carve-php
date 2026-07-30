<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

use MarkupCarve\Carve\Node\ContentNodeInterface;

/**
 * Editorial comment {# ... #}
 *
 * Its own node type rather than a `span` carrying a `critic-comment` class, for
 * the reason an autolink is not a link: the two are written differently and a
 * formatter has to be able to reproduce which one the author used. It is also
 * what lets a profile deny editorial comments without denying every span.
 *
 * The content is literal - spaces are preserved and nothing inside is parsed as
 * markup - so it is carried as a string rather than as child nodes.
 */
class CriticComment extends InlineNode implements ContentNodeInterface
{
    public function __construct(protected string $content = '')
    {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getType(): string
    {
        return 'critic_comment';
    }
}
