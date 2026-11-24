<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Provisional. The navigation extensions: each factory returns one `ExtensionDefinition` with a document transform
 * (C2-X-053) that `render()` runs. The table of contents also writes through its renderer (C2-X-052).
 */
final class Navigation
{
    /**
     * @var string
     */
    public const PERMALINKS = 'carve.headingPermalinks';

    /**
     * @var string
     */
    public const NUMBERS = 'carve.headingNumbers';

    /**
     * @var array<string>
     */
    private const NOT_LINKED = ['link', 'autolink', 'image', 'xref', 'code', 'raw_inline', 'math', 'literal_inline'];

    /**
     * Adds a link to its own id to each heading that has one. The anchor is a core link, so no renderer is needed.
     *
     * @param string $symbol Anchor text.
     * @param string $cssClass Class of the anchor.
     * @param string $ariaLabel `aria-label` of the anchor.
     * @param array<int> $levels Heading levels that get an anchor.
     * @param bool $prepend Puts the anchor before the heading text.
     * @param bool $showOnHover Wraps the anchor in `<span class="permalink-wrapper permalink-hover">`.
     * @param bool $copyToClipboard Adds a `data-permalink-copy` hook for host script.
     *
     * @return \MarkupCarve\Carve\Extension\ExtensionDefinition
     */
    public static function headingPermalinks(
        string $symbol = '¶',
        string $cssClass = 'permalink',
        string $ariaLabel = 'Permalink',
        array $levels = [1, 2, 3, 4, 5, 6],
        bool $prepend = false,
        bool $showOnHover = false,
        bool $copyToClipboard = false,
    ): ExtensionDefinition {
        $run = static function (Node $view) use ($symbol, $cssClass, $ariaLabel, $levels, $prepend, $showOnHover, $copyToClipboard): Node {
            NavTree::declare($view, self::PERMALINKS);
            NavTree::freezeReferences($view, self::PERMALINKS);
            $texts = NavTree::headingTexts($view);
            $anchor = static function () use ($symbol, $cssClass, $ariaLabel, $showOnHover, $copyToClipboard): Node {
                return self::anchor($symbol, $cssClass, $ariaLabel, $showOnHover, $copyToClipboard);
            };
            NavTree::visit($view, static function (Node $node) use ($levels, $prepend, $anchor): void {
                if ($node->type !== 'heading' || !in_array($node->f['level'] ?? 0, $levels, true)) {
                    return;
                }
                $id = NavTree::headingId($node);
                if ($id === null) {
                    return;
                }
                NavTree::pinId($node);
                $link = $anchor();
                $target = $link->type === 'link' ? $link : ($link->children[0] ?? $link);
                $target->f['href'] = '#' . $id;
                $space = NavTree::chrome(NavTree::text(' '), self::PERMALINKS);
                NavTree::chrome($link, self::PERMALINKS);
                $node->children = $prepend ? [$link, $space, ...($node->children ?? [])] : [...($node->children ?? []), $space, $link];
            }, true);
            NavTree::freezeCrossReferences($view, $texts);

            return $view;
        };

        return new ExtensionDefinition(self::PERMALINKS, '1', documentTransform: $run);
    }

    private static function anchor(string $symbol, string $cssClass, string $ariaLabel, bool $showOnHover, bool $copyToClipboard): Node
    {
        $pairs = [['aria-label', $ariaLabel]];
        if ($copyToClipboard) {
            $pairs[] = ['data-permalink-copy', ''];
        }
        $anchor = NavTree::link('', [NavTree::text($symbol)], Apparatus::attrs(classes: [$cssClass], pairs: $pairs));
        if (!$showOnHover) {
            return $anchor;
        }
        $wrapper = new Node('span', [$anchor]);
        $wrapper->f['attrs'] = Apparatus::attrs(classes: ['permalink-wrapper', 'permalink-hover']);

        return $wrapper;
    }

    /**
     * Numbers the sections, `1`, `1.1` and so on, in a `<span class="section-number">` before the heading text, and
     * writes a cross-reference `</#id>` to a numbered heading as "Section 1.2 - Title". A number follows nesting depth,
     * not the level, so a skipped level repeats no number. Only the headings a section wraps are numbered: headings in a
     * quote, note, footnote or list item, with the class `unnumbered` or above `$minLevel` take no number.
     *
     * @param int $minLevel Top numbered heading level.
     * @param string $label Word before the number in a cross-reference.
     * @param string $crossref `number`, `number-title` or `title`.
     *
     * @return \MarkupCarve\Carve\Extension\ExtensionDefinition
     */
    public static function headingNumbers(
        int $minLevel = 1,
        string $label = 'Section',
        string $crossref = 'number-title',
    ): ExtensionDefinition {
        $run = static function (Node $view) use ($minLevel, $label, $crossref): Node {
            NavTree::declare($view, self::NUMBERS);
            NavTree::freezeReferences($view, self::NUMBERS);
            $texts = NavTree::headingTexts($view);
            $byId = self::number($view, $minLevel);
            if ($crossref !== 'title' && $byId !== []) {
                NavTree::rewrite($view, static function (Node $node) use ($byId, $label, $crossref): ?array {
                    if (!NavTree::isTitleXref($node)) {
                        return null;
                    }
                    $key = PlainText::str($node->f['key'] ?? '');
                    $target = $byId[$key] ?? null;
                    if ($target === null) {
                        return null;
                    }
                    $children = $crossref === 'number'
                        ? [NavTree::text($label . ' ' . $target['number'])]
                        : [NavTree::text($label . ' ' . $target['number'] . ' - '), ...array_map(Extensions::copy(...), $target['title'])];

                    return [NavTree::link('#' . $key, $children, NavTree::attrs($node))];
                }, true, visitReplacements: false);
            }
            NavTree::freezeCrossReferences($view, $texts);

            return $view;
        };

        return new ExtensionDefinition(self::NUMBERS, '1', documentTransform: $run);
    }

