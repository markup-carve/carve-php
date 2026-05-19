<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Node\Inline\Mention;
use function str_replace;

/**
 * Parses @mentions and #tags into links.
 *
 * Carve treats both as core social syntax (enabled by default):
 *
 *     Hey @alice, see #release-1.0.
 *     <p>Hey <a class="mention" href="/users/alice">@alice</a>,
 *        see <a class="tag" href="/tags/release-1.0">#release-1.0</a>.</p>
 *
 * URL templates use the {name} placeholder. Both are configurable.
 */
class MentionsExtension implements ExtensionInterface
{
    /**
     * @param string $mentionUrl URL template for @mentions ({name} placeholder)
     * @param string $tagUrl URL template for #tags ({name} placeholder)
     * @param string $mentionClass CSS class for mention links
     * @param string $tagClass CSS class for tag links
     */
    public function __construct(
        protected string $mentionUrl = '/users/{name}',
        protected string $tagUrl = '/tags/{name}',
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
            '/(?<![A-Za-z0-9_])@([a-zA-Z0-9_-]+)/',
            function (string $match, array $groups) use ($mentionUrl, $mentionClass): Mention {
                $name = $groups[1];

                return new Mention(
                    $mentionClass,
                    str_replace('{name}', $name, $mentionUrl),
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
                    str_replace('{name}', $name, $tagUrl),
                    '#' . $name,
                );
            },
        );
    }
}
