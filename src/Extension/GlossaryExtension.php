<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Event\RenderEvent;
use Carve\Node\Block\DefinitionList;
use Carve\Node\Block\DefinitionTerm;
use Carve\Node\Block\Div;
use Carve\Node\Document;
use Carve\Node\Inline\InlineExtension;
use Carve\Node\Node;
use Carve\Renderer\HeadingIdTracker;
use Carve\Renderer\HtmlRenderer;
use Closure;

/**
 * Glossary (#91, Tier-3). A `::: glossary` definition list declares terms;
 * `:term[word]` links a use to its `<dt id="gloss-{slug}">`. Reuses the
 * definition-list and `:name[…]` inline forms - no new syntax. Off by default,
 * never corpus-pinned. See docs/extensions.md §7.
 *
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new GlossaryExtension());
 * ```
 */
class GlossaryExtension implements ExtensionInterface, ParsedDocumentExtensionInterface
{
    /**
     * The inline extension role this extension claims.
     *
     * @var string
     */
    public const INLINE_TYPE = 'term';

    /**
     * The div class this extension claims.
     *
     * @var string
     */
    public const KIND = 'glossary';

    /**
     * Defined term slugs (across every `::: glossary` block), used to resolve
     * `:term[word]` links.
     *
     * @var array<string, true>
     */
    protected array $defined = [];

    protected HeadingIdTracker $slugger;

    public function __construct()
    {
        $this->slugger = new HeadingIdTracker();
        $this->slugger->setLowercase(true);
    }

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $converter->on('render.inline_extension', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof InlineExtension || $node->getExtensionType() !== self::INLINE_TYPE) {
                return;
            }

            $event->setHtml($this->renderTerm($node, $event->getChildrenHtml(), $renderer));
        });

        $converter->on('render.div', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Div || !$node->hasClass(self::KIND)) {
                return;
            }

            $event->setHtml($this->renderGlossary($node, $renderer));
        });
    }

    public function afterParse(Document $document): void
    {
        $this->defined = [];
        // Document-wide first-wins: only the first `<dt>` of a duplicated slug
        // gets the id. Assigning on the node here is idempotent across renders.
        $seen = [];
        $this->walk($document, function (Node $node) use (&$seen): void {
            if (!$node instanceof Div || !$node->hasClass(self::KIND)) {
                return;
            }
            foreach ($node->getChildren() as $child) {
                if (!$child instanceof DefinitionList) {
                    continue;
                }
                foreach ($child->getChildren() as $term) {
                    if (!$term instanceof DefinitionTerm) {
                        continue;
                    }
                    $slug = $this->slug($term);
                    $this->defined[$slug] = true;
                    if (!isset($seen[$slug])) {
                        $term->setAttribute('id', 'gloss-' . $slug);
                        $seen[$slug] = true;
                    }
                }
            }
        });
    }

    protected function renderTerm(InlineExtension $node, string $word, HtmlRenderer $renderer): string
    {
        $slug = $this->slug($node);
        if (isset($this->defined[$slug])) {
            // The structural glossary target wins; drop any author `href`
            // (case-insensitive) so the <a> never has two.
            $attrs = $this->openAttributes($node, $renderer, ['href']);

            return '<a href="#gloss-' . $renderer->escapeAttribute($slug) . '"' . $attrs . '>' . $word . '</a>';
        }

        // Resolved, but no matching entry: degrade to a plain span, nothing dropped.
        return '<span' . $this->openAttributes($node, $renderer) . '>' . $word . '</span>';
    }

    protected function renderGlossary(Div $div, HtmlRenderer $renderer): string
    {
        $parts = [];
        $firstDl = true;
        foreach ($div->getChildren() as $child) {
            if ($child instanceof DefinitionList) {
                $this->applyListAttributes($child, $firstDl ? $div : null);
                $firstDl = false;
            }
            $parts[] = rtrim($renderer->renderNodeFragment($child), "\n");
        }

        return implode("\n", $parts) . "\n";
    }

    /**
     * Set the rendered `<dl>`'s attributes: the `glossary` class leads. The
     * first list of the block also carries the block's authored `{#id .class}`
     * (id first, then class, then other key-values), so a preceding attribute
     * line rides through; later lists are bare `class="glossary"`.
     */
    protected function applyListAttributes(DefinitionList $list, ?Div $source): void
    {
        if ($source === null) {
            $list->setAttributes(['class' => self::KIND]);

            return;
        }

        $classes = [self::KIND];
        foreach ($source->getClassList() as $class) {
            if (!in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        $attrs = [];
        $id = $source->getAttribute('id');
        if ($id !== null) {
            $attrs['id'] = $id;
        }
        $attrs['class'] = implode(' ', $classes);
        foreach ($source->getAttributes() as $name => $value) {
            if ($name !== 'id' && $name !== 'class' && $name !== 'title') {
                $attrs[$name] = $value;
            }
        }
        $list->setAttributes($attrs);
    }

    /**
     * Build the output element's attribute string: the `term` base class ahead
     * of any author classes, then id / key-values, with the always-on
     * hardening plus safe-mode filtering and value escaping applied.
     *
     * @param \Carve\Node\Node $node
     * @param \Carve\Renderer\HtmlRenderer $renderer
     * @param array<string> $exclude Attribute names to drop (e.g. `href`).
     */
    protected function openAttributes(Node $node, HtmlRenderer $renderer, array $exclude = []): string
    {
        $classes = [self::INLINE_TYPE];
        foreach ($node->getClassList() as $class) {
            if (!in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        $attrs = $node->getAttributes();
        unset($attrs['class']);
        $drop = array_flip(array_map('strtolower', $exclude));
        foreach (array_keys($attrs) as $name) {
            if (isset($drop[strtolower((string)$name)])) {
                unset($attrs[$name]);
            }
        }

        $attrs = $renderer->sanitizeAttributes($attrs);
        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $attrs = $safeMode->filterAttributes($attrs);
        }

        return ' class="' . $renderer->escapeAttribute(implode(' ', $classes)) . '"'
            . $renderer->renderAttributeArray($attrs);
    }

    protected function slug(Node $node): string
    {
        return $this->slugger->normalizeId($this->slugger->getPlainText($node));
    }

    /**
     * @param \Carve\Node\Node $node
     * @param \Closure(\Carve\Node\Node): void $callback
     */
    protected function walk(Node $node, Closure $callback): void
    {
        $callback($node);
        foreach ($node->getChildren() as $child) {
            $this->walk($child, $callback);
        }
    }
}
