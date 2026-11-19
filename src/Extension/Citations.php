<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * Provisional. `:cite[key]` (`carve.cite`, C2-X-058) resolved against the entries of a `bibliography` or `references`
 * directive and an optional CSL-JSON pool, with a generated list of the cited entries (`carve.references`).
 */
final class Citations
{
    /**
     * @var string
     */
    public const CITE = 'carve.cite';

    /**
     * @var string
     */
    public const REFERENCES = 'carve.references';

    /**
     * The attributes the citation reads as its own options; they are not written on the element.
     *
     * @var array<string>
     */
    private const OWN = ['locator', 'prefix', 'suffix', 'mode'];

    /**
     * @param string $mode `numbered` writes `[1]`; `author-date` writes `(Author Year)`.
     * @param array<mixed>|null $bibliography An external CSL-JSON pool (decoded). Keys resolve against the document's
     *   entries first, then this pool. When it is given, even empty, each use gets an id and each listed entry
     *   back-links to its uses. The extension reads no file.
     *
     * @throws \InvalidArgumentException
     *
     * @return \MarkupCarve\Carve\Extension\Bundle
     */
    public static function bundle(string $mode = 'numbered', ?array $bibliography = null): Bundle
    {
        if ($mode !== 'numbered' && $mode !== 'author-date') {
            throw new InvalidArgumentException('citations: unknown mode ' . $mode);
        }
        $render = static fn (Node $node, RenderHost $host): string => self::render($node, $host, $mode);

        return new Bundle(
            [
                self::citeDefinition()->withRenderer($render)->withBuildTimeRenderer($render),
                Apparatus::directiveDefinition(self::REFERENCES, Apparatus::body(...)),
            ],
            [new DocumentTransform('citations', static fn (Node $view): Node => self::transform($view, $mode, $bibliography))],
        );
    }

    /**
     * C2-X-058: the registered `cite` matcher. Without the transform each citation keeps its core-text fallback.
     */
    public static function citeDefinition(): ExtensionDefinition
    {
        return new ExtensionDefinition(self::CITE, '1', matcher: ['form' => 'inline', 'name' => 'cite'], transform: self::claim(...));
    }

    public static function claim(Claim $claim): ?Node
    {
        if ($claim->form !== 'inline') {
            return null;
        }
        $text = PlainText::of($claim->node->children ?? []);
        $keys = self::keys($text);
        if ($keys === []) {
            return null;
        }
        $node = new Node('inline_extension', []);
        $node->f['name'] = self::CITE;
        $node->f['version'] = '1';
        $node->f['props'] = ['keys' => $keys];
        $node->f['fallback'] = [Apparatus::text($text)];

        return $node;
    }

