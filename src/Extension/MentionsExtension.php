<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use Throwable;
use WeakMap;
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
class MentionsExtension implements BeforeRenderExtensionInterface
{
    protected ?Closure $mentionResolver;

    protected ?Closure $tagResolver;

    protected mixed $resolverContext;

    /**
     * @var \WeakMap<\MarkupCarve\Carve\Node\Inline\Mention, array{\Closure, string, string, mixed, bool}>
     */
    protected WeakMap $resolverEntries;

    /**
     * @param string $mentionUrl URL template for @mentions ({name} placeholder); empty = non-link span
     * @param string $tagUrl URL template for #tags ({name} placeholder); empty = non-link span
     * @param string $mentionClass CSS class for mentions
     * @param string $tagClass CSS class for tags
     * @param callable|null $mentionResolver authoritative mention resolver
     * @param callable|null $tagResolver authoritative tag resolver
     * @param mixed $resolverContext
     */
    public function __construct(
        protected string $mentionUrl = '',
        protected string $tagUrl = '',
        protected string $mentionClass = 'mention',
        protected string $tagClass = 'tag',
        ?callable $mentionResolver = null,
        ?callable $tagResolver = null,
        mixed $resolverContext = null,
    ) {
        $this->mentionResolver = $mentionResolver === null ? null : Closure::fromCallable($mentionResolver);
        $this->tagResolver = $tagResolver === null ? null : Closure::fromCallable($tagResolver);
        $this->resolverContext = $resolverContext;
        $this->resolverEntries = new WeakMap();
    }

    public function register(CarveConverter $converter): void
    {
        $this->resolverEntries = new WeakMap();
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

                $node = new Mention(
                    $mentionClass,
                    $this->mentionResolver === null
                        ? str_replace('{name}', rawurlencode($name), $mentionUrl)
                        : '',
                    '@' . $name,
                );
                if ($this->mentionResolver !== null) {
                    $this->resolverEntries[$node] = [$this->mentionResolver, 'mention', $name, $this->resolverContext, false];
                }

                return $node;
            },
        );

        $tagUrl = $this->tagUrl;
        $tagClass = $this->tagClass;
        $inlineParser->addInlinePattern(
            '/(?<![A-Za-z0-9_])#([a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*)/',
            function (string $match, array $groups) use ($tagUrl, $tagClass): Mention {
                $name = $groups[1];

                $node = new Mention(
                    $tagClass,
                    $this->tagResolver === null
                        ? str_replace('{name}', rawurlencode($name), $tagUrl)
                        : '',
                    '#' . $name,
                );
                if ($this->tagResolver !== null) {
                    $this->resolverEntries[$node] = [$this->tagResolver, 'tag', $name, $this->resolverContext, false];
                }

                return $node;
            },
        );
    }

    public function beforeRender(Document $document, BeforeRenderContext $context): Document
    {
        $this->resolveSocialLinks($document);

        return $document;
    }

    protected function resolveSocialLinks(Node $node): void
    {
        if ($node instanceof Mention) {
            $destination = $this->resolveSocialDestination($node) ?? '';
            $node->setDestination(HtmlRenderer::blankDangerousScheme($destination));
        }
        foreach ($node->getChildren() as $child) {
            $this->resolveSocialLinks($child);
        }
    }

    protected function resolveSocialDestination(Mention $node): ?string
    {
        $entry = $this->resolverEntries[$node] ?? null;
        if ($entry === null || $entry[4]) {
            return $node->getDestination();
        }
        $entry[4] = true;
        $this->resolverEntries[$node] = $entry;
        try {
            $destination = ($entry[0])(new SocialLinkResolverInput(
                $entry[1],
                $entry[2],
                $node->getAttributes(),
                $entry[3],
            ));
            $node->setDestination(is_string($destination) ? $destination : '');
        } catch (Throwable) {
            $node->setDestination('');
        }

        return $node->getDestination();
    }
}
