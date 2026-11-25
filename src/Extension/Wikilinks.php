<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Provisional. The standard `:wiki[Page#anchor|Display]` matcher (C2-X-057) with a link renderer.
 */
final class Wikilinks
{
    /**
     * @param (\Closure(string): string)|null $urlGenerator Maps a page name to a URL; the anchor is appended afterwards.
     *   Default: a lowercase hyphen slug.
     * @param string $cssClass Classes of the link.
     * @param bool $newWindow Open links in a new tab (`target="_blank" rel="noopener"`).
     *
     * @return \MarkupCarve\Carve\Extension\Bundle
     */
    public static function bundle(?Closure $urlGenerator = null, string $cssClass = 'wikilink', bool $newWindow = false): Bundle
    {
        $generate = $urlGenerator ?? self::slug(...);
        $classes = array_values(array_filter(explode(' ', $cssClass), static fn (string $c): bool => $c !== ''));
        $link = static function (Node $node, RenderHost $host) use ($generate, $classes, $newWindow): string {
            $fallback = PlainText::nodes($node->f['fallback'] ?? []);
            $props = $node->f['props'] ?? [];
            $target = is_array($props) && is_string($props['target'] ?? null) ? $props['target'] : ($host->plainText)($fallback);
            [$page, $anchor] = self::target($target);
            $href = $generate($page) . $anchor;
            // C2-X-057: the target becomes a link only through the URL policy (C2-H-159).
            if ($page === '' && $anchor === '' || !$host->isUrlAllowed($href)) {
                return ($host->inlines)($fallback);
            }
            // The generated href and pairs replace authored ones, so `{rel=opener}` cannot undo `noopener`.
            $generated = [['data-wikilink', $page], ...($newWindow ? [['target', '_blank'], ['rel', 'noopener']] : [])];
            $names = ['href' => true];
            foreach ($generated as [$name]) {
                $names[$name] = true;
            }
            $authored = $node->f['attrs'] ?? null;
            $attrs = $authored instanceof Attrs ? clone $authored : new Attrs();
            $kept = array_values(array_filter($attrs->pairs, static fn (array $p): bool => !isset($names[strtolower($p[0])])));
            $attrs->classes = [...$classes, ...$attrs->classes];
            $attrs->pairs = [$generated[0], ...$kept, ...array_slice($generated, 1)];

            return '<a href="' . RenderHost::escapeAttribute($href) . '"' . AuthorAttrs::write($attrs, $host) . '>'
                . RenderHost::escapeText(self::linkText($node, $host, $page, $anchor)) . '</a>';
        };
        $definition = Standard::definitions('carve.wiki')[0] ?? null;
        assert($definition instanceof ExtensionDefinition);

        return new Bundle([$definition->withRenderer($link)->withBuildTimeRenderer($link)]);
    }

    public static function slug(string $page): string
    {
        $slug = (string)preg_replace('/\s+/', '-', strtolower(trim($page)));
        $slug = (string)preg_replace('~[^a-z0-9\-_/]~', '', $slug);

        return (string)preg_replace('/-+/', '-', $slug);
    }

    /**
     * The display text, or the page (else the anchor) when the inline source has no `|`.
     */
    private static function linkText(Node $node, RenderHost $host, string $page, string $anchor): string
    {
        $source = $node->f['source'] ?? [];
        $body = is_array($source) && ($source['form'] ?? null) === 'inline' ? ($source['body'] ?? null) : null;
        if (is_string($body) && !str_contains($body, '|')) {
            return $page === '' ? $anchor : $page;
        }

        return ($host->plainText)(PlainText::nodes($node->f['fallback'] ?? []));
    }

    /**
     * `Page#anchor` (anything after a `|` ignored): the page and the anchor with its `#`, each possibly empty.
     *
     * @return array{0: string, 1: string}
     */
    private static function target(string $text): array
    {
        $bar = strpos($text, '|');
        $path = trim($bar === false ? $text : substr($text, 0, $bar));
        $hash = strpos($path, '#');

        return [
            $hash === false ? $path : trim(substr($path, 0, $hash)),
            $hash === false ? '' : '#' . substr($path, $hash + 1),
        ];
    }
}