    /**
     * Writes the section numbers and returns the number and title of each heading id.
     *
     * @return array<string, array{number: string, title: array<\MarkupCarve\Carve\Node>}>
     */
    private static function number(Node $view, int $minLevel): array
    {
        $levels = [];
        $numbers = [];
        $byId = [];
        // carve#6531: only the headings a section wraps (C2-H-026), numbered by depth, not level.
        foreach ($view->children ?? [] as $node) {
            if ($node->type !== 'heading') {
                continue;
            }
            $level = $node->f['level'] ?? 1;
            $level = is_int($level) ? $level : 1;
            if (in_array('unnumbered', NavTree::attrs($node)->classes ?? [], true) || $level < $minLevel) {
                continue;
            }
            $closed = null;
            while ($levels !== [] && (int)end($levels) > $level) {
                array_pop($levels);
                $closed = array_pop($numbers);
            }
            if ($levels !== [] && $levels[count($levels) - 1] === $level) {
                $numbers[count($numbers) - 1]++;
            } else {
                $levels[] = $level;
                $numbers[] = $closed === null ? 1 : $closed + 1;
            }
            $number = implode('.', $numbers);
            $id = NavTree::headingId($node);
            if ($id !== null && !isset($byId[$id])) {
                $byId[$id] = ['number' => $number, 'title' => NavTree::derivedInlines($node->children ?? [])];
            }
            NavTree::pinId($node);
            $span = new Node('span', [NavTree::text($number)]);
            $span->f['attrs'] = Apparatus::attrs(classes: ['section-number']);
            $space = NavTree::chrome(NavTree::text(' '), self::NUMBERS);
            $node->children = [NavTree::chrome($span, self::NUMBERS), $space, ...($node->children ?? [])];
        }

        return $byId;
    }

    /**
     * Gives the class `heading-ref` to each reference link a heading resolves (`[Heading][]`, `[text][Heading]`,
     * C2-H-083). The 0.1 `[[Heading]]` spelling is gone: 0.2 adds no delimiter for it (C2-X-013, C2-X-057).
     */
    public static function headingReference(string $cssClass = 'heading-ref'): ExtensionDefinition
    {
        $classes = array_values(array_filter(explode(' ', $cssClass), static fn (string $c): bool => $c !== ''));
        $run = static function (Node $view) use ($classes): Node {
            foreach (NavTree::headingReferences($view) as $link) {
                $attrs = NavTree::attrs($link);
                $attrs = $attrs === null ? new Attrs() : clone $attrs;
                $own = $attrs->classes;
                $added = array_values(array_filter($classes, static fn (string $c): bool => !in_array($c, $own, true)));
                $attrs->classes = [...$own, ...$added];
                $link->f['attrs'] = $attrs;
            }

            return $view;
        };

        return new ExtensionDefinition('carve.headingReference', '1', documentTransform: $run);
    }

    /**
     * Sets `target` and `rel` on each link and autolink to an `http` or `https` URL.
     *
     * @param string $target `''` removes it.
     * @param string $rel
     * @param bool $nofollow Adds `nofollow` to `rel`.
     *
     * @return \MarkupCarve\Carve\Extension\ExtensionDefinition
     */
    public static function externalLinks(
        string $target = '_blank',
        string $rel = 'noopener noreferrer',
        bool $nofollow = false,
    ): ExtensionDefinition {
        $rels = array_values(array_filter(preg_split('/\s+/u', $rel) ?: [], static fn (string $p): bool => $p !== ''));
        if ($nofollow && !in_array('nofollow', $rels, true)) {
            $rels[] = 'nofollow';
        }
        $run = static function (Node $view) use ($target, $rels): Node {
            NavTree::rewrite($view, static function (Node $node) use ($target, $rels): ?array {
                $href = $node->f['href'] ?? null;
                $linkish = $node->type === 'link' || $node->type === 'autolink';
                if (!$linkish || !is_string($href) || preg_match('~^https?://~iu', $href) !== 1) {
                    return null;
                }
                $link = $node->type === 'autolink' ? LinkPolicy::asLink($node) : $node;
                if ($target === '') {
                    NavTree::removePair($link, 'target');
                } else {
                    NavTree::setPair($link, 'target', $target);
                }
                NavTree::setPair($link, 'rel', implode(' ', $rels));

                return $node === $link ? null : [$link];
            }, true);

            return $view;
        };

        return new ExtensionDefinition('carve.externalLinks', '1', documentTransform: $run);
    }

