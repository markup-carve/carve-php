<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Node\Inline\Mention;
use function str_replace;

/**
 * Parses @mentions and #tags as core Carve social syntax (enabled by default).
 *
 * By default both render as non-link spans:
 *
 *     Hey @alice, see #release-1.0.
 *     <p>Hey <span class="mention"><strong>@alice</strong></span>,
 *        see <span class="tag"><strong>#release-1.0</strong></span>.</p>
 *
 * Pass a URL template (with the {name} placeholder) to render links instead:
 *
 *     new MentionsExtension(mentionUrl: '/users/{name}', tagUrl: '/tags/{name}')
 *     <p>Hey <a class="mention" href="/users/alice">@alice</a>, …</p>
 */
class MentionsExtension implements ExtensionInterface
{
    /**
     * @param string $mentionUrl URL template for @mentions ({name} placeholder); empty = non-link span
     * @param string $tagUrl URL template for #tags ({name} placeholder); empty = non-link span
     * @param string $mentionClass CSS class for mentions
     * @param string $tagClass CSS class for tags
     */
    public function __construct(
        protected string $mentionUrl = '',
        protected string $tagUrl = '',
        protected string $mentionClass = 'mention',
        protected string $tagClass = 'tag',
    ) {
    }

    public function register(CarveConverter $converter): void
    {
        $inlineParser = $converter->getParser()->getInlineParser();

        $mentionUrl = $this->mentionUrl;
        $mentionClass = $this->mentionClass;
        $inlineParser->addInlinePattern(
            // Interior dots are part of the name (a dot followed by another
            // name character, `@john.doe`); a trailing dot stays sentence
            // punctuation. Same shape as the tag pattern below (grammar
            // PART 9 §7; corpus 89-mention-and-tag-name-boundaries).
            '/(?<![A-Za-z0-9_])@([a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*)/',
            function (string $match, array $groups) use ($mentionUrl, $mentionClass): Mention {
                $name = $groups[1];

                return new Mention(
                    $mentionClass,
                    str_replace('{name}', rawurlencode($name), $mentionUrl),
                    '@' . $name,
                );
            },
        );

        $tagUrl = $this->tagUrl;
        $tagClass = $this->tagClass;
        $inlineParser->addInlinePattern(
            '/(?<![A-Za-z0-9_])#([a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*)/',
            function (string $match, array $groups) use ($tagUrl, $tagClass): Mention {
                $name = $groups[1];

                return new Mention(
                    $tagClass,
                    str_replace('{name}', rawurlencode($name), $tagUrl),
                    '#' . $name,
                );
            },
        );
    }
}
