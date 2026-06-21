<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Event\RenderEvent;
use Carve\Node\Block\Div;
use Carve\Node\Block\Paragraph;
use Carve\Node\Document;
use Carve\Node\Inline\CitationGroup;
use Carve\Node\Inline\InlineNode;
use Carve\Node\Inline\SoftBreak;
use Carve\Node\Inline\Text;
use Carve\Node\Node;
use Carve\Parser\MatcherContext;
use Carve\Parser\Utility\AttributeParser;
use Carve\Renderer\HtmlRenderer;
use Closure;

/**
 * Bracketed citations with in-document bibliography definitions.
 */
class CitationsExtension implements ExtensionInterface, ParsedDocumentExtensionInterface, BeforeRenderExtensionInterface
{
    /**
     * @var string
     */
    private const REFS_MARK = 'data-cite-refs';

    /**
     * @var string
     */
    private const KEY_PATTERN = '[A-Za-z0-9_][A-Za-z0-9_:.#$%&+?<>~\/-]*';

    /**
     * @var array<string, array{entry: list<\Carve\Node\Inline\InlineNode>, author?: string, year?: string}>
     */
    protected array $definitions = [];

    /**
     * @var array<string, int>
     */
    protected array $numbers = [];

    /**
     * @var list<string>
     */
    protected array $order = [];

    public function __construct(protected string $mode = 'numbered')
    {
    }

