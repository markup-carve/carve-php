<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Resolve\Slug;
use Normalizer;

/**
 * Provisional. `:term[word]` (`carve.term`) links a use to its entry in a `::: glossary` description list
 * (`carve.glossary`).
 */
final class Glossary
{
    /**
     * @var string
     */
    public const TERM = 'carve.term';

    /**
     * @var string
     */
    public const GLOSSARY = 'carve.glossary';

    public static function bundle(): Bundle
    {
        return new Bundle(
            [self::termDefinition(), Apparatus::directiveDefinition(self::GLOSSARY, Apparatus::body(...))],
            [new DocumentTransform('glossary', self::transform(...))],
        );
    }

    public static function termDefinition(): ExtensionDefinition
    {
        return new ExtensionDefinition(
            self::TERM,
            '1',
            ['text'],
            matcher: ['form' => 'inline', 'name' => 'term'],
            transform: static function (Claim $claim): ?Node {
                if ($claim->form !== 'inline') {
                    return null;
                }
                $children = $claim->node->children ?? [];
                $node = new Node('inline_extension', []);
                $node->f['name'] = self::TERM;
                $node->f['version'] = '1';
                if ($children !== []) {
                    $node->f['slots'] = ['text' => $children];
                }
                $node->f['fallback'] = Apparatus::coreInlines($children);

                return $node;
            },
            renderer: self::renderTerm(...),
            buildTimeRenderer: self::renderTerm(...),
        );
    }

    /**
     * A use reaches an entry by its exact text after whitespace collapse and NFC, as in 0.1.
     */
    private static function key(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        return trim((string)preg_replace('/\s+/u', ' ', is_string($normalized) ? $normalized : $text));
    }

    private static function isGlossary(Node $node): bool
    {
        return $node->type === 'directive' && ($node->f['kind'] ?? null) === 'glossary';
    }

    public static function transform(Node $view): Node
    {
        [$targets, $ids] = self::entries($view);
        Apparatus::walk($view, static function (Node $node) use ($targets): null {
            if ($node->type === 'inline_extension' && ($node->f['name'] ?? null) === self::TERM) {
                $slots = is_array($node->f['slots'] ?? null) ? $node->f['slots'] : [];
                $target = $targets[self::key(PlainText::of(PlainText::nodes($slots['text'] ?? [])))] ?? null;
                if ($target !== null) {
                    $node->f['props'] = ['href' => '#' . $target];
                }
            }

            return null;
        });
        $claimed = false;
        Apparatus::walk($view, static function (Node $node) use ($ids, &$claimed): ?Node {
            if (!self::isGlossary($node)) {
                return null;
            }
            $claimed = true;

            return Apparatus::claimDirective(self::GLOSSARY, $node, [self::body($node, $ids)]);
        });
        if ($claimed) {
            Apparatus::declare($view, self::GLOSSARY);
        }

        return $view;
    }

    /**
     * The entries of every glossary in document order: the target id by key, and the generated id by term.
     *
     * @return array{0: array<string, string>, 1: array<int, string>}
     */
    private static function entries(Node $view): array
    {
        $taken = Apparatus::viewIds($view);
        $targets = [];
        $ids = [];
        $slugs = [];
        Apparatus::walk($view, static function (Node $node) use (&$taken, &$targets, &$ids, &$slugs): null {
            if (!self::isGlossary($node)) {
                return null;
            }
            foreach ($node->children ?? [] as $list) {
                foreach ($list->type === 'definition_list' ? $list->children ?? [] : [] as $term) {
                    if ($term->type === 'definition_term') {
                        self::addTerm($term, $taken, $targets, $ids, $slugs);
                    }
                }
            }

            return null;
        });

        return [$targets, $ids];
    }

    /**
     * One term: the first term of a slug takes the id, and the first of a key is the target.
     *
     * @param \MarkupCarve\Carve\Node $term
     * @param array<string, true> $taken
     * @param array<string, string> $targets
     * @param array<int, string> $ids
     * @param array<string, true> $slugs
     *
     * @return void
     */
    private static function addTerm(Node $term, array &$taken, array &$targets, array &$ids, array &$slugs): void
    {
        $text = PlainText::of($term->children ?? []);
        $slug = Slug::of($text, 'keep-case');
        $attrs = $term->f['attrs'] ?? null;
        $authored = $attrs instanceof Attrs ? $attrs->id : null;
        if (!isset($slugs[$slug])) {
            $slugs[$slug] = true;
            if ($authored === null) {
                $ids[spl_object_id($term)] = Apparatus::freshId('gloss-' . $slug, $taken);
            }
        }
        $id = $authored ?? $ids[spl_object_id($term)] ?? null;
        $key = self::key($text);
        if ($id !== null && !isset($targets[$key])) {
            $targets[$key] = $id;
        }
    }

    /**
     * A copy of the directive whose lists carry the class `glossary` and whose terms carry their generated ids.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param array<int, string> $ids
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function body(Node $node, array $ids): Node
    {
        $body = Extensions::copy($node);
        foreach ($node->children ?? [] as $at => $list) {
            $copy = $body->children[$at] ?? null;
            if ($list->type !== 'definition_list' || $copy === null || $copy->type !== 'definition_list') {
                continue;
            }
            $copy->f['attrs'] = self::withFirstClass($copy, 'glossary');
            foreach ($list->children ?? [] as $index => $term) {
                $id = $ids[spl_object_id($term)] ?? null;
                $target = $copy->children[$index] ?? null;
                if ($id !== null && $target !== null) {
                    $attrs = $target->f['attrs'] ?? null;
                    $attrs = $attrs instanceof Attrs ? clone $attrs : new Attrs();
                    $attrs->id = $id;
                    $target->f['attrs'] = $attrs;
                }
            }
        }

        return $body;
    }

    private static function withFirstClass(Node $node, string $class): Attrs
    {
        $attrs = $node->f['attrs'] ?? null;

        return AuthorAttrs::withClass($attrs instanceof Attrs ? $attrs : null, $class);
    }

    public static function renderTerm(Node $node, RenderHost $host): string
    {
        $slots = is_array($node->f['slots'] ?? null) ? $node->f['slots'] : [];
        $text = ($host->inlines)(PlainText::nodes($slots['text'] ?? []));
        $props = $node->f['props'] ?? null;
        $href = is_array($props) ? ($props['href'] ?? null) : null;
        // The glossary target wins over an authored `href`, so the link has one.
        if (is_string($href)) {
            $attrs = Apparatus::inlineAttributes($node, $host, 'term', static fn (string $key): bool => strtolower($key) === 'href');

            return '<a href="' . RenderHost::escapeAttribute($href) . '"' . $attrs . '>' . $text . '</a>';
        }

        return '<span' . Apparatus::inlineAttributes($node, $host, 'term', static fn (): bool => false) . '>' . $text . '</span>';
    }
}