    /**
     * @return array<string>
     */
    private static function keys(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $text)), static fn (string $key): bool => $key !== ''));
    }

    private static function isReferences(Node $node): bool
    {
        $kind = $node->f['kind'] ?? null;

        return $node->type === 'directive' && ($kind === 'references' || $kind === 'bibliography');
    }

    /**
     * @param \MarkupCarve\Carve\Node $view
     * @param string $mode
     * @param array<mixed>|null $bibliography
     *
     * @return \MarkupCarve\Carve\Node
     */
    public static function transform(Node $view, string $mode, ?array $bibliography): Node
    {
        $entries = self::collect($view, $bibliography ?? []);
        [$order, $refIds, $useIds] = self::number($view, $entries, $mode, $bibliography !== null);
        $placed = $order === [];
        $claimed = false;
        $list = static fn (): Node => self::list($order, $entries, $refIds, $useIds, $mode);
        Apparatus::walk($view, static function (Node $node) use (&$placed, &$claimed, $list): ?Node {
            if (!self::isReferences($node)) {
                return null;
            }
            $body = Extensions::copy($node);
            // The directive's blocks without the description lists the entries came from.
            $kept = static fn (Node $child): bool => !self::isEntryList($child);
            $body->children = array_values(array_filter($body->children ?? [], $kept));
            if (!$placed) {
                $body->children[] = $list();
                $placed = true;
            }
            $claimed = true;

            return Apparatus::claimDirective(self::REFERENCES, $node, [$body]);
        });
        if (!$placed) {
            // No directive places the list: it goes at the end of the document, as in 0.1.
            $node = new Node('block_extension', []);
            $node->f['name'] = self::REFERENCES;
            $node->f['version'] = '1';
            $node->f['slots'] = ['body' => [$list()]];
            $node->f['fallback'] = [];
            $view->children = [...($view->children ?? []), $node];
            $claimed = true;
        }
        if ($claimed) {
            Apparatus::declare($view, self::REFERENCES);
        }

        return $view;
    }

    private static function isEntryList(Node $node): bool
    {
        if ($node->type !== 'definition_list') {
            return false;
        }
        foreach ($node->children ?? [] as $item) {
            if ($item->type === 'definition_description') {
                return true;
            }
        }

        return false;
    }

    /**
     * Numbers the keys in order of first use and writes the resolved references on each citation.
     *
     * @param \MarkupCarve\Carve\Node $view
     * @param array<string, array{blocks: array<\MarkupCarve\Carve\Node>, author?: string, year?: string}> $entries
     * @param string $mode
     * @param bool $backlinked
     *
     * @return array{0: array<string>, 1: array<string, string>, 2: array<string, array<string>>}
     */
    private static function number(Node $view, array $entries, string $mode, bool $backlinked): array
    {
        $taken = Apparatus::viewIds($view);
        $numbers = [];
        $refIds = [];
        $useIds = [];
        foreach (self::uses($view) as [$node, $keys]) {
            // A citation with an undefined key keeps its text, as in 0.1: it is no use and takes no number.
            if (array_diff($keys, array_map('strval', array_keys($entries))) !== []) {
                continue;
            }
            $suppress = Apparatus::attribute($node, 'mode') === 'suppress-author';
            $refs = [];
            foreach ($keys as $key) {
                if (!isset($numbers[$key])) {
                    $numbers[$key] = count($numbers) + 1;
                    $refIds[$key] = Apparatus::freshId('ref-' . $key, $taken);
                }
                $ref = ['key' => $key, 'href' => '#' . $refIds[$key]];
                $ref['label'] = self::label($entries[$key], $numbers[$key], $mode, $suppress);
                if ($backlinked) {
                    $useIds[$key] ??= [];
                    $ref['id'] = Apparatus::freshId('cite-' . $key . '-' . (count($useIds[$key]) + 1), $taken);
                    $useIds[$key][] = $ref['id'];
                }
                $refs[] = $ref;
            }
            $props = is_array($node->f['props'] ?? null) ? $node->f['props'] : [];
            $node->f['props'] = [...$props, 'refs' => $refs];
        }

        return [array_map('strval', array_keys($numbers)), $refIds, $useIds];
    }

    /**
     * C2-X-058: each term of a description list in the directive is a key, and its description the entry; then the
     * CSL-JSON pool for keys the document does not define.
     *
     * @param \MarkupCarve\Carve\Node $view
     * @param array<mixed> $pool
     *
     * @return array<string, array{blocks: array<\MarkupCarve\Carve\Node>, author?: string, year?: string}>
     */
    private static function collect(Node $view, array $pool): array
    {
        $entries = [];
        Apparatus::walk($view, static function (Node $node) use (&$entries): ?bool {
            if (!self::isReferences($node)) {
                return null;
            }
            foreach ($node->children ?? [] as $list) {
                if ($list->type === 'definition_list') {
                    self::listEntries($list, $entries);
                }
            }

            return false;
        });
        foreach ($pool as $csl) {
            if (is_array($csl) && is_string($csl['id'] ?? null) && !isset($entries[$csl['id']])) {
                $entries[$csl['id']] = self::cslEntry($csl);
            }
        }

        return $entries;
    }

    /**
     * The entries of one description list: each run of terms takes the description that follows it.
     *
     * @param \MarkupCarve\Carve\Node $list
     * @param array<string, array{blocks: array<\MarkupCarve\Carve\Node>, author?: string, year?: string}> $entries
     *
     * @return void
     */
    private static function listEntries(Node $list, array &$entries): void
    {
        $terms = [];
        $facts = [];
        $open = false;
        foreach ($list->children ?? [] as $item) {
            if ($item->type === 'definition_term') {
                if ($open) {
                    $terms = [];
                }
                $open = false;
                $terms[] = trim(PlainText::of($item->children ?? []));
                $facts = self::authorYear($item);
            } elseif ($item->type === 'definition_description') {
                $open = true;
                // The last entry of a duplicate key wins, as in 0.1.
                foreach ($terms as $key) {
                    if ($key !== '') {
                        $entries[$key] = ['blocks' => $item->children ?? [], ...$facts];
                    }
                }
            }
        }
    }

    /**
     * The `author` and `year` attributes the author-date mode reads, on the term or on a span around the key:
     * `:: [doe]{author="Doe" year="2020"}`.
     *
     * @return array{author?: string, year?: string}
     */
    private static function authorYear(Node $term): array
    {
        $span = null;
        foreach ($term->children ?? [] as $child) {
            if ($child->type === 'span') {
                $span = $child;

                break;
            }
        }
        $out = [];
        foreach (['author', 'year'] as $key) {
            $value = Apparatus::attribute($term, $key) ?? ($span === null ? null : Apparatus::attribute($span, $key));
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * The fixed template of 0.1: `Family, Given (Year). Title.`, with missing parts and their separators left out.
     *
     * @param array<mixed> $csl
     *
     * @return array{blocks: array<\MarkupCarve\Carve\Node>, author?: string, year?: string}
     */
    private static function cslEntry(array $csl): array
    {
        $authors = is_array($csl['author'] ?? null) && array_is_list($csl['author']) ? $csl['author'] : [];
        $year = self::cslYear($csl['issued'] ?? null);
        $text = self::cslText($authors, $year, is_string($csl['title'] ?? null) ? $csl['title'] : '');
        $entry = ['blocks' => $text === '' ? [] : [new Node('paragraph', [Apparatus::text($text)])]];
        $first = $authors[0] ?? null;
        $author = is_array($first) ? ($first['literal'] ?? $first['family'] ?? null) : null;
        if (is_string($author)) {
            $entry['author'] = $author;
        }
        if ($year !== '') {
            $entry['year'] = $year;
        }

        return $entry;
    }

    /**
     * @param array<mixed> $authors
     * @param string $title
     * @param string $year
     */
    private static function cslText(array $authors, string $year, string $title): string
    {
        $head = implode('; ', array_filter(array_map(self::formatName(...), $authors), static fn (string $n): bool => $n !== ''));
        if ($year !== '') {
            $head = $head === '' ? '(' . $year . ')' : $head . ' (' . $year . ')';
        }
        $parts = array_values(array_filter([$head, $title], static fn (string $p): bool => $p !== ''));

        return $parts === [] ? '' : implode('. ', $parts) . '.';
    }

    private static function formatName(mixed $name): string
    {
        if (!is_array($name)) {
            return '';
        }
        $literal = $name['literal'] ?? null;
        $family = $name['family'] ?? null;
        $given = $name['given'] ?? null;
        if (is_string($literal) && $literal !== '') {
            return $literal;
        }
        if (is_string($family) && is_string($given) && $given !== '') {
            return $family . ', ' . $given;
        }

        return is_string($family) ? $family : '';
    }

    private static function cslYear(mixed $issued): string
    {
        if (!is_array($issued)) {
            return '';
        }
        $parts = $issued['date-parts'] ?? null;
        $year = is_array($parts) && is_array($parts[0] ?? null) ? ($parts[0][0] ?? null) : null;
        if (is_int($year) || is_float($year)) {
            return (string)$year;
        }

        return is_string($issued['literal'] ?? null) ? $issued['literal'] : '';
    }

    /**
     * The citations in document order: the body first, then the notes, as 0.1 numbered them.
     *
     * @return array<array{0: \MarkupCarve\Carve\Node, 1: array<string>}>
     */
    private static function uses(Node $view): array
    {
        $body = [];
        $notes = [];
        $visit = static function (Node $node, bool $inBody) use (&$body, &$notes, &$visit): ?bool {
            if (self::isReferences($node)) {
                return false;
            }
            if ($inBody && ($node->type === 'footnote' || $node->type === 'inline_footnote')) {
                $note = $node->type === 'footnote' ? $node : new Node('paragraph', [$node]);
                Apparatus::walk(new Node('document', [$note]), static fn (Node $n): ?bool => $visit($n, false));

                return false;
            }
            if ($node->type !== 'inline_extension' || ($node->f['name'] ?? null) !== self::CITE) {
                return null;
            }
            $props = $node->f['props'] ?? null;
            $keys = is_array($props) && is_array($props['keys'] ?? null) ? $props['keys'] : null;
            $keys = $keys === null ? null : array_values(array_filter($keys, 'is_string'));
            if ($keys !== null) {
                if ($inBody) {
                    $body[] = [$node, $keys];
                } else {
                    $notes[] = [$node, $keys];
                }
            }

            return false;
        };
        Apparatus::walk($view, static fn (Node $node): ?bool => $visit($node, true));

        return [...$body, ...$notes];
    }

    /**
     * @param array{blocks: array<\MarkupCarve\Carve\Node>, author?: string, year?: string} $entry
     * @param bool $suppress
     * @param string $mode
     * @param int $number
     */
    private static function label(array $entry, int $number, string $mode, bool $suppress): string
    {
        if ($mode !== 'author-date') {
            return (string)$number;
        }
        if ($suppress) {
            return $entry['year'] ?? (string)$number;
        }
        $label = trim(($entry['author'] ?? '') . ' ' . ($entry['year'] ?? ''));

        return $label === '' ? (string)$number : $label;
    }

    /**
     * @param array<string> $order
     * @param array<string, array{blocks: array<\MarkupCarve\Carve\Node>, author?: string, year?: string}> $entries
     * @param array<string, string> $refIds
     * @param array<string, array<string>> $useIds
     * @param string $mode
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function list(array $order, array $entries, array $refIds, array $useIds, string $mode): Node
    {
        $keys = $order;
        if ($mode === 'author-date') {
            // Code point order, so every host sorts alike.
            usort($keys, static fn (string $a, string $b): int => strcmp(
                $entries[$a]['author'] ?? $a,
                $entries[$b]['author'] ?? $b,
            ));
        }
        $items = array_map(
            static fn (string $key): Node => self::item($entries[$key] ?? ['blocks' => []], $refIds[$key] ?? $key, $useIds[$key] ?? []),
            $keys,
        );
        $list = new Node('list', $items);
        $list->f['marker'] = $mode === 'author-date'
            ? ['kind' => 'bullet', 'char' => '-'] : ['kind' => 'ordered', 'style' => '1', 'delimiter' => '.'];
        $list->f['spacing'] = 'tight';
        $list->f['attrs'] = Apparatus::attrs(classes: ['references']);

        return $list;
    }

    /**
     * @param array{blocks: array<\MarkupCarve\Carve\Node>, author?: string, year?: string} $entry
     * @param string $id
     * @param array<string> $uses
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function item(array $entry, string $id, array $uses): Node
    {
        $blocks = Apparatus::coreBlocks($entry['blocks']);
        $links = [];
        foreach ($uses as $use) {
            $link = new Node('link', [Apparatus::text('↩')]);
            $link->f['href'] = '#' . $use;
            $link->f['attrs'] = Apparatus::attrs(classes: ['ref-backref']);
            array_push($links, Apparatus::text(' '), $link);
        }
        if (count($blocks) === 1 && $blocks[0]->type === 'paragraph') {
            $children = [new Node('paragraph', [...($blocks[0]->children ?? []), ...$links])];
        } elseif ($blocks === []) {
            $children = $links === [] ? [] : [new Node('paragraph', array_slice($links, 1))];
        } else {
            $children = $links === [] ? $blocks : [...$blocks, new Node('paragraph', array_slice($links, 1))];
        }
        $item = new Node('list_item', $children);
        $item->f['attrs'] = Apparatus::attrs(id: $id);

        return $item;
    }

    private static function render(Node $node, RenderHost $host, string $mode): string
    {
        $props = $node->f['props'] ?? null;
        $refs = is_array($props) ? ($props['refs'] ?? null) : null;
        if (!is_array($refs)) {
            return ($host->inlines)(PlainText::nodes($node->f['fallback'] ?? []));
        }
        $text = self::citationText($node, $refs, $mode);
        $integral = Apparatus::attribute($node, 'mode') === 'author-in-text';
        $attrs = $node->f['attrs'] ?? null;
        $own = $attrs instanceof Attrs && ($attrs->id !== null || $attrs->classes !== []
            || array_filter($attrs->pairs, static fn (array $p): bool => !in_array($p[0], self::OWN, true)) !== []);
        // As in 0.1 the citation stands bare unless it is integral or carries attributes of its own.
        if (!$integral && !$own) {
            return $text;
        }

        $drop = static fn (string $key): bool => in_array($key, self::OWN, true);

        return '<span' . Apparatus::inlineAttributes($node, $host, 'citation', $drop)
            . ($integral ? ' data-cite-mode="integral"' : '') . '>' . $text . '</span>';
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     * @param array<mixed> $refs
     * @param string $mode
     *
     * @return string
     */
    private static function citationText(Node $node, array $refs, string $mode): string
    {
        $items = [];
        foreach ($refs as $ref) {
            $ref = is_array($ref) ? $ref : [];
            $id = is_string($ref['id'] ?? null) ? ' id="' . RenderHost::escapeAttribute($ref['id']) . '"' : '';
            $items[] = '<a' . $id . ' href="' . RenderHost::escapeAttribute(PlainText::str($ref['href'] ?? '')) . '" data-cite-key="'
                . RenderHost::escapeAttribute(PlainText::str($ref['key'] ?? '')) . '">'
                . RenderHost::escapeText(PlainText::str($ref['label'] ?? '')) . '</a>';
        }
        $part = static function (string $key, string $before, string $after) use ($node): string {
            $value = Apparatus::attribute($node, $key);

            return $value === null ? '' : $before . RenderHost::escapeText($value) . $after;
        };
        $inner = $part('prefix', '', ' ') . implode($mode === 'author-date' ? '; ' : ', ', $items)
            . $part('locator', ', ', '') . $part('suffix', ' ', '');

        return $mode === 'author-date' ? '(' . $inner . ')' : '[' . $inner . ']';
    }
}