    public function register(CarveConverter $converter): void
    {
        $converter->getParser()->getInlineParser()->addInlineMatcher(
            fn (string $text, int $pos, MatcherContext $ctx): ?array => $this->matchCitation($text, $pos, $ctx),
            priority: 10,
            triggerChars: '[',
        );

        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $converter->on('render.citation-group', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof CitationGroup) {
                return;
            }

            $event->setHtml($this->renderCitationGroup($node, $renderer));
        });

        $converter->on('render.div', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Div || !$node->hasAttribute(self::REFS_MARK)) {
                return;
            }

            $event->setHtml($this->renderReferencesList($renderer));
        });
    }

    public function afterParse(Document $document): void
    {
        $this->definitions = [];
        $this->numbers = [];
        $this->order = [];

        $this->collectDefinitions($document);
    }

    public function beforeRender(Document $document): Document
    {
        $renderDocument = clone $document;
        $this->numbers = [];
        $this->order = [];

        $this->walkCitationGroups($renderDocument, function (CitationGroup $group): void {
            foreach ($group->getItems() as $item) {
                $key = $item['key'];
                if (!isset($this->definitions[$key]) || isset($this->numbers[$key])) {
                    continue;
                }

                $this->numbers[$key] = count($this->numbers) + 1;
                $this->order[] = $key;
            }
        });

        if ($this->order === []) {
            return $renderDocument;
        }

        $carrier = new Div();
        $carrier->setAttribute(self::REFS_MARK, '');

        $explicit = $this->findReferencesContainer($renderDocument);
        if ($explicit !== null) {
            $explicit->appendChild($carrier);
        } else {
            $renderDocument->appendChild($carrier);
        }

        return $renderDocument;
    }

    /**
     * @return array{node: \Carve\Node\Node, end: int}|null
     */
    protected function matchCitation(string $text, int $pos, MatcherContext $ctx): ?array
    {
        if (($text[$pos] ?? '') !== '[') {
            return null;
        }

        $close = $this->findClosingBracket($text, $pos);
        if ($close === null) {
            return null;
        }

        $after = $text[$close + 1] ?? '';
        if ($after === '(' || $after === '[' || $after === '{') {
            return null;
        }

        $inner = substr($text, $pos + 1, $close - $pos - 1);
        if (!str_contains($inner, '@')) {
            return null;
        }

        $items = [];
        foreach (explode(';', $inner) as $part) {
            $item = $this->parseItem($part, $ctx);
            if ($item === null) {
                return null;
            }
            $items[] = $item;
        }

        $node = new CitationGroup($items, substr($text, $pos, $close - $pos + 1));
        $end = $close + 1;
        if ($after === ':' && $this->isLineStart($text, $pos) && $this->isSimpleDefinitionItems($items)) {
            $node->setAttribute(self::REFS_MARK . '-def', '');
            $end++;
            while (($text[$end] ?? '') === ' ' || ($text[$end] ?? '') === "\t") {
                $end++;
            }
            if (($text[$end] ?? '') === '{') {
                $attrEnd = $this->findAttributeBlockEnd($text, $end);
                if ($attrEnd !== null) {
                    $attrs = AttributeParser::parse(substr($text, $end + 1, $attrEnd - $end - 1));
                    foreach (['author', 'year'] as $name) {
                        if (isset($attrs[$name])) {
                            $node->setAttribute($name, $attrs[$name]);
                        }
                    }
                    $end = $attrEnd + 1;
                    while (($text[$end] ?? '') === ' ' || ($text[$end] ?? '') === "\t") {
                        $end++;
                    }
                }
            }
        }

        return [
            'node' => $node,
            'end' => $end,
        ];
    }

    /**
     * @param list<array{key: string, suppressAuthor: bool, prefix?: list<\Carve\Node\Inline\InlineNode>, locator?: list<\Carve\Node\Inline\InlineNode>}> $items
     */
    protected function isSimpleDefinitionItems(array $items): bool
    {
        if (count($items) !== 1) {
            return false;
        }

        $item = $items[0];

        return !$item['suppressAuthor'] && !isset($item['prefix']) && !isset($item['locator']);
    }

    protected function isLineStart(string $text, int $pos): bool
    {
        return $pos === 0 || ($text[$pos - 1] ?? '') === "\n";
    }

    protected function findAttributeBlockEnd(string $text, int $open): ?int
    {
        $quote = null;
        $length = strlen($text);
        for ($i = $open + 1; $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }
            if ($char === '}') {
                return $i;
            }
            if ($char === "\n") {
                return null;
            }
        }

        return null;
    }

    protected function findClosingBracket(string $text, int $open): ?int
    {
        $depth = 0;
        $length = strlen($text);
        for ($i = $open; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '\\') {
                $i++;

                continue;
            }
            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @return array{key: string, suppressAuthor: bool, prefix?: list<\Carve\Node\Inline\InlineNode>, locator?: list<\Carve\Node\Inline\InlineNode>}|null
     */
    protected function parseItem(string $raw, MatcherContext $ctx): ?array
    {
        if (!preg_match('/^(.*?)(-?)@(' . self::KEY_PATTERN . ')(?:,\s*(.*))?$/', trim($raw), $matches)) {
            return null;
        }

        $item = [
            'key' => $matches[3],
            'suppressAuthor' => $matches[2] === '-',
        ];

        $prefix = rtrim($matches[1]);
        if ($prefix !== '') {
            $item['prefix'] = $this->onlyInlineNodes($ctx->parseInlines($prefix));
        }

        $locator = trim($matches[4] ?? '');
        if ($locator !== '') {
            $item['locator'] = $this->onlyInlineNodes($ctx->parseInlines($locator));
        }

        return $item;
    }

    /**
     * @param array<\Carve\Node\Node> $nodes
     *
     * @return list<\Carve\Node\Inline\InlineNode>
     */
    protected function onlyInlineNodes(array $nodes): array
    {
        return array_values(array_filter($nodes, static fn (Node $node): bool => $node instanceof InlineNode));
    }

    protected function collectDefinitions(Document $document): void
    {
        foreach ($document->getChildren() as $block) {
            if (!$block instanceof Paragraph) {
                continue;
            }

            $lines = $this->splitOnSoftBreaks($block->getChildren());
            $kept = [];
            foreach ($lines as $line) {
                $definition = $this->asDefinition($line);
                if ($definition !== null) {
                    $this->definitions[$definition['key']] = $definition['value'];
                } else {
                    $kept[] = $line;
                }
            }

            if (count($kept) === count($lines)) {
                continue;
            }

            if ($kept === []) {
                $document->removeChild($block);

                continue;
            }

            while ($block->removeChildAt(0) !== null) {
            }
            foreach ($this->joinWithSoftBreaks($kept) as $node) {
                $block->appendChild($node);
            }
        }
    }

    /**
     * @param array<\Carve\Node\Node> $nodes
     *
     * @return list<list<\Carve\Node\Inline\InlineNode>>
     */
    protected function splitOnSoftBreaks(array $nodes): array
    {
        $lines = [[]];
        foreach ($nodes as $node) {
            if ($node instanceof SoftBreak) {
                $lines[] = [];
            } elseif ($node instanceof InlineNode) {
                $lines[count($lines) - 1][] = $node;
            }
        }

        return array_values($lines);
    }

    /**
     * @param list<list<\Carve\Node\Inline\InlineNode>> $lines
     *
     * @return list<\Carve\Node\Inline\InlineNode>
     */
    protected function joinWithSoftBreaks(array $lines): array
    {
        $nodes = [];
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $nodes[] = new SoftBreak();
            }
            foreach ($line as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param list<\Carve\Node\Inline\InlineNode> $nodes
     *
     * @return array{key: string, value: array{entry: list<\Carve\Node\Inline\InlineNode>, author?: string, year?: string}}|null
     */
    protected function asDefinition(array $nodes): ?array
    {
        $group = $nodes[0] ?? null;
        if (!$group instanceof CitationGroup || count($group->getItems()) !== 1) {
            return null;
        }

        $item = $group->getItems()[0];
        if (isset($item['prefix']) || isset($item['locator']) || $item['suppressAuthor']) {
            return null;
        }

        $isDefinitionMarker = $group->hasAttribute(self::REFS_MARK . '-def');
        $second = $nodes[1] ?? null;
        if (!$isDefinitionMarker && (!$second instanceof Text || !str_starts_with($second->getContent(), ':'))) {
            return null;
        }

        $entry = array_slice($nodes, 1);
        if (!$isDefinitionMarker) {
            $entry[0] = new Text((string)preg_replace('/^:\s*/', '', $second->getContent()));
        }
        $value = ['entry' => $entry];
        foreach (['author', 'year'] as $name) {
            $attr = $group->getAttribute($name);
            if ($attr !== null) {
                $value[$name] = $attr;
            }
        }

        $head = $entry[0] ?? null;
        if ($head instanceof Text && preg_match('/^\{([^}]*)\}\s*/', $head->getContent(), $matches)) {
            $attrs = AttributeParser::parse($matches[1]);
            if (isset($attrs['author'])) {
                $value['author'] = $attrs['author'];
            }
            if (isset($attrs['year'])) {
                $value['year'] = $attrs['year'];
            }

            $head->setContent(substr($head->getContent(), strlen($matches[0])));
            if ($head->getContent() === '') {
                array_shift($entry);
            }
        }

        $head = $entry[0] ?? null;
        if ($head instanceof Text) {
            $head->setContent((string)preg_replace('/^\s+/', '', $head->getContent()));
        }
        $value['entry'] = $entry;

        return ['key' => $item['key'], 'value' => $value];
    }

    protected function renderCitationGroup(CitationGroup $group, HtmlRenderer $renderer): string
    {
        foreach ($group->getItems() as $item) {
            if (!isset($this->definitions[$item['key']])) {
                return $this->escapeHtml($group->getRaw());
            }
        }

        $parts = [];
        foreach ($group->getItems() as $item) {
            $prefix = isset($item['prefix']) ? $this->renderInlines($item['prefix'], $renderer) . ' ' : '';
            $locator = isset($item['locator']) ? ', ' . $this->renderInlines($item['locator'], $renderer) : '';
            $key = $item['key'];

            if ($this->mode === 'author-date') {
                $definition = $this->definitions[$key];
                $label = $item['suppressAuthor']
                    ? ($definition['year'] ?? (string)($this->numbers[$key] ?? ''))
                    : trim(($definition['author'] ?? '') . ' ' . ($definition['year'] ?? ''));
                if ($label === '') {
                    $label = (string)($this->numbers[$key] ?? '');
                }
                $parts[] = $prefix . '<a href="#ref-' . $renderer->escapeAttribute($key) . '">'
                    . $this->escapeHtml($label) . '</a>' . $locator;

                continue;
            }

            $parts[] = $prefix . '<a href="#ref-' . $renderer->escapeAttribute($key) . '">'
                . ($this->numbers[$key] ?? '') . '</a>' . $locator;
        }

        if ($this->mode === 'author-date') {
            return '(' . implode('; ', $parts) . ')';
        }

        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * @param list<\Carve\Node\Inline\InlineNode> $nodes
     * @param \Carve\Renderer\HtmlRenderer $renderer
     */
    protected function renderInlines(array $nodes, HtmlRenderer $renderer): string
    {
        $html = '';
        foreach ($nodes as $node) {
            $html .= $renderer->renderNodeFragment($node);
        }

        return $html;
    }

    protected function renderReferencesList(HtmlRenderer $renderer): string
    {
        $keys = $this->order;
        if ($this->mode === 'author-date') {
            usort($keys, function (string $a, string $b): int {
                return strcmp($this->definitions[$a]['author'] ?? $a, $this->definitions[$b]['author'] ?? $b);
            });
        }

        $tag = $this->mode === 'author-date' ? 'ul' : 'ol';
        $html = '<' . $tag . " class=\"references\">\n";
        foreach ($keys as $key) {
            $html .= '  <li id="ref-' . $renderer->escapeAttribute($key) . '">'
                . $this->renderInlines($this->definitions[$key]['entry'], $renderer) . "</li>\n";
        }
        $html .= '</' . $tag . ">\n";

        return $html;
    }

    protected function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
    }

    protected function findReferencesContainer(Document $document): ?Div
    {
        foreach ($document->getChildren() as $child) {
            if ($child instanceof Div && $child->hasClass('references')) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @param \Carve\Node\Node $node
     * @param \Closure(\Carve\Node\Inline\CitationGroup): void $callback
     */
    protected function walkCitationGroups(Node $node, Closure $callback): void
    {
        if ($node instanceof CitationGroup) {
            $callback($node);

            return;
        }

        foreach ($node->getChildren() as $child) {
            $this->walkCitationGroups($child, $callback);
        }
    }
}