    /**
     * Applies a link policy to the render view: a link, autolink or image whose URL the policy refuses is written as
     * its text, and each link that stays gets the `$rel` values. It narrows the core URL policy (C2-H-159), never widens
     * it. A host is read as a browser reads it, and a bare relative path is internal.
     *
     * @param array<string>|null $allowedSchemes Schemes a link may use; null allows every scheme that is not denied.
     * @param array<string> $deniedSchemes
     * @param array<string>|null $allowedDomains Hosts an `http` or `https` link may point to, with their subdomains.
     * @param array<string> $deniedDomains Hosts, with their subdomains, a link may not point to.
     * @param bool $allowExternal Allows links to other hosts than `$baseHost`.
     * @param bool $allowInternal Allows fragments and relative URLs.
     * @param array<string> $rel Values added to the `rel` of each link that stays.
     * @param string|null $baseHost The host of the document, which decides what is internal.
     *
     * @return \MarkupCarve\Carve\Extension\ExtensionDefinition
     */
    public static function linkPolicy(
        ?array $allowedSchemes = null,
        array $deniedSchemes = ['javascript', 'vbscript', 'data', 'file'],
        ?array $allowedDomains = null,
        array $deniedDomains = [],
        bool $allowExternal = true,
        bool $allowInternal = true,
        array $rel = [],
        ?string $baseHost = null,
    ): ExtensionDefinition {
        $policy = new LinkPolicy(
            $allowedSchemes,
            $deniedSchemes,
            $allowedDomains,
            $deniedDomains,
            $allowExternal,
            $allowInternal,
            $baseHost,
        );
        $run = static fn (Node $view): Node => $policy->apply($view, $rel);

        return new ExtensionDefinition('carve.linkPolicy', '1', documentTransform: $run);
    }

    /**
     * Links bare URLs and email addresses in the text of the render view; angle autolinks `<url>` are core, and this
     * adds no delimiter (C2-X-013). Trailing sentence punctuation stays outside the link.
     *
     * @param array<string> $allowedSchemes Schemes linked in bare text; `mailto` also links bare email addresses.
     *
     * @return \MarkupCarve\Carve\Extension\ExtensionDefinition
     */
    public static function autolink(array $allowedSchemes = ['https', 'http', 'mailto']): ExtensionDefinition
    {
        $pattern = LinkPolicy::autolinkPattern($allowedSchemes);
        $run = static function (Node $view) use ($pattern): Node {
            if ($pattern === null) {
                return $view;
            }
            NavTree::rewrite($view, static function (Node $node, array $ancestors) use ($pattern): ?array {
                if ($node->type !== 'text') {
                    return null;
                }
                foreach ($ancestors as $above) {
                    if (in_array($above->type, self::NOT_LINKED, true)) {
                        return null;
                    }
                }

                return LinkPolicy::linked(PlainText::str($node->f['value'] ?? ''), $pattern);
            }, true);

            return $view;
        };

        return new ExtensionDefinition('carve.autolink', '1', documentTransform: $run);
    }

    /**
     * A table of contents of the top-level headings, inserted at the top or bottom of the render view, as `carve.toc`
     * with the list of links as its fallback; the renderer writes it inside a named `<nav>`.
     *
     * @param int $minLevel Lowest heading level listed (1-6).
     * @param int $maxLevel Highest heading level listed (1-6).
     * @param string $listType `ul` or `ol`.
     * @param string $cssClass Class of the `<nav>`.
     * @param string $position `top` or `bottom`.
     * @param bool $collapsible Writes a `<details>` disclosure instead of the `<nav>`.
     * @param string $summary Summary of the disclosure.
     * @param bool $open Opens the disclosure.
     *
     * @return \MarkupCarve\Carve\Extension\ExtensionDefinition
     */
    public static function tableOfContents(
        int $minLevel = 1,
        int $maxLevel = 6,
        string $listType = 'ul',
        string $cssClass = 'toc',
        string $position = 'top',
        bool $collapsible = false,
        string $summary = 'Table of Contents',
        bool $open = false,
    ): ExtensionDefinition {
        return Toc::tableOfContents(
            Toc::clamp($minLevel),
            Toc::clamp($maxLevel),
            $listType === 'ol',
            $cssClass,
            $position === 'bottom',
            $collapsible ? ['collapsible' => true, 'summary' => $summary] + ($open ? ['open' => true] : []) : [],
        );
    }

    /**
     * Writes a table of contents at each `toc` directive, claimed as `carve.tocPlacement`; its headings window comes from
     * `{depth=N}` or `{from=X to=Y}`, and like `tableOfContents()` it lists only the headings a section wraps. Without
     * this extension the directive stays the core `<div class="toc">`.
     */
    public static function tocPlacement(): ExtensionDefinition
    {
        return Toc::placement();
    }
}
