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
     * @var array<string, array{entry: list<\Carve\Node\Inline\InlineNode>, author?: string, year?: string, cslText?: string}>
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

    /**
     * Per-key, document-wide use-site count, populated when a bibliography pool
     * is active; drives the references-list back-links (#199).
     *
     * @var array<string, int>
     */
    protected array $uses = [];

    /**
     * Whether a CSL-JSON bibliography pool was supplied (Tier-3, #199). When
     * true, in-text citations and the references list gain back-links.
     */
    protected bool $hasBibliography = false;

    /**
     * External CSL-JSON entries (each normally an associative array with a
     * string `id`; non-array members are tolerated and skipped).
     *
     * @var array<int, mixed>
     */
    protected array $pool = [];

    /**
     * Per-text cache of balanced `[`->`]` bracket pairs (open offset => close
     * offset), precomputed in one pass so each matchCitation() call is O(1)
     * instead of re-scanning to EOF (which is O(n^2) on inputs like `[[[[`).
     *
     * @var array<string, array<int, int>>
     */
    protected array $bracketPairs = [];

    /**
     * @param string $mode `numbered` (default) or `author-date`.
     * @param array<int, mixed>|null $bibliography Tier-3 CSL-JSON pool (#199);
     *   null disables external resolution and back-links. The host resolves the
     *   front-matter `bibliography:` path and passes the parsed array here; the
     *   extension itself performs no file I/O.
     */
    public function __construct(protected string $mode = 'numbered', ?array $bibliography = null)
    {
        $this->hasBibliography = $bibliography !== null;
        $this->pool = $bibliography ?? [];
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
        $this->uses = [];
        $this->bracketPairs = [];

        $this->collectDefinitions($document);

        // Seed the CSL-JSON pool: in-document defs win on collision (§6.2).
        foreach ($this->pool as $entry) {
            if (!is_array($entry) || !isset($entry['id']) || !is_string($entry['id'])) {
                continue;
            }
            if (!isset($this->definitions[$entry['id']])) {
                $this->definitions[$entry['id']] = $this->cslToDefinition($entry);
            }
        }
    }

    public function beforeRender(Document $document): Document
    {
        $renderDocument = clone $document;
        $this->numbers = [];
        $this->order = [];
        $this->uses = [];

        $this->walkCitationGroups($renderDocument, function (CitationGroup $group): void {
            $items = $group->getItems();
            // A group with any unresolved key renders verbatim (§6.4): its keys
            // are not "cited", so they enter neither the numbering/references
            // list (§6.2) nor the back-link use sites. Skip the whole group.
            foreach ($items as $item) {
                if (!isset($this->definitions[$item['key']])) {
                    return;
                }
            }

            foreach ($items as $index => $item) {
                $key = $item['key'];
                if (!isset($this->numbers[$key])) {
                    $this->numbers[$key] = count($this->numbers) + 1;
                    $this->order[] = $key;
                }
                if ($this->hasBibliography) {
                    $useIndex = ($this->uses[$key] ?? 0) + 1;
                    $this->uses[$key] = $useIndex;
                    // Stash the per-item use index on the (cloned) group so the
                    // renderer can emit `id="cite-{key}-{n}"`.
                    $group->setAttribute('cite-use-' . $index, (string)$useIndex);
                }
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
        return $this->bracketPairs($text)[$open] ?? null;
    }

    /**
     * Precompute balanced `[`->`]` pairs for the whole text in a single pass
     * (stack of open offsets), so matchCitation() resolves each opener in O(1).
     * Mirrors the previous per-opener depth scan: each `]` matches the nearest
     * still-open `[`; `\` escapes the next character; unmatched `[` get no
     * entry (findClosingBracket returns null for them, as before).
     *
     * @return array<int, int>
     */
    protected function bracketPairs(string $text): array
    {
        if (isset($this->bracketPairs[$text])) {
            return $this->bracketPairs[$text];
        }

        $pairs = [];
        $stack = [];
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '\\') {
                $i++;

                continue;
            }
            if ($char === '[') {
                $stack[] = $i;
            } elseif ($char === ']' && $stack !== []) {
                $pairs[array_pop($stack)] = $i;
            }
        }

        return $this->bracketPairs[$text] = $pairs;
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
        foreach ($group->getItems() as $index => $item) {
            $prefix = isset($item['prefix']) ? $this->renderInlines($item['prefix'], $renderer) . ' ' : '';
            $locator = isset($item['locator']) ? ', ' . $this->renderInlines($item['locator'], $renderer) : '';
            $key = $item['key'];

            // Back-link anchor on the per-key item (only with a bibliography pool, §6.3).
            $useIndex = $this->hasBibliography ? $group->getAttribute('cite-use-' . $index) : null;
            $idAttr = ($useIndex !== null && $useIndex !== '')
                ? 'id="cite-' . $renderer->escapeAttribute($key) . '-' . $useIndex . '" '
                : '';

            if ($this->mode === 'author-date') {
                $definition = $this->definitions[$key];
                $label = $item['suppressAuthor']
                    ? ($definition['year'] ?? (string)($this->numbers[$key] ?? ''))
                    : trim(($definition['author'] ?? '') . ' ' . ($definition['year'] ?? ''));
                if ($label === '') {
                    $label = (string)($this->numbers[$key] ?? '');
                }
                $parts[] = $prefix . '<a ' . $idAttr . 'href="#ref-' . $renderer->escapeAttribute($key) . '">'
                    . $this->escapeHtml($label) . '</a>' . $locator;

                continue;
            }

            $parts[] = $prefix . '<a ' . $idAttr . 'href="#ref-' . $renderer->escapeAttribute($key) . '">'
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
            $definition = $this->definitions[$key];
            // A CSL-sourced entry is plain text (escaped); an in-doc def is inline AST.
            $body = isset($definition['cslText'])
                ? $this->escapeHtml($definition['cslText'])
                : $this->renderInlines($definition['entry'], $renderer);

            $backlinks = '';
            if ($this->hasBibliography) {
                $count = $this->uses[$key] ?? 0;
                $links = [];
                for ($m = 1; $m <= $count; $m++) {
                    $links[] = '<a href="#cite-' . $renderer->escapeAttribute($key) . '-' . $m
                        . '" class="ref-backref">↩</a>';
                }
                if ($links !== []) {
                    $backlinks = ($body !== '' ? ' ' : '') . implode(' ', $links);
                }
            }

            $html .= '  <li id="ref-' . $renderer->escapeAttribute($key) . '">'
                . $body . $backlinks . "</li>\n";
        }
        $html .= '</' . $tag . ">\n";

        return $html;
    }

    /**
     * Build a definition from a CSL-JSON entry using the minimal fixed template
     * (§6.3): `Family, Given (Year). Title.`, missing fields + separators
     * omitted, trailing period when non-empty. The text is plain (HTML-escaped
     * at render); CSL-JSON is external data and is never re-parsed as Carve.
     *
     * @param array<array-key, mixed> $entry
     *
     * @return array{entry: list<\Carve\Node\Inline\InlineNode>, author?: string, year?: string, cslText: string}
     */
    protected function cslToDefinition(array $entry): array
    {
        $names = [];
        $authors = is_array($entry['author'] ?? null) ? $entry['author'] : [];
        foreach ($authors as $name) {
            if (!is_array($name)) {
                continue;
            }
            $formatted = $this->formatCslName($name);
            if ($formatted !== '') {
                $names[] = $formatted;
            }
        }
        $authorsText = implode('; ', $names);
        $year = $this->cslYear($entry['issued'] ?? null);

        $head = $authorsText;
        if ($year !== '') {
            $head = $head !== '' ? $head . ' (' . $year . ')' : '(' . $year . ')';
        }

        $segments = [];
        if ($head !== '') {
            $segments[] = $head;
        }
        $title = $entry['title'] ?? null;
        if (is_string($title) && $title !== '') {
            $segments[] = $title;
        }
        $cslText = implode('. ', $segments);
        if ($cslText !== '') {
            $cslText .= '.';
        }

        $value = ['entry' => [], 'cslText' => $cslText];
        // author/year also feed author-date mode; use the first author's family.
        $first = $authors[0] ?? null;
        if (is_array($first)) {
            $author = $first['literal'] ?? ($first['family'] ?? null);
            if (is_string($author)) {
                $value['author'] = $author;
            }
        }
        if ($year !== '') {
            $value['year'] = $year;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $name
     */
    protected function formatCslName(array $name): string
    {
        if (isset($name['literal']) && is_string($name['literal'])) {
            return $name['literal'];
        }
        $family = is_string($name['family'] ?? null) ? $name['family'] : '';
        $given = is_string($name['given'] ?? null) ? $name['given'] : '';
        if ($family !== '' && $given !== '') {
            return $family . ', ' . $given;
        }

        return $family;
    }

    protected function cslYear(mixed $issued): string
    {
        if (!is_array($issued)) {
            return '';
        }
        $dateParts = $issued['date-parts'] ?? null;
        $firstPart = is_array($dateParts) ? ($dateParts[0] ?? null) : null;
        $year = is_array($firstPart) ? ($firstPart[0] ?? null) : null;
        if (is_int($year)) {
            return (string)$year;
        }

        $literal = $issued['literal'] ?? null;

        return is_string($literal) ? $literal : '';
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
