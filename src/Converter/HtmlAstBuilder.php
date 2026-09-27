<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use SplObjectStorage;
use Throwable;

/**
 * Builds the public Carve AST directly from an HTML DOM.
 *
 * This class deliberately knows nothing about Carve source syntax. Source is
 * produced after this pass by CarveRenderer, so escaping and delimiter choices
 * stay in the canonical writer.
 *
 * @internal
 *
 * @phpstan-type Attrs array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}
 * @phpstan-type TableCellNode array{type: 'table_cell', header: bool, children: list<array<string, mixed>>, span?: 'rowspan'|'colspan', align?: string, valign?: string, attrs?: array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}}
 * @phpstan-type TableRowNode array{type: 'table_row', cells: list<array{type: 'table_cell', header: bool, children: list<array<string, mixed>>, span?: 'rowspan'|'colspan', align?: string, valign?: string, attrs?: array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}}>, attrs?: array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}}
 */
final class HtmlAstBuilder
{
    private ?DOMDocument $builtDocument = null;

    /**
     * @var \SplObjectStorage<\DOMElement, null>
     */
    private SplObjectStorage $droppedEmptyElements;

    /**
     * Elements whose URL-list attribute the tree carries.
     *
     * @var \SplObjectStorage<\DOMElement, null>
     */
    private SplObjectStorage $urlListCarriers;

    /**
     * @return \SplObjectStorage<\DOMElement, null>
     */
    public function urlListCarriers(): SplObjectStorage
    {
        return $this->urlListCarriers;
    }

    /**
     * @return \SplObjectStorage<\DOMElement, null>
     */
    public function droppedEmptyElements(): SplObjectStorage
    {
        return $this->droppedEmptyElements;
    }

    /**
     * @var array<string, list<string>>
     */
    private array $retainedTableAttributes = [];

    /**
     * @var array<string, true>
     */
    private array $retainedTablePartitions = [];

    /**
     * @var \SplObjectStorage<\DOMElement, \DOMElement|null>
     */
    private SplObjectStorage $codeLanguageWrappers;

    /**
     * @var array<string, list<string>>
     */
    private array $displacedFigureAttributes = [];

    /**
     * @return array<string, list<string>>
     */
    public function displacedFigureAttributes(): array
    {
        return $this->displacedFigureAttributes;
    }

    /**
     * @return array<string, list<string>>
     */
    public function retainedTableAttributes(): array
    {
        return $this->retainedTableAttributes;
    }

    /**
     * @return array<string, true>
     */
    public function retainedTablePartitions(): array
    {
        return $this->retainedTablePartitions;
    }

    private static function importPath(DOMElement $node): string
    {
        $parts = [];
        for ($current = $node; $current instanceof DOMElement && !in_array(strtolower($current->tagName), ['carve-import-root', 'html', 'body'], true); $current = $current->parentNode) {
            $index = 1;
            for ($sibling = $current->previousSibling; $sibling !== null; $sibling = $sibling->previousSibling) {
                $index++;
            }
            array_unshift($parts, strtolower($current->tagName) . '[' . $index . ']');
        }

        return '/' . implode('/', $parts);
    }

    /**
     * @var \SplObjectStorage<\DOMElement, null>
     */
    private SplObjectStorage $keptRawElements;

    /**
     * @var \SplObjectStorage<\DOMElement, null>
     */
    private SplObjectStorage $droppedBlankTableRows;

    /**
     * `<dl>` elements merged into the definition list before them.
     *
     * @var \SplObjectStorage<\DOMElement, null>
     */
    private SplObjectStorage $mergedDefinitionLists;

    private ?bool $tableCellAllowsEmptyCode = null;

    /**
     * @var array<string, true>
     */
    private array $footnoteTargets = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $footnoteDefinitions = [];

    /**
     * @var array<string, string>
     */
    private array $referenceDefinitions = [];

    /**
     * The bracket text an ordered task item's checkbox is written as, keyed by
     * the `<input>` element's object id.
     *
     * @var array<int, string>
     */
    private array $orderedTaskBrackets = [];

    /**
     * @var array<string, string>
     */
    private array $abbreviationDefinitions = [];

    private bool $inFootnoteDefinition = false;

    /**
     * @var list<string>
     */
    private array $inlineTypeStack = [];

    private int $quoteDepth = 0;

    private bool $inCaption = false;

    private bool $inInlineProjection = false;

    private bool $preserveInlineWhitespace = false;

    /**
     * @template T
     *
     * @param iterable<T> $values
     * @param callable(T): bool $predicate
     */
    private static function every(iterable $values, callable $predicate): bool
    {
        foreach ($values as $value) {
            if (!$predicate($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @template T
     *
     * @param iterable<T> $values
     * @param callable(T): bool $predicate
     */
    private static function some(iterable $values, callable $predicate): bool
    {
        foreach ($values as $value) {
            if ($predicate($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $name
     */
    private function addHint(array &$node, string $name): void
    {
        $this->setPrivateAttribute($node, $name, '1');
    }

    /**
     * @param array<string, mixed> $node
     * @param string $value
     * @param string $name
     */
    private function setPrivateAttribute(array &$node, string $name, string $value): void
    {
        if (!$this->sourceSafe) {
            return;
        }
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $keyValues = is_array($attrs['keyValues'] ?? null) ? $attrs['keyValues'] : [];
        $order = is_array($attrs['order'] ?? null) ? $attrs['order'] : [];
        $keyValues[$name] = $value;
        $order[] = $name;
        $attrs['keyValues'] = $keyValues;
        $attrs['order'] = $order;
        $node['attrs'] = $attrs;
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function nodeList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $nodes = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $node = [];
            foreach ($item as $key => $entry) {
                if (is_string($key)) {
                    $node[$key] = $entry;
                }
            }
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * @return Attrs
     */
    private static function attrsValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $attrs = [];
        if (is_string($value['id'] ?? null)) {
            $attrs['id'] = $value['id'];
        }
        $classes = is_array($value['classes'] ?? null)
            ? array_values(array_filter($value['classes'], 'is_string'))
            : [];
        if ($classes !== []) {
            $attrs['classes'] = $classes;
        }
        $keyValues = is_array($value['keyValues'] ?? null)
            ? array_filter($value['keyValues'], 'is_string')
            : [];
        if ($keyValues !== []) {
            $attrs['keyValues'] = $keyValues;
        }
        $order = is_array($value['order'] ?? null)
            ? array_values(array_filter($value['order'], 'is_string'))
            : [];
        if ($order !== []) {
            $attrs['order'] = $order;
        }

        return $attrs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectedFootnotes(): array
    {
        return $this->footnoteDefinitions;
    }

    /**
     * @return array<string, string>
     */
    private function collectedReferences(): array
    {
        return $this->referenceDefinitions;
    }

    /**
     * @param bool $listTableForBlockCells
     * @param string $importMode
     * @param bool $trustedRoundTrip
     * @param bool $sourceSafe
     * @param array<string, string> $alignmentClasses
     * @param array<string, string> $labels
     */
    public function __construct(
        private readonly bool $listTableForBlockCells = false,
        private readonly string $importMode = 'safe',
        private readonly bool $trustedRoundTrip = false,
        private readonly bool $sourceSafe = true,
        private readonly array $alignmentClasses = [],
        private readonly array $labels = [],
    ) {
        $this->displacedFigureAttributes = [];
        $this->droppedEmptyElements = new SplObjectStorage();
        $this->urlListCarriers = new SplObjectStorage();
        $this->codeLanguageWrappers = new SplObjectStorage();
        $this->keptRawElements = new SplObjectStorage();
        $this->droppedBlankTableRows = new SplObjectStorage();
        $this->mergedDefinitionLists = new SplObjectStorage();
    }

    public function builtDocument(): ?DOMDocument
    {
        return $this->builtDocument;
    }

    /**
     * @return \SplObjectStorage<\DOMElement, null>
     */
    public function keptRawElements(): SplObjectStorage
    {
        return $this->keptRawElements;
    }

    /**
     * @return \SplObjectStorage<\DOMElement, null>
     */
    public function droppedBlankTableRows(): SplObjectStorage
    {
        return $this->droppedBlankTableRows;
    }

    /**
     * @return \SplObjectStorage<\DOMElement, null>
     */
    public function mergedDefinitionLists(): SplObjectStorage
    {
        return $this->mergedDefinitionLists;
    }

    private function keepRaw(DOMElement $node): void
    {
        $this->keptRawElements[$node] = null;
    }

    /**
     * @var string
     */
    private const SLUG_KEY = "\0heading-slug";

    /**
     * @var string
     */
    private const SECTION_ID_KEY = "\0section-id";

    /**
     * @var int
     */
    private const SECTION_ID_PASSES = 32;

    /**
     * @var array<string, true>
     */
    private const BLOCK_TAGS = [
        'address' => true,
        'article' => true,
        'aside' => true,
        'blockquote' => true,
        'details' => true,
        'div' => true,
        'dl' => true,
        'fieldset' => true,
        'figure' => true,
        'footer' => true,
        'form' => true,
        'header' => true,
        'hgroup' => true,
        'hr' => true,
        'main' => true,
        'nav' => true,
        'ol' => true,
        'p' => true,
        'pre' => true,
        'section' => true,
        'table' => true,
        'ul' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
    ];

    /**
     * Elements with a spelling of their own outside {@see BLOCK_TAGS}. Every
     * other element is unwrapped to its children, so {@see holdsBlock()} looks
     * through it.
     *
     * @var array<string, true>
     */
    private const SPELLED_NON_BLOCK_TAGS = [
        'a' => true,
        'abbr' => true,
        'b' => true,
        'br' => true,
        'caption' => true,
        'cite' => true,
        'code' => true,
        'dd' => true,
        'del' => true,
        'dfn' => true,
        'dt' => true,
        'em' => true,
        'figcaption' => true,
        'i' => true,
        'img' => true,
        'input' => true,
        'ins' => true,
        'kbd' => true,
        'li' => true,
        'mark' => true,
        'math' => true,
        'noscript' => true,
        'q' => true,
        'ruby' => true,
        's' => true,
        'samp' => true,
        'script' => true,
        'span' => true,
        'strike' => true,
        'strong' => true,
        'style' => true,
        'sub' => true,
        'summary' => true,
        'sup' => true,
        'tbody' => true,
        'td' => true,
        'template' => true,
        'tfoot' => true,
        'th' => true,
        'thead' => true,
        'time' => true,
        'tr' => true,
        'u' => true,
        'var' => true,
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(string $html, ?int $sourceByteLength = null): array
    {
        $this->displacedFigureAttributes = [];
        $this->droppedEmptyElements = new SplObjectStorage();
        $this->urlListCarriers = new SplObjectStorage();
        $this->codeLanguageWrappers = new SplObjectStorage();
        $this->keptRawElements = new SplObjectStorage();
        $this->droppedBlankTableRows = new SplObjectStorage();
        $this->mergedDefinitionLists = new SplObjectStorage();
        $this->footnoteTargets = [];
        $this->footnoteDefinitions = [];
        $this->referenceDefinitions = [];
        $this->orderedTaskBrackets = [];
        $this->abbreviationDefinitions = [];
        $this->inFootnoteDefinition = false;
        $this->inlineTypeStack = [];
        $this->quoteDepth = 0;
        $this->inCaption = false;
        $this->preserveInlineWhitespace = false;
        $document = HtmlDomLoader::load('<carve-import-root>' . $html . '</carve-import-root>');
        $this->builtDocument = $document;
        $this->retainedTableAttributes = [];
        $this->retainedTablePartitions = [];

        $root = $document->getElementsByTagName('carve-import-root')->item(0);
        if (!$root instanceof DOMElement) {
            return ['type' => 'document', 'srcByteLength' => $sourceByteLength ?? strlen($html), 'children' => []];
        }

        foreach ($root->getElementsByTagName('a') as $anchor) {
            if (strtolower($anchor->getAttribute('role')) !== 'doc-noteref') {
                continue;
            }
            $href = $anchor->getAttribute('href');
            if (str_starts_with($href, '#') && strlen($href) > 1) {
                $this->footnoteTargets[substr($href, 1)] = true;
            }
        }
        foreach ($root->getElementsByTagName('template') as $template) {
            if (!$template->hasAttribute('data-djot-abbreviations')) {
                continue;
            }
            if (preg_match_all('/^\*\[([^\]\n]+)\]: +(.+)$/m', $template->textContent, $matches, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($matches as $match) {
                $this->abbreviationDefinitions[$match[1]] ??= $match[2];
            }
        }
        $children = $this->blocks($this->children($root));
        foreach (array_reverse($this->abbreviationDefinitions, true) as $abbr => $expansion) {
            $definition = [
                'type' => 'abbreviation_def',
                'abbr' => $abbr,
                'expansion' => $expansion,
            ];
            $this->addHint($definition, "\0carve-compact-definition");
            array_unshift($children, $definition);
        }
        foreach ($this->collectedFootnotes() as $definition) {
            $children[] = $definition;
        }
        foreach ($this->collectedReferences() as $label => $href) {
            $children[] = [
                'type' => 'link_reference_definition',
                'label' => $label,
                'href' => $href,
            ];
        }

        $tree = [
            'type' => 'document',
            'srcByteLength' => $sourceByteLength ?? strlen($html),
            'children' => $children,
        ];
        $this->resolveSectionIds($tree);
        if ($this->sourceSafe) {
            $this->markLiteralSymbolText($tree);
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function markLiteralSymbolText(array &$node): void
    {
        if (
            ($node['type'] ?? null) === 'text'
            && preg_match('/(?<![\w]):[a-zA-Z0-9+-][\w+-]*(?::|\[)/', self::stringValue($node['value'] ?? null)) === 1
        ) {
            $this->addHint($node, "\0carve-literal-symbol");
        }
        if (
            ($node['type'] ?? null) === 'text'
            && preg_match('/\[[^\]\n]+\]\([^\)\n]*["\'][^\)\n]*\)/', self::stringValue($node['value'] ?? null)) === 1
        ) {
            $this->addHint($node, "\0carve-literal-inline-opener");
        }
        $type = $node['type'] ?? null;
        if (in_array($type, ['span', 'link'], true)) {
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $first = array_key_first($children);
            if (
                $first !== null
                && is_array($children[$first])
                && ($children[$first]['type'] ?? null) === 'text'
                && is_string($children[$first]['value'] ?? null)
                && preg_match('/^\^./s', $children[$first]['value']) === 1
            ) {
                $this->addHint($children[$first], "\0carve-literal-caret");
                $node['children'] = $children;
            }
        }
        if ($type === 'table_cell' && !isset($node['attrs'])) {
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            foreach ($children as &$child) {
                if (is_array($child) && ($child['type'] ?? null) === 'text' && ($child['value'] ?? null) === '^') {
                    $this->addHint($child, "\0carve-literal-caret");
                }
            }
            unset($child);
            $node['children'] = $children;
        }
        foreach ($node as &$value) {
            if (!is_array($value)) {
                continue;
            }
            if (array_is_list($value)) {
                $children = self::nodeList($value);
                foreach ($children as &$child) {
                    $this->markLiteralSymbolText($child);
                }
                unset($child);
                $value = $children;
            } elseif (isset($value['type'])) {
                $nested = self::nodeList([$value]);
                if (isset($nested[0])) {
                    $this->markLiteralSymbolText($nested[0]);
                    $value = $nested[0];
                }
            }
        }
        unset($value);
    }

    /**
     * @param list<\DOMNode> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(array $nodes): array
    {
        $blocks = [];
        $pending = [];
        $flush = function () use (&$blocks, &$pending): void {
            $children = $this->hoistedRun($pending);
            $this->normalizeInlineBoundaries($children);
            $children = $this->trimBlockEdges($children);
            $pending = [];
            if ($children !== []) {
                if (count($children) === 1 && ($children[0]['type'] ?? null) === 'image') {
                    $blocks[] = $children[0];
                } else {
                    $blocks[] = ['type' => 'paragraph', 'children' => $children];
                }
            }
        };

        foreach ($nodes as $index => $node) {
            if ($node instanceof DOMComment && self::commentBreaksACellRow($node, $this->listTableForBlockCells)) {
                continue;
            }
            if ($node instanceof DOMComment) {
                $inlineRun = self::some(
                    $pending,
                    static fn (array $part): bool => ($part['type'] ?? null) !== 'text'
                        || trim(self::stringValue($part['value'] ?? null)) !== '',
                );
                if (!$inlineRun) {
                    for ($next = $index + 1, $count = count($nodes); $next < $count; ++$next) {
                        $sibling = $nodes[$next];
                        if ($sibling instanceof DOMText && trim($sibling->textContent) === '') {
                            continue;
                        }
                        // An element that imports to nothing is no neighbor (carve-rs#2029).
                        if (
                            $sibling instanceof DOMElement
                            && !$this->isBlock($sibling)
                            && (trim($sibling->textContent) === '' || in_array(strtolower($sibling->tagName), ['script', 'style', 'template', 'noscript'], true))
                            && $this->inline($sibling) === []
                        ) {
                            continue;
                        }
                        $inlineRun = !$this->isBlock($sibling) && !$sibling instanceof DOMComment;

                        break;
                    }
                }
                if ($inlineRun) {
                    foreach ($this->inline($node) as $inline) {
                        $pending[] = $inline;
                    }

                    continue;
                }
                $flush();
                $blocks[] = [
                    'type' => 'comment',
                    'content' => $node->textContent,
                    'block' => true,
                ];

                continue;
            }
            if ($this->isBlock($node)) {
                $flush();
                $produced = $this->block($node);
                $last = array_key_last($blocks);
                // Carve source has no boundary between two definition lists, so
                // an attribute-less one following another joins it (carve#2369).
                if (
                    $last !== null
                    && $node instanceof DOMElement
                    && ($blocks[$last]['type'] ?? null) === 'definition_list'
                    && ($produced[0]['type'] ?? null) === 'definition_list'
                    && self::attrsValue($produced[0]['attrs'] ?? null) === []
                ) {
                    $next = array_shift($produced);
                    $blocks[$last]['items'] = array_merge(self::nodeList($blocks[$last]['items'] ?? null), self::nodeList($next['items'] ?? null));
                    if (($next['loose'] ?? false) === true) {
                        $blocks[$last]['loose'] = true;
                    }
                    $this->mergedDefinitionLists[$node] = null;
                }
                foreach ($produced as $block) {
                    $blocks[] = $block;
                }

                continue;
            }
            foreach ($this->inline($node) as $inline) {
                $pending[] = $inline;
            }
        }
        $flush();

        return $blocks;
    }

    private function isBlock(DOMNode $node): bool
    {
        if (!$node instanceof DOMElement) {
            return false;
        }
        $tag = strtolower($node->tagName);
        if (isset(self::BLOCK_TAGS[$tag])) {
            return true;
        }

        return $this->holdsBlock($node, !isset(self::SPELLED_NON_BLOCK_TAGS[$tag]));
    }

    /**
     * Does a block sit under this element? An unsupported element is replaced
     * by its children, so with `$throughWrappers` the search also looks through
     * unsupported ones: `<x-a><x-b><p>` holds the paragraph as `<x-a><p>` does.
     * A spelled element such as `<a>` only counts a direct block child.
     */
    private function holdsBlock(DOMElement $node, bool $throughWrappers): bool
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (isset(self::BLOCK_TAGS[$tag])) {
                return true;
            }
            if ($throughWrappers && !isset(self::SPELLED_NON_BLOCK_TAGS[$tag]) && $this->holdsBlock($child, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function block(DOMNode $node): array
    {
        if (!$node instanceof DOMElement) {
            return [];
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'template', 'noscript'], true)) {
            return [];
        }
        if ($tag === 'figcaption' && self::isOrphanFigcaption($node)) {
            return $this->blocks($this->children($node));
        }
        if ($tag === 'caption' || $tag === 'figcaption') {
            return [];
        }
        if (in_array($tag, ['th', 'td', 'dt', 'dd'], true)) {
            $children = $this->blockInlines($node);

            return $children === [] ? [] : [['type' => 'paragraph', 'children' => $children]];
        }
        $roundTripBlocks = $this->roundTripBlocks($node);
        if ($roundTripBlocks !== null) {
            return $roundTripBlocks;
        }
        if ($tag === 'p') {
            $children = $this->blockInlines($node);
            $attrs = $this->attrs($node, []);
            if ($children === []) {
                // Carve has no empty paragraph; an attribute line would attach to the next block.
                if ($attrs !== [] && !$this->inInlineProjection) {
                    $this->droppedEmptyElements[$node] = null;
                }

                return [];
            }
            $paragraph = ['type' => 'paragraph', 'children' => $children];
            if ($attrs !== []) {
                $paragraph['attrs'] = $attrs;
            }

            return [$paragraph];
        }
        if (preg_match('/^h([1-6])$/D', $tag, $match) === 1) {
            $heading = [
                'type' => 'heading',
                'level' => (int)$match[1],
                'children' => $this->blockInlines($node),
                self::SLUG_KEY => (new HeadingIdTracker())->normalizeId(trim($node->textContent)),
            ];
            $skip = ['data-djot-source-level', 'data-djot-explicit-id'];
            if ($this->headingIdWasGenerated($node)) {
                $skip[] = 'id';
            }
            $this->attachAttrsInElementOrder($heading, $node, $skip);

            return [$heading];
        }
        if ($tag === 'hr') {
            $break = ['type' => 'thematic_break'];
            if (in_array($node->getAttribute('data-char'), ['*', '_'], true)) {
                $break['marker'] = $node->getAttribute('data-char');
            }
            $this->attachAttrs($break, $node, ['data-char']);

            return [$break];
        }
        if ($tag === 'blockquote') {
            $quote = ['type' => 'block_quote', 'children' => $this->blocks($this->children($node))];
            $this->attachAttrs($quote, $node);
            if (($quote['children'][0]['type'] ?? null) === 'list') {
                $this->addHint($quote, "\0carve-leading-blank");
            }

            return [$quote];
        }
        if ($tag === 'pre') {
            return [$this->codeBlock($node)];
        }
        if ($tag === 'div') {
            $math = $this->delimitedMath($node);
            if ($math !== null) {
                return [['type' => 'paragraph', 'children' => [$math]]];
            }
        }
        if ($tag === 'ul' || $tag === 'ol') {
            return $this->listBlocks($node, $tag === 'ol');
        }
        if ($tag === 'dl') {
            return $this->definitionListBlocks($node);
        }
        if ($tag === 'table') {
            $table = $this->table($node);

            return $table === null ? [] : [$table];
        }
        if ($tag === 'figure') {
            return $this->figure($node);
        }
        if (
            $this->importMode === 'roundtrip'
            && in_array($tag, ['address', 'fieldset', 'form', 'hgroup'], true)
            && !self::aRowRefusesTheRegion($node)
        ) {
            $html = $node->ownerDocument?->saveHTML($node);

            $this->keepRaw($node);

            return [
                [
                    'type' => 'raw_block',
                    'content' => is_string($html) ? rtrim($html, "\n") : '',
                    'format' => 'html',
                ],
            ];
        }
        if ($tag === 'section' && strtolower($node->getAttribute('role')) === 'doc-endnotes') {
            return $this->endnotes($node);
        }
        if ($tag === 'details') {
            return [$this->details($node)];
        }
        if ($tag === 'section') {
            return $this->section($node);
        }
        if (
            $tag === 'aside'
            && (
                $this->hasClass($node, 'note')
                || (
                    $this->hasClass($node, 'admonition')
                    && count(preg_split('/\s+/', trim($node->getAttribute('class'))) ?: []) > 1
                )
            )
        ) {
            return $this->container($node);
        }
        if (in_array($tag, ['article', 'main', 'header', 'footer', 'nav', 'aside'], true)) {
            return $this->blocks($this->children($node));
        }
        if ($tag === 'div') {
            return $this->container($node);
        }
        if (
            $this->importMode === 'roundtrip'
            && !$this->isSupportedBlockTag($tag)
            && !self::aRowRefusesTheRegion($node)
        ) {
            $html = $node->ownerDocument?->saveHTML($node);

            $this->keepRaw($node);

            return [
                [
                    'type' => 'paragraph',
                    'children' => [
                        [
                            'type' => 'raw_inline',
                            'content' => is_string($html) ? rtrim($html, "\n") : '',
                            'format' => 'html',
                        ],
                    ],
                ],
            ];
        }

        return $this->blocks($this->children($node));
    }

    private function isSupportedBlockTag(string $tag): bool
    {
        return in_array($tag, [
            'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'footer', 'header', 'hr', 'main',
            'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul', 'h1', 'h2', 'h3',
            'h4', 'h5', 'h6',
            'script', 'style', 'template', 'noscript', 'caption', 'figcaption',
        ], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function section(DOMElement $node): array
    {
        $blocks = $this->blocks($this->children($node));
        // Only the id is the renderer's: it moves a heading's id onto the section
        // it opens (CARVE-P9-019). Anything else on a section has no carrier.
        $heading = $this->firstElementChild($node);
        if (
            $heading === null
            || preg_match('/^h[1-6]$/iD', $heading->tagName) !== 1
            || !$node->hasAttribute('id')
            || $heading->hasAttribute('id')
        ) {
            return $blocks;
        }
        foreach ($blocks as &$block) {
            if (($block['type'] ?? null) === 'heading') {
                $headingAttrs = self::attrsValue($block['attrs'] ?? null);
                $headingAttrs['id'] = $node->getAttribute('id');
                $headingAttrs['order'] = ['#id', ...array_values(array_diff($headingAttrs['order'] ?? [], ['#id']))];
                $block['attrs'] = $headingAttrs;
                if (!$node->hasAttribute('data-djot-explicit-id')) {
                    $block[self::SECTION_ID_KEY] = true;
                }

                break;
            }
        }
        unset($block);

        return $blocks;
    }

    private function firstElementChild(DOMElement $node): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Drops a section id the renderer derives back: its dedup reserves every
     * written id, then numbers the generated slugs in document order. Keeping
     * one id can move a later slug, so the pass repeats until nothing changes.
     *
     * @param array<string, mixed> $tree
     */
    private function resolveSectionIds(array &$tree): void
    {
        $headings = [];
        $written = [];
        $this->collectHeadingIds($tree, $headings, $written);
        $slugs = $this->renderedHeadingSlugs($tree);
        $kept = [];
        foreach ($headings as $index => $heading) {
            if ($slugs === null && $heading['candidate']) {
                $kept[$index] = true;
            }
            $headings[$index]['slug'] = $slugs[$index] ?? $heading['slug'];
        }
        foreach ($headings as $index => $heading) {
            $id = (string)$heading['id'];
            if ($heading['candidate'] && $id !== $heading['slug'] && preg_match('/^' . preg_quote($heading['slug'], '/') . '-[1-9][0-9]*$/D', $id) !== 1) {
                $kept[$index] = true;
            }
        }
        // Keep only the first mismatch per pass: it reserves an id that can
        // give a later heading the number it was written with. Past the pass
        // cap every section id stays explicit, which renders the same ids.
        $passes = 0;
        do {
            if (++$passes > self::SECTION_ID_PASSES) {
                foreach ($headings as $index => $heading) {
                    if ($heading['candidate']) {
                        $kept[$index] = true;
                    }
                }

                break;
            }
            $changed = false;
            $used = $written;
            foreach ($kept as $index => $_) {
                $used[(string)$headings[$index]['id']] = 1;
            }
            foreach ($headings as $index => $heading) {
                if ($heading['id'] !== null && (!$heading['candidate'] || isset($kept[$index]))) {
                    continue;
                }
                $generated = self::dedupeId($heading['slug'], $used);
                if ($heading['candidate'] && $generated !== $heading['id']) {
                    $kept[$index] = true;
                    $changed = true;

                    break;
                }
            }
        } while ($changed);
        $index = 0;
        $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];
        $this->applySectionIds($children, $kept, $index);
        $tree['children'] = $children;
    }

    /**
     * The slug the renderer derives for each heading, from its converted
     * content; null when the decoded headings do not line up with the tree.
     *
     * @param array<string, mixed> $tree
     *
     * @return array<int, string>|null
     */
    private function renderedHeadingSlugs(array $tree): ?array
    {
        $count = 0;
        $this->stripSectionMarkers($tree, $count);
        try {
            $document = (new AstCodec())->decodeImporterTree($tree);
        } catch (Throwable) {
            return null;
        }
        $tracker = new HeadingIdTracker();
        $slugs = [];
        $stack = [$document];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node instanceof Heading) {
                $slugs[] = $tracker->normalizeId($tracker->getPlainText($node));
            }
            foreach (array_reverse($node->getChildren()) as $child) {
                $stack[] = $child;
            }
        }

        return count($slugs) === $count ? $slugs : null;
    }

    /**
     * @param array<mixed> $node
     * @param int $headings
     */
    private function stripSectionMarkers(array &$node, int &$headings): void
    {
        if (($node['type'] ?? null) === 'heading' && is_string($node[self::SLUG_KEY] ?? null)) {
            $headings++;
        }
        unset($node[self::SLUG_KEY], $node[self::SECTION_ID_KEY]);
        foreach ($node as &$child) {
            if (is_array($child)) {
                $this->stripSectionMarkers($child, $headings);
            }
        }
        unset($child);
    }

    /**
     * @param string $base
     * @param array<string, int> $used
     */
    private static function dedupeId(string $base, array &$used): string
    {
        if (!isset($used[$base])) {
            $used[$base] = 1;

            return $base;
        }
        do {
            $used[$base]++;
            $candidate = $base . '-' . $used[$base];
        } while (isset($used[$candidate]));
        $used[$candidate] = 1;

        return $candidate;
    }

    /**
     * @param array<mixed> $node
     * @param array<int, array{slug: string, id: ?string, candidate: bool}> $headings
     * @param array<string, int> $written
     */
    private function collectHeadingIds(array $node, array &$headings, array &$written): void
    {
        $attrs = $node['attrs'] ?? null;
        $id = is_array($attrs) && is_string($attrs['id'] ?? null) ? $attrs['id'] : null;
        $candidate = ($node[self::SECTION_ID_KEY] ?? false) === true;
        if ($id !== null && !$candidate) {
            $written[$id] = 1;
        }
        if (($node['type'] ?? null) === 'heading' && is_string($node[self::SLUG_KEY] ?? null)) {
            $headings[] = ['slug' => $node[self::SLUG_KEY], 'id' => $id, 'candidate' => $candidate];
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collectHeadingIds($child, $headings, $written);
            }
        }
    }

    /**
     * @param array<mixed> $node
     * @param array<int, true> $kept
     * @param int $index
     */
    private function applySectionIds(array &$node, array $kept, int &$index): void
    {
        if (($node['type'] ?? null) === 'heading' && is_string($node[self::SLUG_KEY] ?? null)) {
            if (($node[self::SECTION_ID_KEY] ?? false) === true && !isset($kept[$index]) && is_array($node['attrs'] ?? null)) {
                unset($node['attrs']['id']);
                $order = array_filter(is_array($node['attrs']['order'] ?? null) ? $node['attrs']['order'] : [], 'is_string');
                $node['attrs']['order'] = array_values(array_diff($order, ['#id']));
                if ($node['attrs']['order'] === []) {
                    unset($node['attrs']['order']);
                }
                if ($node['attrs'] === []) {
                    unset($node['attrs']);
                }
            }
            $index++;
        }
        unset($node[self::SLUG_KEY], $node[self::SECTION_ID_KEY]);
        foreach ($node as &$child) {
            if (is_array($child)) {
                $this->applySectionIds($child, $kept, $index);
            }
        }
        unset($child);
    }

    private function headingIdWasGenerated(DOMElement $node): bool
    {
        if ($this->importMode !== 'roundtrip' || !$node->hasAttribute('id') || $node->hasAttribute('data-djot-explicit-id')) {
            return false;
        }
        $names = [];
        foreach ($node->attributes as $attribute) {
            $names[] = strtolower($attribute->nodeName);
        }
        while ($names !== [] && end($names) === 'data-source-line') {
            array_pop($names);
        }
        if ($names === [] || end($names) !== 'id') {
            return false;
        }
        $base = (new HeadingIdTracker())->normalizeId(trim($node->textContent));
        $id = $node->getAttribute('id');
        if ($id === $base) {
            return true;
        }
        $tail = str_starts_with($id, $base . '-') ? substr($id, strlen($base) + 1) : '';

        return $tail !== ''
            && $tail !== '1'
            && !str_starts_with($tail, '0')
            && preg_match('/^[0-9]+$/D', $tail) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function details(DOMElement $node): array
    {
        $domChildren = $this->children($node);
        // An inline-only slot holds no admonition, so the title it would carry
        // has nowhere to go and the `<summary>` left the document (carve-php#2371).
        // A caption is such a slot as much as a cell is, and taking the cell's
        // path keeps the summary in the same inline run as the body.
        if ($this->isInsideTableCell($node) || $this->inCaption) {
            return [
                'type' => 'div',
                'children' => $this->blocks($domChildren),
            ];
        }
        $title = [];
        foreach ($domChildren as $index => $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'summary') {
                if ($this->summaryCanBeTitle($child)) {
                    $title = $this->blockInlines($child);
                    unset($domChildren[$index]);
                }

                break;
            }
        }
        $details = [
            'type' => 'admonition',
            'kind' => 'details',
            'children' => $this->blocks(array_values($domChildren)),
        ];
        if ($title !== []) {
            $details['title'] = $title;
        }
        $this->attachAttrs($details, $node);

        return $details;
    }

    private function summaryCanBeTitle(DOMElement $summary): bool
    {
        if (str_contains($summary->textContent, '"') || str_contains($summary->textContent, "\n")) {
            return false;
        }
        $blockChildren = 0;
        foreach ($summary->childNodes as $child) {
            if ($child instanceof DOMElement && $this->isBlock($child) && ++$blockChildren > 1) {
                return false;
            }
        }

        return $this->blockInlines($summary) !== [];
    }

    /**
     * Would a raw region for this element have to fit on one line?
     *
     * A table row IS one line: a region holding a newline ends the row there and
     * takes the table with it. A caption is not a row and carries one, so the
     * nearest slot decides rather than the tag. The report walk asks the same
     * question about a figure, which is why this is static.
     *
     * @see markup-carve/carve#2284
     */

    /**
     * A `<figcaption>` that is not a figure's child has nothing to caption, so
     * it gives way to its children like any unsupported element.
     */
    public static function isOrphanFigcaption(DOMElement $node): bool
    {
        $parent = $node->parentNode;

        return !$parent instanceof DOMElement || strtolower($parent->tagName) !== 'figure';
    }

    public static function aRowRefusesTheRegion(DOMElement $node): bool
    {
        for ($ancestor = $node->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            $tag = strtolower($ancestor->tagName);
            if ($tag === 'caption' || $tag === 'figcaption') {
                return false;
            }
            if ($tag === 'td' || $tag === 'th') {
                $html = $node->ownerDocument?->saveHTML($node);

                return is_string($html) && str_contains(rtrim($html, "\n"), "\n");
            }
        }

        return false;
    }

    private function isInsideTableCell(DOMElement $node): bool
    {
        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (in_array(strtolower($parent->tagName), ['td', 'th'], true)) {
                return true;
            }
        }

        return false;
    }

    private function codeLanguage(DOMElement $pre, ?DOMElement $code): ?string
    {
        foreach ($code !== null ? [$code, $pre] : [$pre] as $node) {
            $tokens = $this->codeLanguageClasses($node);
            $language = $this->prefixedCodeLanguage($tokens, 'language-')
                ?? $this->prefixedCodeLanguage($tokens, 'lang-');
            if ($language !== null) {
                return $language;
            }
            $data = trim($node->getAttribute('data-lang'), " \t\n\r\f");
            if ($this->validCodeLanguage($data)) {
                return $data;
            }
            if ($node === $pre) {
                preg_match_all('/(?=(?:^|[\t\n\f\r ;])brush:[\t\n\f\r ]*([^\t\n\f\r ;]+))/', $node->getAttribute('class'), $matches);
                foreach ($matches[1] as $value) {
                    if ($this->validCodeLanguage($value)) {
                        return $value;
                    }
                }
            }
        }
        $parent = $pre->parentNode;
        if (!$parent instanceof DOMElement || !$this->codeLanguageWrapper($parent, $pre)) {
            return null;
        }
        $tokens = $this->codeLanguageClasses($parent);
        $direct = (in_array('highlight', $tokens, true) ? $this->prefixedCodeLanguage($tokens, 'highlight-source-') : null)
            ?? (in_array('mw-highlight', $tokens, true) ? $this->prefixedCodeLanguage($tokens, 'mw-highlight-lang-') : null);
        if ($direct !== null) {
            return $direct;
        }
        $outer = $parent->parentNode;

        return in_array('highlight', $tokens, true) && $outer instanceof DOMElement && $this->codeLanguageWrapper($outer, $parent)
            ? $this->prefixedCodeLanguage($this->codeLanguageClasses($outer), 'highlight-')
            : null;
    }

    private function validCodeLanguage(string $value): bool
    {
        return $value !== '' && preg_match('/[^a-zA-Z0-9_+#.\/-]/', $value) === 0;
    }

    /**
     * @return list<string>
     */
    private function codeLanguageClasses(DOMElement $node): array
    {
        return preg_split('/[\t\n\f\r ]+/', $node->getAttribute('class')) ?: [];
    }

    /**
     * @param list<string> $tokens
     * @param string $prefix
     */
    private function prefixedCodeLanguage(array $tokens, string $prefix): ?string
    {
        foreach ($tokens as $token) {
            if (!str_starts_with($token, $prefix) || ($prefix === 'highlight-' && str_starts_with($token, 'highlight-source-'))) {
                continue;
            }
            $value = substr($token, strlen($prefix));
            if ($this->validCodeLanguage($value)) {
                return $value;
            }
        }

        return null;
    }

    private function codeLanguageWrapper(DOMElement $parent, DOMElement $child): bool
    {
        if (strtolower($parent->tagName) !== 'div') {
            return false;
        }
        if (!$this->codeLanguageWrappers->offsetExists($parent)) {
            $element = null;
            $eligible = true;
            foreach ($parent->childNodes as $node) {
                if ($node instanceof DOMElement && $element === null) {
                    $element = $node;
                } elseif ($node instanceof DOMComment || ($node instanceof DOMText && preg_match('/[^\t\n\f\r ]/', $node->textContent) === 0)) {
                    continue;
                } else {
                    $eligible = false;

                    break;
                }
            }
            $this->codeLanguageWrappers[$parent] = $eligible ? $element : null;
        }

        return $this->codeLanguageWrappers[$parent] === $child;
    }

    /**
     * @return array<string, mixed>
     */
    private function codeBlock(DOMElement $node): array
    {
        $code = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'code') {
                $code = $child;

                break;
            }
        }
        $source = $code ?? $node;
        $content = $source->textContent;
        // An HTML parser drops one line feed right after `<pre>`; libxml keeps it.
        if ($source === $node && $node->firstChild instanceof DOMText && str_starts_with($node->firstChild->data, "\n")) {
            $content = substr($content, 1);
        }
        if (str_ends_with($content, "\n")) {
            $content = substr($content, 0, -1);
        }
        $block = ['type' => 'code_block', 'content' => $content];
        $class = $source->getAttribute('class');
        $language = $this->codeLanguage($node, $code);
        if ($language !== null) {
            $block['lang'] = $language;
        }
        $skip = [];
        $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
        if (strtolower($node->getAttribute('role')) === 'img' && $classes !== []) {
            if ($node->getAttribute('aria-label') === $classes[0]) {
                $skip[] = 'aria-label';
            }
        }
        $attrs = $this->attrs($node, $skip);
        if (
            $node->hasAttribute('aria-label')
            && strcasecmp($node->getAttribute('aria-label'), trim($class)) !== 0
        ) {
            $keyValues = $attrs['keyValues'] ?? [];
            $order = $attrs['order'] ?? [];
            $keyValues['aria-label'] = $node->getAttribute('aria-label');
            $order[] = 'aria-label';
            $attrs['keyValues'] = $keyValues;
            $attrs['order'] = $order;
        }
        if ($attrs !== []) {
            $block['attrs'] = $attrs;
        }

        return $block;
    }

    /**
     * @return array<string, mixed>
     */
    private function list(DOMElement $node, bool $ordered): array
    {
        $items = [];
        $hasLooseItem = false;
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement || strtolower($child->tagName) !== 'li') {
                continue;
            }
            $task = $this->taskCheckbox($child);
            $taskState = $child->getAttribute('data-task-state');
            $checkboxChecked = $task !== null && (
                $task->hasAttribute('checked')
                || strtolower($child->getAttribute('data-checked')) === 'true'
            );
            $consumesTaskState = $task !== null
                && in_array($taskState, ['', '-', 'x', 'X', ' '], true)
                && (!in_array($taskState, ['x', 'X'], true) || $checkboxChecked);
            // An ORDERED task item has no Carve spelling: `task_marker` hangs off
            // `unordered_item` alone (PART 3). The writer keeps the characters
            // the box was read from and loses the task-item semantics, which the
            // import report names (carve-php#2381). The AST exit holds the box on
            // the item and reports nothing - the split PART 12 section 16 draws.
            $unspellableTask = $ordered && $task !== null && $this->sourceSafe;
            if ($unspellableTask) {
                $this->orderedTaskBrackets[spl_object_id($task)] = '['
                    . ($consumesTaskState && $taskState !== '' && !in_array($taskState, ['x', 'X', ' '], true)
                        ? $taskState
                        : ($checkboxChecked ? 'x' : ' '))
                    . ']';
            }
            $itemChildren = $this->blocks($this->children($child));
            foreach (array_slice($itemChildren, 1) as $laterBlock) {
                if (in_array($laterBlock['type'] ?? null, ['paragraph', 'figure'], true)) {
                    $hasLooseItem = true;

                    break;
                }
            }
            $item = ['type' => 'list_item', 'children' => $itemChildren];
            $skipItemAttrs = $consumesTaskState ? ['data-task-state'] : [];
            if ($task !== null && strtolower($child->getAttribute('data-type')) === 'taskitem') {
                $skipItemAttrs[] = 'data-type';
            }
            if ($task !== null && $child->hasAttribute('data-checked')) {
                $skipItemAttrs[] = 'data-checked';
            }
            $this->attachAttrs($item, $child, $skipItemAttrs);
            if ($task !== null && !$unspellableTask) {
                $item['checked'] = $checkboxChecked;
                if ($consumesTaskState && $taskState !== '' && !in_array($taskState, ['x', 'X', ' '], true)) {
                    $item['taskState'] = $taskState;
                }
            }
            $items[] = $item;
        }
        $tight = !$hasLooseItem;
        foreach ($node->childNodes as $itemElement) {
            if (!$itemElement instanceof DOMElement || strtolower($itemElement->tagName) !== 'li') {
                continue;
            }
            foreach ($itemElement->childNodes as $itemChild) {
                if ($itemChild instanceof DOMElement && strtolower($itemChild->tagName) === 'p') {
                    $tight = false;

                    break 2;
                }
            }
        }
        $list = ['type' => 'list', 'ordered' => $ordered, 'tight' => $tight, 'items' => $items];
        if ($node->hasAttribute('data-marker')) {
            $marker = $node->getAttribute('data-marker');
            if (in_array($marker, ['-', '*', '.', ')'], true)) {
                $list[$ordered ? 'delim' : 'bulletChar'] = $marker;
            }
        }
        if ($ordered && $node->hasAttribute('start')) {
            $start = filter_var($node->getAttribute('start'), FILTER_VALIDATE_INT);
            if (is_int($start)) {
                $list['start'] = $start;
            }
        }
        $olType = $ordered ? $node->getAttribute('type') : '';
        $fallbackTypeAttribute = false;
        if (in_array($olType, ['a', 'A', 'i', 'I'], true)) {
            $list['olType'] = $olType;
            if (strtolower($olType) === 'a') {
                $start = (int)($list['start'] ?? 1);
                $last = $start + count($items) - 1;
                $letter = $start >= 1 && $start <= 26 ? chr(96 + $start) : '';
                $fallbackTypeAttribute = $last > 26
                    || (count($items) === 1 && str_contains('ivxlcdm', $letter));
            }
        }
        $skipListAttrs = ['start', 'reversed', 'data-type', 'data-marker'];
        if (!$fallbackTypeAttribute) {
            $skipListAttrs[] = 'type';
        }
        $this->attachAttrs($list, $node, $skipListAttrs);
        if ($this->hasClass($node, 'task-list')) {
            $this->removeStructuralClass($list, 'task-list');
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listBlocks(DOMElement $node, bool $ordered): array
    {
        $strays = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) === '') {
                continue;
            }
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                continue;
            }
            $strays[] = $child;
        }
        $blocks = $this->blocks($strays);
        $list = $this->list($node, $ordered);
        if (($list['items'] ?? []) !== []) {
            $blocks[] = $list;
        }

        return $blocks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function endnotes(DOMElement $section): array
    {
        $found = false;
        $unreferenced = [];
        foreach ($this->directEndnoteItems($section) as $item) {
            $id = $item->getAttribute('id');
            if ($item->hasAttribute('data-djot-inline-footnote')) {
                $found = true;

                continue;
            }
            $label = $item->getAttribute('data-djot-footnote-label');
            if ($label === '') {
                $label = $this->footnoteLabel($id);
            }
            $referenced = $id !== '' && isset($this->footnoteTargets[$id]);
            if (!$referenced && $label !== '') {
                foreach (array_keys($this->footnoteTargets) as $target) {
                    if ($this->footnoteLabel($target) === $label) {
                        $referenced = true;

                        break;
                    }
                }
            }
            if (!$referenced) {
                $unreferenced[] = [
                    'type' => 'list_item',
                    'children' => $this->blocks($this->children($item)),
                ];

                continue;
            }
            $found = true;
            $previous = $this->inFootnoteDefinition;
            $this->inFootnoteDefinition = true;
            try {
                $children = $this->blocks($this->children($item));
            } finally {
                $this->inFootnoteDefinition = $previous;
            }
            $definition = [
                'type' => 'footnote',
                'label' => $label,
                'children' => $children,
            ];
            $this->addHint($definition, "\0carve-indent-blank-lines");
            $this->addHint($definition, "\0carve-compact-definition");
            $this->footnoteDefinitions[] = $definition;
        }
        if (!$found) {
            return $this->blocks($this->children($section));
        }
        if ($unreferenced !== []) {
            return [
                [
                    'type' => 'list',
                    'ordered' => true,
                    'tight' => false,
                    'items' => $unreferenced,
                ],
            ];
        }
        if (!$this->hasFollowingContent($section)) {
            return [];
        }

        return [$this->namedContainer('footnotes', [])];
    }

    /**
     * @return list<\DOMElement>
     */
    private function directEndnoteItems(DOMElement $section): array
    {
        $items = [];
        foreach ($section->childNodes as $child) {
            if (!$child instanceof DOMElement || strtolower($child->tagName) !== 'ol') {
                continue;
            }
            foreach ($child->childNodes as $item) {
                if ($item instanceof DOMElement && strtolower($item->tagName) === 'li') {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    private function hasFollowingContent(DOMElement $node): bool
    {
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            for ($sibling = $current->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
                if ($sibling instanceof DOMText) {
                    if (trim($sibling->textContent) !== '') {
                        return true;
                    }

                    continue;
                }
                if (!$sibling instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($sibling->tagName);
                if (in_array($tag, ['script', 'style', 'template', 'noscript'], true)) {
                    continue;
                }
                if (in_array($tag, ['hr', 'br'], true)) {
                    return true;
                }
                if ($tag === 'img' && !self::carriesNoDestination($sibling->getAttribute('src'))) {
                    return true;
                }
                foreach ($sibling->childNodes as $child) {
                    if ($child instanceof DOMElement || ($child instanceof DOMText && trim($child->textContent) !== '')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function footnoteLabel(string $fragment): string
    {
        return preg_replace('/^(?:fn|footnote[-_]?)/i', '', $fragment) ?: $fragment;
    }

    /**
     * A `<dd>` before the list's first `<dt>` writes its content as blocks
     * ahead of the list, because `: text` with no term re-reads as a paragraph
     * (markup-carve/carve#2384). A list left with no entry is not written.
     *
     * @return list<array<string, mixed>>
     */
    private function definitionListBlocks(DOMElement $node): array
    {
        $before = [];
        $items = [];
        foreach (self::definitionListEntries($node) as $candidate) {
            if ($items === [] && strtolower($candidate->tagName) === 'dd') {
                array_push($before, ...$this->blocks($this->children($candidate)));

                continue;
            }
            $this->appendDefinitionItem($items, $candidate);
        }
        if ($items === []) {
            return $before;
        }
        $list = ['type' => 'definition_list', 'items' => $items];
        $this->attachAttrs($list, $node);
        // A leading <dd> can end in a definition list, which this one would
        // join on re-read; merge it the way a sibling <dl> is (carve#2369).
        $last = array_key_last($before);
        if ($last !== null && ($before[$last]['type'] ?? null) === 'definition_list' && $this->attrs($node, []) === []) {
            $before[$last]['items'] = array_merge(self::nodeList($before[$last]['items'] ?? null), $items);
            $this->mergedDefinitionLists[$node] = null;

            return $before;
        }
        $before[] = $list;

        return $before;
    }

    /**
     * The `<dt>` and `<dd>` elements of a `<dl>`, through its group `<div>`s.
     *
     * @return list<\DOMElement>
     */
    public static function definitionListEntries(DOMElement $node): array
    {
        $entries = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $candidates = strtolower($child->tagName) === 'div' ? iterator_to_array($child->childNodes) : [$child];
            foreach ($candidates as $candidate) {
                if ($candidate instanceof DOMElement && in_array(strtolower($candidate->tagName), ['dt', 'dd'], true)) {
                    $entries[] = $candidate;
                }
            }
        }

        return $entries;
    }

    /**
     * The `<dd>` elements before a `<dl>`'s first `<dt>`.
     *
     * @return list<\DOMElement>
     */
    public static function leadingTermlessDescriptions(DOMElement $node): array
    {
        $leading = [];
        foreach (self::definitionListEntries($node) as $entry) {
            if (strtolower($entry->tagName) === 'dt') {
                break;
            }
            $leading[] = $entry;
        }

        return $leading;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param \DOMElement $child
     */
    private function appendDefinitionItem(array &$items, DOMElement $child): void
    {
        $tag = strtolower($child->tagName);
        if ($tag === 'dt') {
            $term = ['type' => 'definition_term', 'children' => $this->blockInlines($child)];
            $this->attachAttrs($term, $child);
            $items[] = $term;
        } elseif ($tag === 'dd') {
            $description = ['type' => 'definition_description', 'children' => $this->blocks($this->children($child))];
            $this->attachAttrs($description, $child);
            $items[] = $description;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function table(DOMElement $node): ?array
    {
        $rows = [];
        $listForm = $this->listTableForBlockCells && $this->tableHasBlockCell($node);
        /** @var list<list<list<array<string, mixed>>|null>> $cellBlockGrid */
        $cellBlockGrid = [];
        /** @var list<list<array{align: ?string, valign: ?string}|null>> $ownAlignmentGrid */
        $ownAlignmentGrid = [];
        $keptRows = [];
        $headerRows = 0;
        $sawBodyRow = false;
        /** @var array<int, int> $rowspans */
        $rowspans = [];
        foreach ($this->directTableRows($node) as $rowElement) {
            $cells = [];
            $cellBlocks = [];
            $ownAlignment = [];
            $rowIsAllHeader = true;
            $column = 0;
            $activeRowspans = $rowspans;
            $rowspans = [];
            foreach ($rowElement->childNodes as $cellElement) {
                if (!$cellElement instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($cellElement->tagName);
                if ($tag !== 'td' && $tag !== 'th') {
                    continue;
                }
                $isHeader = $tag === 'th';
                $rowIsAllHeader = $rowIsAllHeader && $isHeader;
                while (($activeRowspans[$column] ?? 0) > 0) {
                    $cells[] = $this->spanCell('rowspan');
                    $cellBlocks[] = null;
                    $ownAlignment[] = null;
                    if ($activeRowspans[$column] > 1) {
                        $rowspans[$column] = $activeRowspans[$column] - 1;
                    }
                    ++$column;
                }
                $colspan = max(1, (int)$cellElement->getAttribute('colspan'));
                $lastColumn = $column + $colspan - 1;
                $allowsEmptyCode = $listForm
                    || (!$this->hasFollowingTableCell($cellElement) && $colspan === 1);
                foreach ($activeRowspans as $spanColumn => $remaining) {
                    if ($remaining > 0 && $spanColumn > $lastColumn) {
                        $allowsEmptyCode = false;

                        break;
                    }
                }
                $previousProjection = $this->inInlineProjection;
                $this->inInlineProjection = $previousProjection || !$listForm;
                $previousCellContext = $this->tableCellAllowsEmptyCode;
                $this->tableCellAllowsEmptyCode = $allowsEmptyCode;
                try {
                    $blocks = $this->blocks($this->children($cellElement));
                    $children = $listForm ? [] : $this->flattenBlocks($blocks);
                } finally {
                    $this->tableCellAllowsEmptyCode = $previousCellContext;
                    $this->inInlineProjection = $previousProjection;
                }
                $cell = [
                    'type' => 'table_cell',
                    'header' => $tag === 'th',
                    'children' => $children,
                ];
                $horizontal = $this->styleEnum($cellElement, 'text-align', ['left', 'right', 'center']);
                $vertical = $this->styleEnum($cellElement, 'vertical-align', ['top', 'middle', 'bottom']);
                if ($this->importMode !== 'safe') {
                    if ($horizontal !== null) {
                        $cell['align'] = $horizontal;
                    }
                    if ($vertical !== null) {
                        $cell['valign'] = $vertical;
                    }
                }
                $skipAttrs = ['colspan', 'rowspan'];
                if (in_array(strtolower($cellElement->getAttribute('scope')), ['col', 'row'], true)) {
                    $skipAttrs[] = 'scope';
                }
                if ($horizontal !== null) {
                    $skipAttrs[] = 'align';
                }
                if ($vertical !== null) {
                    $skipAttrs[] = 'valign';
                }
                $this->attachAttrs($cell, $cellElement, $skipAttrs);
                $rowspan = max(1, (int)$cellElement->getAttribute('rowspan'));
                $cells[] = $cell;
                $cellBlocks[] = $blocks;
                $ownAlignment[] = ['align' => $cell['align'] ?? null, 'valign' => $cell['valign'] ?? null];
                for ($offset = 0; $offset < $colspan; ++$offset) {
                    if ($rowspan > 1) {
                        $rowspans[$column + $offset] = $rowspan - 1;
                    }
                    if ($offset > 0) {
                        $cells[] = $this->spanCell('colspan');
                        $cellBlocks[] = null;
                        $ownAlignment[] = null;
                    }
                }
                $column += $colspan;
            }
            while (($activeRowspans[$column] ?? 0) > 0) {
                $cells[] = $this->spanCell('rowspan');
                $cellBlocks[] = null;
                $ownAlignment[] = null;
                if ($activeRowspans[$column] > 1) {
                    $rowspans[$column] = $activeRowspans[$column] - 1;
                }
                ++$column;
            }
            if ($cells !== []) {
                // A list table spells a row of empty cells, so only the pipe form drops it.
                $blank = !$listForm && self::every(
                    $cells,
                    static fn (array $cell): bool => self::cellWritesBlank($cell),
                );
                if ($blank) {
                    $this->droppedBlankTableRows[$rowElement] = null;

                    continue;
                }
                $row = ['type' => 'table_row', 'cells' => $cells];
                if (!$listForm) {
                    $this->attachAttrs($row, $rowElement);
                }
                $rows[] = $row;
                $keptRows[] = $rowElement;
                if ($rowIsAllHeader && !$sawBodyRow) {
                    ++$headerRows;
                } else {
                    $sawBodyRow = true;
                }
                $cellBlockGrid[] = $cellBlocks;
                $ownAlignmentGrid[] = $ownAlignment;
            } elseif ($listForm) {
                // A list-table row is the list of its cells, so a row with none has no spelling.
                $this->droppedBlankTableRows[$rowElement] = null;
            }
        }
        if ($rows === []) {
            $attributedSection = false;
            foreach ($node->childNodes as $section) {
                if ($section instanceof DOMElement && in_array(strtolower($section->tagName), ['thead', 'tbody', 'tfoot'], true) && $this->attrs($section, []) !== []) {
                    $attributedSection = true;
                }
            }
            if (!$attributedSection || $this->sourceSafe) {
                return null;
            }
        }
        $columnAlignments = [];
        if ($rows !== []) {
            foreach ($rows[0]['cells'] as $column => &$headCell) {
                if (!$headCell['header']) {
                    continue;
                }
                $element = $this->tableCellElementAt($node, 0, $column);
                $alignment = $headCell['align'] ?? ($element instanceof DOMElement
                ? $this->styleEnum($element, 'text-align', ['left', 'right', 'center'])
                : null);
                if ($alignment !== null) {
                    $headCell['align'] = $alignment;
                    $columnAlignments[$column] = $alignment;
                }
            }
            unset($headCell);
        }
        foreach ($rows as $rowIndex => &$row) {
            if ($rowIndex === 0) {
                continue;
            }
            foreach ($row['cells'] as $column => &$cell) {
                if (($cell['align'] ?? null) === ($columnAlignments[$column] ?? null)) {
                    unset($cell['align']);
                }
            }
            unset($cell);
        }
        unset($row);
        if ($listForm) {
            return $this->listTableOf($node, $rows, $cellBlockGrid, $ownAlignmentGrid);
        }
        $table = ['type' => 'table', 'rows' => $rows];
        $headerAt = static function (int $r, int $c) use (&$headerAt, $rows): bool {
            $cell = $rows[$r]['cells'][$c] ?? null;
            if ($cell === null) {
                return false;
            }
            if (($cell['span'] ?? null) === 'rowspan') {
                return $r > 0 && $headerAt($r - 1, $c);
            }
            if (($cell['span'] ?? null) === 'colspan') {
                return $c > 0 && $headerAt($r, $c - 1);
            }

            return $cell['header'];
        };
        $groups = ['headRows' => 0, 'footRows' => 0, 'bodies' => []];
        $phase = 0;
        $valid = true;
        $hasSectionAttrs = false;
        $plans = [];
        foreach ($node->childNodes as $section) {
            if (!$section instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($section->tagName);
            if ($tag === 'tr') {
                $index = array_search($section, $keptRows, true);
                if ($index === false) {
                    continue;
                }
                $last = array_key_last($plans);
                if ($last !== null && $plans[$last]['section'] === null) {
                    $plans[$last]['indices'][] = $index;
                } else {
                    $plans[] = ['section' => null, 'indices' => [$index]];
                }
            } elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                $plans[] = ['section' => $section, 'indices' => array_keys(array_filter($keptRows, static fn (DOMElement $row): bool => $row->parentNode === $section))];
            }
        }
        foreach ($plans as $plan) {
            $section = $plan['section'];
            $tag = $section !== null ? strtolower($section->tagName) : 'tbody';
            $rank = ['thead' => 0, 'tbody' => 1, 'tfoot' => 2][$tag];
            $valid = $valid && $rank >= $phase;
            $phase = $rank;
            $indices = $plan['indices'];
            $count = count($indices);
            $own = $section !== null ? $this->attrs($section, []) : [];
            $hasSectionAttrs = $hasSectionAttrs || $own !== [];
            if ($tag === 'tbody') {
                $bodyHead = 0;
                $rowHeadColumns = null;
                foreach ($indices as $r) {
                    $lead = 0;
                    $cellCount = count($rows[$r]['cells']);
                    while ($lead < $cellCount && $headerAt($r, $lead)) {
                        $lead++;
                    }
                    if ($bodyHead < $count && $rowHeadColumns === null && $lead === count($rows[$r]['cells'])) {
                        $bodyHead++;
                    } else {
                        $rowHeadColumns = $rowHeadColumns === null ? $lead : min($rowHeadColumns, $lead);
                    }
                }
                $groups['bodies'][] = [
                    'headRows' => $bodyHead,
                    'bodyRows' => $count - $bodyHead,
                    ...($rowHeadColumns > 0 ? ['rowHeadColumns' => $rowHeadColumns] : []),
                    ...($own !== [] ? ['attrs' => $own] : []),
                ];
            } else {
                $prefix = $tag === 'thead' ? 'head' : 'foot';
                $groups[$prefix . 'Rows'] += $count;
                if ($own !== []) {
                    if (isset($groups[$prefix . 'Attrs'])) {
                        $valid = false;
                    }
                    $groups[$prefix . 'Attrs'] = $own;
                }
            }
        }
        if (!isset($groups['headAttrs']) && $groups['headRows'] === 0 && count($groups['bodies']) === 1 && $headerRows > 0) {
            $absorbed = min($headerRows, $groups['bodies'][0]['headRows']);
            $groups['headRows'] = $absorbed;
            $groups['bodies'][0]['headRows'] -= $absorbed;
        }
        $counted = $groups['headRows'] + $groups['footRows'] + array_sum(array_column($groups['bodies'], 'bodyRows')) + array_sum(array_column($groups['bodies'], 'headRows'));
        if ($valid && $counted === count($rows) && ($hasSectionAttrs || $groups['footRows'] > 0 || count($groups['bodies']) > 1)) {
            $table['rowGroups'] = $groups;
            $this->retainedTablePartitions[self::importPath($node)] = true;
            foreach ($node->childNodes as $section) {
                if (!$section instanceof DOMElement || !in_array(strtolower($section->tagName), ['thead', 'tbody', 'tfoot'], true)) {
                    continue;
                }
                $attrs = $this->attrs($section, []);
                $names = array_keys($attrs['keyValues'] ?? []);
                if (isset($attrs['id'])) {
                    $names[] = 'id';
                }
                if (isset($attrs['classes'])) {
                    $names[] = 'class';
                }
                $this->retainedTableAttributes[self::importPath($section)] = $names;
            }
        }

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'caption') {
                $caption = $this->captionInlines($child);
                if ($caption !== []) {
                    $table['caption'] = $caption;
                }

                break;
            }
        }
        $this->attachAttrs($table, $node, ['data-djot-col-widths']);
        if (
            $this->sourceSafe
            && $headerRows > 0
            && $node->hasAttribute('data-djot-col-widths')
            && !$node->hasAttribute('data-djot-src')
        ) {
            $this->setPrivateAttribute($table, "\0carve-col-widths", $node->getAttribute('data-djot-col-widths'));
        }
        if ($this->sourceSafe && $headerRows > 0 && $this->importedTableNeedsDelimiter($rows)) {
            $this->addHint($table, "\0carve-delimiter-row");
        }

        return $table;
    }

    /**
     * @param array<string, mixed> $cell
     */
    private static function cellWritesBlank(array $cell): bool
    {
        if (isset($cell['span']) || isset($cell['align']) || isset($cell['valign']) || self::attrsValue($cell['attrs'] ?? null) !== []) {
            return false;
        }
        foreach (self::nodeList($cell['children'] ?? null) as $child) {
            $type = $child['type'] ?? null;
            if ($type === 'hard_break' || $type === 'soft_break') {
                continue;
            }
            if ($type !== 'text' || trim(self::stringValue($child['value'] ?? null)) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @phpstan-param list<TableRowNode> $rows
     *
     * @param list<array<string, mixed>> $rows
     */
    private function importedTableNeedsDelimiter(array $rows): bool
    {
        $cells = $rows[0]['cells'] ?? [];
        $firstSpan = null;
        foreach ($cells as $index => $cell) {
            if (isset($cell['span'])) {
                $firstSpan = $index;

                break;
            }
        }
        if ($firstSpan === null) {
            return false;
        }
        if ($firstSpan === 0) {
            return true;
        }
        foreach (array_slice($cells, $firstSpan) as $cell) {
            if (($cell['span'] ?? null) !== 'colspan') {
                return true;
            }
        }

        return false;
    }

    /**
     * @phpstan-return TableCellNode
     *
     * @phpstan-param 'rowspan'|'colspan' $span
     *
     * @return array<string, mixed>
     */
    private function spanCell(string $span): array
    {
        return [
            'type' => 'table_cell',
            'span' => $span,
            'header' => false,
            'children' => [],
        ];
    }

    private function tableHasBlockCell(DOMElement $table): bool
    {
        foreach ($this->directTableRows($table) as $row) {
            foreach ($row->childNodes as $cell) {
                if (
                    $cell instanceof DOMElement
                    && in_array(strtolower($cell->tagName), ['td', 'th'], true)
                    && self::cellHoldsBlocks($cell)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The pipe-table grid, written as the `::: list-table` it is equivalent to
     * (docs/html-import-contract.md in the spec, "A table whose cells hold
     * blocks can be written as a list table").
     *
     * @param \DOMElement $node
     * @param list<array{cells: list<array<string, mixed>>}> $rows
     * @param list<list<list<array<string, mixed>>|null>> $cellBlockGrid
     * @param list<list<array{align: ?string, valign: ?string}|null>> $ownAlignmentGrid
     *
     * @return array<string, mixed>
     */
    private function listTableOf(DOMElement $node, array $rows, array $cellBlockGrid, array $ownAlignmentGrid): array
    {
        $headerAt = static function (int $r, int $c) use (&$headerAt, $rows): bool {
            $cell = $rows[$r]['cells'][$c] ?? null;
            if ($cell === null) {
                return false;
            }
            if (($cell['span'] ?? null) === 'rowspan') {
                return $r > 0 && $headerAt($r - 1, $c);
            }
            if (($cell['span'] ?? null) === 'colspan') {
                return $c > 0 && $headerAt($r, $c - 1);
            }

            return ($cell['header'] ?? false) === true;
        };
        $leading = static function (int $r) use ($headerAt, $rows): int {
            $n = 0;
            $count = count($rows[$r]['cells']);
            while ($n < $count && $headerAt($r, $n)) {
                $n++;
            }

            return $n;
        };
        $rowCount = count($rows);
        $headerRows = 0;
        foreach ($rows as $row) {
            if ($leading($headerRows) !== count($row['cells'])) {
                break;
            }
            $headerRows++;
        }
        $headerCols = null;
        for ($r = $headerRows; $r < $rowCount; $r++) {
            $headerCols = $headerCols === null ? $leading($r) : min($headerCols, $leading($r));
        }
        $headerCols ??= 0;

        $items = [];
        foreach ($rows as $r => $row) {
            $cellItems = [];
            foreach ($row['cells'] as $c => $cell) {
                $span = $cell['span'] ?? null;
                if ($span !== null) {
                    $cellItems[] = [
                        'type' => 'list_item',
                        'children' => [
                            [
                                'type' => 'paragraph',
                                'children' => [['type' => 'text', 'value' => $span === 'rowspan' ? '^' : '<']],
                            ],
                        ],
                    ];

                    continue;
                }
                $children = $cellBlockGrid[$r][$c] ?? [];
                if (
                    count($children) === 1
                    && ($children[0]['type'] ?? null) === 'paragraph'
                    && !isset($children[0]['attrs'])
                    && count(self::nodeList($children[0]['children'] ?? null)) === 1
                ) {
                    $text = self::nodeList($children[0]['children'])[0];
                    $value = $text['value'] ?? null;
                    if (($text['type'] ?? null) === 'text' && !isset($text['attrs']) && ($value === '^' || $value === '<')) {
                        $children[0]['children'] = [['type' => 'escaped_text', 'value' => $value]];
                    }
                }
                $attrs = is_array($cell['attrs'] ?? null) ? $cell['attrs'] : [];
                $keyValues = is_array($attrs['keyValues'] ?? null) ? $attrs['keyValues'] : [];
                $order = is_array($attrs['order'] ?? null) ? $attrs['order'] : [];
                $put = static function (string $key, ?string $value) use (&$keyValues, &$order): void {
                    if ($value === null || array_key_exists($key, $keyValues)) {
                        return;
                    }
                    $keyValues[$key] = $value;
                    $order[] = $key;
                };
                if (($cell['header'] ?? false) === true && $r >= $headerRows && $c >= $headerCols) {
                    $put('header', '');
                }
                $own = $ownAlignmentGrid[$r][$c] ?? null;
                $put('align', $own['align'] ?? null);
                $put('valign', $own['valign'] ?? null);
                if ($keyValues !== []) {
                    $attrs['keyValues'] = $keyValues;
                    $attrs['order'] = $order;
                }
                $item = ['type' => 'list_item', 'children' => $children];
                if ($attrs !== []) {
                    $item['attrs'] = $attrs;
                }
                $cellItems[] = $item;
            }
            $items[] = [
                'type' => 'list_item',
                'children' => [
                    [
                        'type' => 'list',
                        'ordered' => false,
                        'tight' => !self::some(
                            $cellItems,
                            static fn (array $item): bool => count($item['children']) > 1,
                        ),
                        'items' => $cellItems,
                    ],
                ],
            ];
        }

        $admonition = [
            'type' => 'admonition',
            'kind' => 'list-table',
            'children' => [['type' => 'list', 'ordered' => false, 'tight' => true, 'items' => $items]],
        ];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'caption') {
                $title = $this->captionInlines($child);
                if ($title !== []) {
                    $admonition['title'] = $title;
                }

                break;
            }
        }
        $attrs = $this->attrs($node, ['data-djot-col-widths']);
        foreach (['header-rows' => $headerRows, 'header-cols' => $headerCols] as $key => $count) {
            if ($count > 0) {
                $attrs['keyValues'][$key] = (string)$count;
                $attrs['order'][] = $key;
            }
        }
        if ($attrs !== []) {
            $admonition['attrs'] = $attrs;
        }

        return $admonition;
    }

    private function hasFollowingTableCell(DOMElement $cell): bool
    {
        for ($sibling = $cell->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
            if (
                $sibling instanceof DOMElement
                && in_array(strtolower($sibling->tagName), ['td', 'th'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    private static function cellHoldsBlocks(DOMElement $cell): bool
    {
        $paragraphs = 0;
        foreach ($cell->getElementsByTagName('*') as $descendant) {
            $tag = strtolower($descendant->tagName);
            if (in_array($tag, ['ul', 'ol', 'pre', 'blockquote', 'table', 'dl'], true)) {
                return true;
            }
            if ($tag === 'p' && ++$paragraphs > 1) {
                return true;
            }
        }

        return false;
    }

    private function tableCellElementAt(DOMElement $table, int $rowIndex, int $column): ?DOMElement
    {
        $rows = $this->directTableRows($table);
        $row = $rows[$rowIndex] ?? null;
        if (!$row instanceof DOMElement) {
            return null;
        }
        $cells = [];
        foreach ($row->childNodes as $cell) {
            if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                $cells[] = $cell;
            }
        }

        return $cells[$column] ?? null;
    }

    /**
     * @return list<\DOMElement>
     */
    private function directTableRows(DOMElement $table): array
    {
        $rows = [];
        foreach ($table->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'tr') {
                $rows[] = $child;

                continue;
            }
            if (!in_array($tag, ['thead', 'tbody', 'tfoot'], true)) {
                continue;
            }
            foreach ($child->childNodes as $row) {
                if ($row instanceof DOMElement && strtolower($row->tagName) === 'tr') {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @param \DOMElement $node
     * @param string $property
     * @param list<string> $allowed
     */
    private function styleEnum(DOMElement $node, string $property, array $allowed): ?string
    {
        $style = $node->getAttribute('style');
        if (preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:\s*([A-Za-z-]+)/i', $style, $match) !== 1) {
            return null;
        }
        $value = strtolower($match[1]);

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * @param \DOMElement $node
     * @param array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>} $own
     * @param array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>} $target
     */
    private function recordDisplacedFigureAttributes(DOMElement $node, array $own, array $target): void
    {
        $names = isset($own['id'], $target['id']) ? ['id'] : [];
        foreach (array_keys($own['keyValues'] ?? []) as $name) {
            if (array_key_exists($name, $target['keyValues'] ?? [])) {
                $names[] = $name;
            }
        }
        $this->displacedFigureAttributes[self::importPath($node)] = $names;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function figure(DOMElement $node): array
    {
        if ($this->hasClass($node, 'carve-figure-group')) {
            return [$this->figureGroup($node)];
        }
        // A denied destination imports as content, so it cannot ride along in raw HTML.
        $keepsRaw = $this->importMode === 'roundtrip' && !self::holdsADeniedDestination($node);
        $caption = [];
        $captionDeclared = false;
        $bodyNodes = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'figcaption') {
                $captionDeclared = trim($child->textContent) !== '' || $child->getElementsByTagName('*')->length > 0;
                $caption = $this->captionInlines($child);

                continue;
            }
            $bodyNodes[] = $child;
        }
        $body = $this->blocks($bodyNodes);
        $target = count($body) === 1 ? $body[0] : null;
        if (
            is_array($target)
            && ($target['type'] ?? null) === 'paragraph'
            && !isset($target['attrs'])
            && count(self::nodeList($target['children'] ?? null)) === 1
            && ((self::nodeList($target['children'] ?? null)[0]['type'] ?? null) === 'image')
        ) {
            $target = self::nodeList($target['children'] ?? null)[0];
        }
        if (is_array($target) && ($target['type'] ?? null) === 'table') {
            $tableHasCaption = ($target['caption'] ?? []) !== [];
            if (!$captionDeclared) {
                return [$target];
            }
            if (!$tableHasCaption && $caption !== []) {
                $target['caption'] = $caption;
            }
            $figureAttrs = $this->attrs($node, []);
            if ($figureAttrs !== []) {
                // One attribute line for the figure and its table, merged the
                // way the parser merges two stacked lines (carve#2370).
                $tableAttrs = self::attrsValue($target['attrs'] ?? null);
                $this->recordDisplacedFigureAttributes($node, $figureAttrs, $tableAttrs);
                $merged = $this->mergeAttrs($figureAttrs, $tableAttrs);
                if (!isset($figureAttrs['order']) && !isset($tableAttrs['order'])) {
                    unset($merged['order']);
                }
                $target['attrs'] = $merged;
            }
            if (!$tableHasCaption || $caption === []) {
                return [$target];
            }
            if (!$keepsRaw) {
                return [
                    $target,
                    ['type' => 'paragraph', 'children' => $caption],
                ];
            }
        }
        if (
            is_array($target)
            && ($target['type'] ?? null) === 'paragraph'
            && !isset($target['attrs'])
            && count(self::nodeList($target['children'] ?? null)) === 1
            && ((self::nodeList($target['children'] ?? null)[0]['type'] ?? null) === 'image')
        ) {
            $target = self::nodeList($target['children'] ?? null)[0];
        }
        if (count($bodyNodes) === 1 && $bodyNodes[0] instanceof DOMElement && strtolower($bodyNodes[0]->tagName) === 'picture') {
            foreach ($bodyNodes[0]->getElementsByTagName('img') as $imageElement) {
                $images = $this->inline($imageElement);
                if (($images[0]['type'] ?? null) === 'image') {
                    $target = $images[0];

                    break;
                }
            }
        }
        if (
            is_array($target)
            && $caption !== []
            && in_array($target['type'] ?? null, ['image', 'block_quote', 'code_block'], true)
        ) {
            // Only an image has an attribute slot of its own under a caption
            // line; any other target shares the figure's line, merged the way
            // the parser merges two stacked lines (carve#2370).
            $targetAttrs = self::attrsValue($target['attrs'] ?? null);
            $shared = $target['type'] !== 'image' && $targetAttrs !== [];
            if ($shared) {
                unset($target['attrs']);
            }
            $figure = ['type' => 'figure', 'target' => $target, 'caption' => $caption];
            $this->attachAttrs($figure, $node);
            $this->removeStructuralClass($figure, 'carve-figure-panel');
            if ($shared) {
                $own = self::attrsValue($figure['attrs'] ?? null);
                $this->recordDisplacedFigureAttributes($node, $own, $targetAttrs);
                $merged = $this->mergeAttrs($own, $targetAttrs);
                if (!isset($own['order']) && !isset($targetAttrs['order'])) {
                    unset($merged['order']);
                }
                $figure['attrs'] = $merged;
            }

            return [$figure];
        }

        if ($keepsRaw && $caption !== [] && !self::aRowRefusesTheRegion($node)) {
            $html = $node->ownerDocument?->saveHTML($node);
            $this->keepRaw($node);

            return [
                [
                    'type' => 'raw_block',
                    'content' => is_string($html) ? rtrim($html, "\n") : '',
                    'format' => 'html',
                ],
            ];
        }

        $fallback = [];
        $segment = [];
        $flush = function () use (&$fallback, &$segment): void {
            foreach ($this->blocks($segment) as $block) {
                $fallback[] = $block;
            }
            $segment = [];
        };
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'figcaption') {
                $flush();
                $previousProjection = $this->inInlineProjection;
                $this->inInlineProjection = true;
                try {
                    foreach ($this->blocks($this->children($child)) as $block) {
                        $fallback[] = $block;
                    }
                } finally {
                    $this->inInlineProjection = $previousProjection;
                }

                continue;
            }
            $segment[] = $child;
        }
        $flush();

        return $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function figureGroup(DOMElement $node): array
    {
        $body = [];
        $caption = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'figcaption') {
                $caption = $this->captionInlines($child);

                continue;
            }
            $body[] = $child;
        }
        $group = ['type' => 'figure_group', 'children' => $this->blocks($body)];
        if ($caption !== []) {
            $group['caption'] = $caption;
        }
        $this->attachAttrs($group, $node);
        $this->removeStructuralClass($group, 'carve-figure-group');

        return $group;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $structural
     */
    private function removeStructuralClass(array &$node, string $structural): void
    {
        if (!is_array($node['attrs'] ?? null)) {
            return;
        }
        $attrs = $node['attrs'];
        $classes = array_values(array_filter(
            is_array($attrs['classes'] ?? null) ? $attrs['classes'] : [],
            static fn (mixed $class): bool => is_string($class) && $class !== $structural,
        ));
        if ($classes === []) {
            unset($attrs['classes']);
            $attrs['order'] = array_values(array_filter(
                is_array($attrs['order'] ?? null) ? $attrs['order'] : [],
                static fn (mixed $slot): bool => is_string($slot) && $slot !== '.class',
            ));
        } else {
            $attrs['classes'] = $classes;
        }
        if (($attrs['order'] ?? []) === []) {
            unset($attrs['order']);
        }
        if ($attrs === []) {
            unset($node['attrs']);
        } else {
            $node['attrs'] = $attrs;
        }
    }

    /**
     * @phpstan-param Attrs $inner
     * @phpstan-param Attrs $outer
     *
     * @param array<string, mixed> $outer
     * @param array<string, mixed> $inner
     *
     * @return Attrs
     */
    private function mergeAttrs(array $outer, array $inner): array
    {
        if ($inner === []) {
            return $outer;
        }
        $merged = $outer;
        if (isset($inner['id'])) {
            $merged['id'] = $inner['id'];
        }
        $merged['classes'] = array_values(array_unique([
            ...($outer['classes'] ?? []),
            ...($inner['classes'] ?? []),
        ]));
        if ($merged['classes'] === []) {
            unset($merged['classes']);
        }
        $merged['keyValues'] = [...($outer['keyValues'] ?? []), ...($inner['keyValues'] ?? [])];
        if ($merged['keyValues'] === []) {
            unset($merged['keyValues']);
        }
        $merged['order'] = array_values(array_unique([
            ...($outer['order'] ?? []),
            ...($inner['order'] ?? []),
        ]));

        return $merged;
    }

    private function taskCheckbox(DOMElement $item): ?DOMElement
    {
        foreach ($item->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->textContent) === '') {
                continue;
            }
            if (
                $child instanceof DOMElement
                && strtolower($child->tagName) === 'input'
                && strtolower($child->getAttribute('type')) === 'checkbox'
            ) {
                return $child;
            }
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'label') {
                foreach ($child->childNodes as $labelChild) {
                    if ($labelChild instanceof DOMText && trim($labelChild->textContent) === '') {
                        continue;
                    }
                    if (
                        $labelChild instanceof DOMElement
                        && strtolower($labelChild->tagName) === 'input'
                        && strtolower($labelChild->getAttribute('type')) === 'checkbox'
                    ) {
                        return $labelChild;
                    }

                    break;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function container(DOMElement $node): array
    {
        $domChildren = $this->children($node);
        $title = [];
        $titleNode = null;
        foreach ($domChildren as $child) {
            if (
                $child instanceof DOMElement
                && strtolower($child->tagName) === 'p'
                && $this->hasClass($child, 'admonition-title')
            ) {
                $title = $this->blockInlines($child);
                $this->stripOpenerTitleQuotes($title);
                $titleNode = $child;

                break;
            }
        }
        if (
            $this->sourceSafe
            && $titleNode instanceof DOMElement
            && (
                trim($titleNode->textContent) === $this->derivedAriaLabel($node)
                || (
                    $node->hasAttribute('data-djot-admonition-type')
                    && trim($titleNode->textContent) === ucfirst($node->getAttribute('data-djot-admonition-type'))
                )
            )
        ) {
            $title = [];
        }
        $bareTransport = $this->isBareDjotContentWrapper($node);
        $label = null;
        $labelNode = null;
        foreach ($domChildren as $index => $child) {
            if ($child instanceof DOMText && trim($child->textContent) === '') {
                continue;
            }
            if ($child === $titleNode) {
                continue;
            }
            if (
                !$bareTransport
                &&
                $child instanceof DOMElement
                && strtolower($child->tagName) === 'p'
                && (preg_split('/\s+/', trim($child->getAttribute('class'))) ?: []) === ['div-label']
                && $child->attributes->length === 1
                && self::every([...$child->childNodes], static fn (DOMNode $part): bool => $part instanceof DOMText)
                && !str_contains($child->textContent, ']')
                && !str_contains($child->textContent, "\n")
            ) {
                $label = $child->textContent;
                $labelNode = $child;
            }

            break;
        }
        $domChildren = array_values(array_filter(
            $domChildren,
            static fn (DOMNode $child): bool => $child !== $titleNode && $child !== $labelNode,
        ));
        $children = $this->blocks($domChildren);
        if ($bareTransport) {
            return $this->blocks($this->children($node));
        }
        $skipAttrs = [];
        if ($this->derivedAriaLabel($node) === $node->getAttribute('aria-label')) {
            $skipAttrs[] = 'aria-label';
        }
        if (
            $titleNode instanceof DOMElement
            && $titleNode->getAttribute('id') !== ''
            && $node->getAttribute('aria-labelledby') === $titleNode->getAttribute('id')
        ) {
            $skipAttrs[] = 'aria-labelledby';
        }
        $attrs = $this->attrs($node, $skipAttrs);
        $classes = $attrs['classes'] ?? [];
        $structuralKind = trim($node->getAttribute('data-djot-admonition-type'));
        $tag = strtolower($node->tagName);
        $kindIndex = $tag === 'aside' && ($classes[0] ?? null) === 'admonition' && isset($classes[1]) ? 1 : 0;
        $lineBlockIndex = array_search('line-block', $classes, true);
        if ($lineBlockIndex !== false) {
            $kindIndex = $lineBlockIndex;
        }
        $classKind = $classes[$kindIndex] ?? '';
        $classKindIsSpellable = $classKind === 'line-block'
            || preg_match('/^[A-Za-z_][\w-]*$/D', $classKind) === 1;
        if ($structuralKind !== '' || $classKindIsSpellable) {
            $kind = $structuralKind !== '' ? $structuralKind : ($classKind === 'line-block' ? '|' : $classKind);
            $container = $kind === '|'
                ? ['type' => 'line_block', 'children' => $children]
                : $this->namedContainer($kind, $children);
            if ($classKind !== '') {
                unset($classes[$kindIndex]);
            }
            if ($tag === 'aside' && ($classes[0] ?? null) === 'admonition') {
                unset($classes[0]);
            }
            $classes = array_values($classes);
            if ($classes === []) {
                unset($attrs['classes']);
            } else {
                $attrs['classes'] = $classes;
            }
            if ($classes === []) {
                $attrs['order'] = array_values(array_filter(
                    $attrs['order'] ?? [],
                    static fn (string $slot): bool => $slot !== '.class',
                ));
            }
            if (($attrs['order'] ?? []) === []) {
                unset($attrs['order']);
            }
            if ($attrs !== []) {
                $container['attrs'] = $attrs;
            }
            // A directive is closed WITHOUT a `title`, so the quoted opener has
            // nowhere to go - the same loss the codec exit takes on
            // `::: toc "Contents"`, and the two exits have to agree.
            // markup-carve/carve#2247 asks where it should live.
            if ($title !== [] && ($container['type'] ?? null) !== 'directive') {
                $container['title'] = $title;
            }
            if ($label !== null) {
                $container['label'] = $label;
            }

            return [$container];
        }
        if ($attrs === [] && $label === null) {
            return $children;
        }
        $container = ['type' => 'div', 'children' => $children, 'attrs' => $attrs];
        if ($attrs === []) {
            unset($container['attrs']);
        }
        if ($label !== null) {
            $container['label'] = $label;
        }

        return [$container];
    }

    /**
     * A named `:::` container as one of the two types its kind selects
     * (CARVE-P12-057).
     *
     * A kind naming GENERATED CONTENT is a `directive`, every other named kind an
     * `admonition`. The list of six is CLOSED, so `endnotes` is an admonition.
     * A directive publishes `children` even when its body is empty.
     *
     * @param string $kind
     * @param list<array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private function namedContainer(string $kind, array $children): array
    {
        if (!in_array($kind, Div::GENERATED_CONTENT_KINDS, true)) {
            return ['type' => 'admonition', 'kind' => $kind, 'children' => $children];
        }

        return ['type' => 'directive', 'kind' => $kind, 'children' => $children];
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function stripOpenerTitleQuotes(array &$nodes): void
    {
        foreach ($nodes as &$node) {
            if (($node['type'] ?? null) === 'text') {
                $node['value'] = str_replace('"', '', self::stringValue($node['value'] ?? null));
            }
            if (is_array($node['children'] ?? null)) {
                $children = self::nodeList($node['children']);
                $this->stripOpenerTitleQuotes($children);
                $node['children'] = $children;
            }
        }
        unset($node);
    }

    private function isBareDjotContentWrapper(DOMElement $node): bool
    {
        if ((preg_split('/\s+/', trim($node->getAttribute('class'))) ?: []) !== ['djot-content']) {
            return false;
        }
        if ($node->hasAttribute('id') && $node->getAttribute('id') !== '') {
            return false;
        }
        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            if ($name !== 'class' && $name !== 'style' && !str_starts_with($name, 'on')) {
                return false;
            }
        }

        return true;
    }

    public static function isDerivedRole(DOMElement $node, string $value): bool
    {
        $tag = strtolower($node->tagName);
        $role = strtolower(trim($value));
        $classes = preg_split('/\s+/', trim($node->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (
            $tag === 'pre'
            && $role === 'img'
            && $classes !== []
            && (trim($node->getAttribute('aria-label')) !== '' || trim($node->getAttribute('aria-labelledby')) !== '')
        ) {
            return true;
        }
        if ($tag !== 'div') {
            return false;
        }
        if (array_intersect($classes, ['tabs', 'code-group']) !== []) {
            return in_array($role, ['group', 'tablist'], true);
        }
        if (array_intersect($classes, ['tabs-panel', 'code-group-panel']) !== []) {
            return in_array($role, ['group', 'tabpanel'], true);
        }
        $kind = trim($node->getAttribute('data-djot-admonition-type'));
        if ($kind === '' && ($classes[0] ?? null) === 'admonition') {
            $kind = $classes[1] ?? '';
        }

        return $kind !== '' && $role === (in_array($kind, ['warning', 'danger'], true) ? 'alert' : 'note');
    }

    private function derivedAriaLabel(DOMElement $node): ?string
    {
        $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
        $defaults = [
            'note' => 'Note',
            'tip' => 'Tip',
            'important' => 'Important',
            'warning' => 'Warning',
            'caution' => 'Caution',
        ];
        if (strtolower($node->tagName) === 'aside' && in_array('admonition', $classes, true)) {
            foreach ($classes as $class) {
                $key = 'admonition' . ucfirst($class);
                if (isset($this->labels[$key])) {
                    return (string)$this->labels[$key];
                }
                if (isset($defaults[$class])) {
                    return $defaults[$class];
                }
            }
        }
        if (in_array('tabs', $classes, true)) {
            return (string)($this->labels['tabsGroup'] ?? 'Tabs');
        }
        if (in_array('code-group', $classes, true)) {
            return (string)($this->labels['codeGroup'] ?? 'Code examples');
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blockInlines(DOMNode $node): array
    {
        $inlines = $this->inlines($this->children($node));
        $this->normalizeInlineBoundaries($inlines);

        return $this->trimBlockEdges($inlines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function captionInlines(DOMNode $node): array
    {
        $out = [];
        $previousWasBlock = false;
        $previousCaptionState = $this->inCaption;
        $this->inCaption = true;
        try {
            foreach ($node->childNodes as $child) {
                $current = $this->inline($child);
                $currentIsBlock = $this->isBlock($child);
                if (
                    $out !== []
                    && $current !== []
                    && $previousWasBlock
                    && $currentIsBlock
                    && !$this->captionBoundaryHasSpace($out[array_key_last($out)], false)
                    && !$this->captionBoundaryHasSpace($current[0], true)
                ) {
                    $out[] = ['type' => 'text', 'value' => ' '];
                }
                foreach ($current as $inline) {
                    $out[] = $inline;
                }
                $previousWasBlock = $currentIsBlock;
            }
        } finally {
            $this->inCaption = $previousCaptionState;
        }
        $out = $this->hoistedRun($out);
        $this->normalizeInlineBoundaries($out);

        return $this->trimBlockEdges($out);
    }

    /**
     * @param array<string, mixed> $node
     * @param bool $atStart
     */
    private function captionBoundaryHasSpace(array $node, bool $atStart): bool
    {
        if (in_array($node['type'] ?? null, ['text', 'code'], true)) {
            $pattern = $atStart ? '/^[\s\x{00A0}]/u' : '/[\s\x{00A0}]$/u';

            return preg_match($pattern, self::stringValue($node['value'] ?? null)) === 1;
        }
        $children = $node['children'] ?? null;
        if (!is_array($children) || $children === []) {
            return false;
        }
        $children = self::nodeList($children);
        if ($children === []) {
            return false;
        }
        $key = $atStart ? array_key_first($children) : array_key_last($children);

        return $this->captionBoundaryHasSpace($children[$key], $atStart);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function normalizeInlineBoundaries(array &$nodes): void
    {
        if ($nodes === []) {
            return;
        }
        foreach ($nodes as &$node) {
            $this->normalizeHardBreakPadding($node);
        }
        unset($node);
        $this->trimInlineLeading($nodes[0]);
        $last = count($nodes) - 1;
        $this->trimInlineTrailing($nodes[$last]);
        for ($index = 0, $count = count($nodes) - 1; $index < $count; ++$index) {
            $leftType = $nodes[$index]['type'] ?? null;
            $rightType = $nodes[$index + 1]['type'] ?? null;
            if ($rightType === 'hard_break') {
                if ($leftType !== 'text') {
                    $this->trimInlineTrailing($nodes[$index]);
                }

                continue;
            }
            if ($rightType === 'code' && !in_array($leftType, ['text', 'span'], true)) {
                $this->trimInlineTrailing($nodes[$index]);

                continue;
            }
            if ($leftType === 'hard_break') {
                $this->trimInlineLeading($nodes[$index + 1]);

                continue;
            }
            if (!$this->inlineEndsWithSpace($nodes[$index]) || !$this->inlineStartsWithSpace($nodes[$index + 1])) {
                continue;
            }
            if ($leftType === 'span' || $rightType === 'span' || $rightType === 'code') {
                continue;
            }
            if ($rightType === 'text') {
                $this->trimInlineTrailing($nodes[$index]);
            } elseif ($leftType !== 'text') {
                $this->trimInlineLeading($nodes[$index + 1]);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function normalizeHardBreakPadding(array &$node): void
    {
        if (!is_array($node['children'] ?? null)) {
            return;
        }
        $children = self::nodeList($node['children']);
        foreach ($children as &$child) {
            $this->normalizeHardBreakPadding($child);
        }
        unset($child);
        for ($index = 0, $count = count($children); $index < $count; ++$index) {
            if (($children[$index]['type'] ?? null) !== 'hard_break') {
                continue;
            }
            if ($index > 0) {
                $this->trimInlineTrailing($children[$index - 1]);
            }
            if ($index + 1 < $count) {
                $this->trimInlineLeading($children[$index + 1]);
            }
        }
        $node['children'] = $children;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function inlineStartsWithSpace(array $node): bool
    {
        if (($node['type'] ?? null) === 'text') {
            return preg_match('/^[ \t]/', self::stringValue($node['value'] ?? null)) === 1;
        }
        if (($node['type'] ?? null) === 'code') {
            return preg_match('/^[ \t]/', self::stringValue($node['value'] ?? null)) === 1;
        }
        $children = self::nodeList($node['children'] ?? null);

        return $children !== [] && $this->inlineStartsWithSpace($children[0]);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function inlineEndsWithSpace(array $node): bool
    {
        if (($node['type'] ?? null) === 'text') {
            return preg_match('/[ \t]$/', self::stringValue($node['value'] ?? null)) === 1;
        }
        if (($node['type'] ?? null) === 'code') {
            return preg_match('/[ \t]$/', self::stringValue($node['value'] ?? null)) === 1;
        }
        $children = self::nodeList($node['children'] ?? null);
        $last = array_key_last($children);

        return $last !== null && $this->inlineEndsWithSpace($children[$last]);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function trimInlineLeading(array &$node): void
    {
        if (($node['type'] ?? null) === 'text') {
            $node['value'] = preg_replace('/^[ \t]+/', '', self::stringValue($node['value'] ?? null)) ?? $node['value'];

            return;
        }
        if (($node['type'] ?? null) === 'span' && self::attrsValue($node['attrs'] ?? null) !== []) {
            return;
        }
        $plain = $this->plainInlineText([$node]);
        if ($plain !== '' && trim($plain) === '') {
            return;
        }
        $children = self::nodeList($node['children'] ?? null);
        if (isset($children[0])) {
            $this->trimInlineLeading($children[0]);
            $node['children'] = $children;
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function trimInlineTrailing(array &$node): void
    {
        if (($node['type'] ?? null) === 'text') {
            $node['value'] = preg_replace('/[ \t]+$/', '', self::stringValue($node['value'] ?? null)) ?? $node['value'];

            return;
        }
        if (($node['type'] ?? null) === 'span' && self::attrsValue($node['attrs'] ?? null) !== []) {
            return;
        }
        $plain = $this->plainInlineText([$node]);
        if ($plain !== '' && trim($plain) === '') {
            return;
        }
        $children = self::nodeList($node['children'] ?? null);
        $last = array_key_last($children);
        if ($last !== null) {
            $this->trimInlineTrailing($children[$last]);
            $node['children'] = $children;
        }
    }

    /**
     * @param list<\DOMNode> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function inlines(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            foreach ($this->inline($node) as $inline) {
                $out[] = $inline;
            }
        }

        foreach ($out as $index => $inline) {
            if (($inline['type'] ?? null) !== 'code' || ($inline['value'] ?? null) !== '') {
                continue;
            }
            $tail = array_slice($out, $index + 1);
            if (
                $tail !== [] && self::every($tail, static fn (array $part): bool => ($part['type'] ?? null) === 'text' && trim(self::stringValue($part['value'] ?? null)) === '')
            ) {
                $out = array_slice($out, 0, $index + 1);

                break;
            }
        }

        return $this->hoistedRun($out);
    }

    /**
     * Close an inline run: hoist the edge whitespace of every link and span in
     * it, then merge the neighboring text.
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function hoistedRun(array $nodes): array
    {
        // A trusted round trip reads its own Carve source back, spaces included.
        $keep = $this->preserveInlineWhitespace || $this->trustedRoundTrip;

        return $this->coalesceText($keep ? $nodes : $this->hoistEdgeSpace($nodes));
    }

    /**
     * A link's or span's edge whitespace stands outside it, as one space that
     * merges with whitespace already there (markup-carve/carve#2361).
     * Whitespace-only content stays, and so does U+00A0, which is content.
     *
     * @param list<array<string, mixed>> $nodes
     * @param bool $withinLinkOrSpan
     *
     * @return list<array<string, mixed>>
     */
    private function hoistEdgeSpace(array $nodes, bool $withinLinkOrSpan = false): array
    {
        $startsBlank = static fn (?array $node): bool => ($node['type'] ?? null) === 'text'
            && preg_match('/^[ \t]/', self::stringValue($node['value'] ?? null)) === 1;
        $endsBlank = static fn (?array $node): bool => ($node['type'] ?? null) === 'text'
            && preg_match('/[ \t]$/', self::stringValue($node['value'] ?? null)) === 1;
        // Through nested inlines: a strong ending in a space already has one there.
        $deepStartsBlank = static function (?array $node) use (&$deepStartsBlank, $startsBlank): bool {
            if ($startsBlank($node)) {
                return true;
            }
            $children = self::nodeList($node['children'] ?? null);

            return $children !== [] && $deepStartsBlank($children[0]);
        };
        $deepEndsBlank = static function (?array $node) use (&$deepEndsBlank, $endsBlank): bool {
            if ($endsBlank($node)) {
                return true;
            }
            $children = self::nodeList($node['children'] ?? null);
            $last = array_key_last($children);

            return $last !== null && $deepEndsBlank($children[$last]);
        };
        $out = [];
        $owed = false;
        foreach ($nodes as $node) {
            $lead = false;
            $trail = false;
            $children = self::nodeList($node['children'] ?? null);
            $linkOrSpan = !$withinLinkOrSpan && in_array($node['type'] ?? null, ['link', 'span'], true);
            $formatting = $withinLinkOrSpan && in_array($node['type'] ?? null, [
                'emphasis', 'strong', 'underline', 'strike', 'highlight',
                'insert', 'delete', 'superscript', 'subscript',
            ], true);
            if ($linkOrSpan || $formatting) {
                $children = $this->coalesceText($this->hoistEdgeSpace($children, true));
                $node['children'] = $children;
            }
            $blankOnly = self::every($children, static fn (array $child): bool => ($child['type'] ?? null) === 'text'
                && preg_match('/^[ \t]*$/D', self::stringValue($child['value'] ?? null)) === 1);
            if (($linkOrSpan || $formatting) && !$blankOnly) {
                if ($startsBlank($children[0])) {
                    $lead = true;
                    $children[0]['value'] = preg_replace('/^[ \t]+/', '', self::stringValue($children[0]['value'])) ?? '';
                    if ($children[0]['value'] === '') {
                        array_shift($children);
                    }
                }
                $last = array_key_last($children);
                if ($last !== null && $endsBlank($children[$last])) {
                    $trail = true;
                    $children[$last]['value'] = preg_replace('/[ \t]+$/', '', self::stringValue($children[$last]['value'])) ?? '';
                    if ($children[$last]['value'] === '') {
                        array_pop($children);
                    }
                }
                if ($lead || $trail) {
                    $node['children'] = $children;
                    if (isset($node['rawRef'], $node['ref'])) {
                        $label = $this->plainInlineText($children);
                        $ref = self::stringValue($node['ref']);
                        $node['rawRef'] = '[' . $label . ']' . ($label === $ref ? '[]' : '[' . $ref . ']');
                    }
                }
            }
            $previous = $out === [] ? null : $out[array_key_last($out)];
            if (($owed || $lead) && !$deepEndsBlank($previous) && !($owed && !$lead && $deepStartsBlank($node))) {
                $out[] = ['type' => 'text', 'value' => ' '];
            }
            $out[] = $node;
            $owed = $trail;
        }
        if ($owed) {
            $out[] = ['type' => 'text', 'value' => ' '];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inline(DOMNode $node): array
    {
        if ($node instanceof DOMText) {
            $value = $this->preserveInlineWhitespace
                ? $node->textContent
                : (preg_replace('/[ \t\n\f\r]+/', ' ', $node->textContent) ?? $node->textContent);

            return $value === '' ? [] : [['type' => 'text', 'value' => $value]];
        }
        if ($node instanceof DOMComment) {
            if (
                str_contains($node->textContent, '%}')
                || preg_match('/\R\s*\R/u', $node->textContent) === 1
                || self::commentBreaksACellRow($node, $this->listTableForBlockCells)
                || self::commentBreaksAHeadingLine($node)
            ) {
                return [];
            }

            return [
                [
                    'type' => 'comment',
                    'content' => $node->textContent,
                    'delimited' => true,
                    'block' => false,
                ],
            ];
        }
        if (!$node instanceof DOMElement) {
            return $this->inlines($this->children($node));
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'template', 'noscript'], true)) {
            return [];
        }
        if ($tag === 'img' && self::isDroppedFormulaImage($node)) {
            return [];
        }
        if ($this->importMode === 'roundtrip' && $node->hasAttribute('data-djot-escaped')) {
            return array_map(
                static fn (string $char): array => ['type' => 'escaped_text', 'value' => $char],
                preg_split('//u', $node->textContent, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            );
        }
        if ($this->importMode === 'roundtrip' && $node->hasAttribute('data-djot-raw')) {
            $content = '';
            foreach ($node->childNodes as $child) {
                $content .= $node->ownerDocument?->saveHTML($child) ?? '';
            }

            return [
                [
                    'type' => 'raw_inline',
                    'content' => $content,
                    'format' => $node->getAttribute('data-djot-raw'),
                ],
            ];
        }
        if (in_array($tag, ['th', 'td', 'dt', 'dd'], true)) {
            return $this->inlines($this->children($node));
        }
        if ($tag === 'figcaption' && self::isOrphanFigcaption($node)) {
            return $this->inlines($this->children($node));
        }
        if ($tag === 'caption' || $tag === 'figcaption') {
            return [];
        }
        if ($tag === 'br') {
            return [['type' => 'hard_break']];
        }
        if ($tag === 'math') {
            $content = $this->mathTex($node);
            if ($content === null) {
                if ($this->importMode !== 'roundtrip') {
                    $text = self::linearMathText($node);

                    return $text === null ? [] : [['type' => 'text', 'value' => $text]];
                }
                $html = $node->ownerDocument?->saveHTML($node);
                $this->keepRaw($node);

                return [
                    [
                        'type' => 'raw_inline',
                        'content' => is_string($html) ? rtrim($html, "\n") : '',
                        'format' => 'html',
                    ],
                ];
            }

            $math = [
                'type' => 'math',
                'display' => strtolower($node->getAttribute('display')) === 'block',
                'content' => $content,
            ];
            // `display` and `alttext` are read as the node; `xmlns` is the element's namespace.
            $this->attachAttrs($math, $node, ['xmlns', 'display', 'alttext']);

            return [$math];
        }
        if ($tag === 'span') {
            $token = trim($node->textContent);
            if (
                $this->hasClass($node, 'mention')
                && preg_match('/^@([A-Za-z0-9_][A-Za-z0-9_-]*)$/D', $token, $match) === 1
            ) {
                return [['type' => 'mention', 'user' => $match[1]]];
            }
            if (
                $this->hasClass($node, 'tag')
                && preg_match('/^#([A-Za-z0-9_][A-Za-z0-9_-]*)$/D', $token, $match) === 1
            ) {
                return [['type' => 'tag', 'name' => $match[1]]];
            }
            $math = $this->delimitedMath($node);
            if ($math !== null) {
                return [$math];
            }
        }
        if ($tag === 'ruby') {
            $hasAnnotation = false;
            foreach ($node->childNodes as $component) {
                if ($component instanceof DOMElement && in_array(strtolower($component->tagName), ['rt', 'rtc'], true)) {
                    $hasAnnotation = true;

                    break;
                }
            }
            if ($hasAnnotation || $this->importMode !== 'roundtrip') {
                return $this->ruby($node);
            }
        }
        if (
            $this->importMode === 'roundtrip'
            && !$this->inCaption
            && !$this->isSupportedInlineTag($tag)
            && !self::aRowRefusesTheRegion($node)
        ) {
            $html = $node->ownerDocument?->saveHTML($node);
            $this->keepRaw($node);

            return [
                [
                    'type' => 'raw_inline',
                    'content' => is_string($html) ? rtrim($html, "\n") : '',
                    'format' => 'html',
                ],
            ];
        }
        if ($tag === 'img') {
            if (self::carriesNoDestination($node->getAttribute('src'))) {
                $alt = $node->getAttribute('alt');
                if ($alt !== '' && $node->hasAttribute('title')) {
                    return [
                        [
                            'type' => 'span',
                            'children' => [['type' => 'text', 'value' => $alt]],
                            'attrs' => [
                                'keyValues' => ['title' => $node->getAttribute('title')],
                                'order' => ['title'],
                            ],
                        ],
                    ];
                }

                return $alt === '' ? [] : [['type' => 'text', 'value' => $alt]];
            }
            if (
                $this->importMode === 'roundtrip'
                && (str_contains($node->getAttribute('alt'), '[') || str_contains($node->getAttribute('alt'), '\\'))
            ) {
                $html = $this->sanitizedElementHtml($node);
                $serialized = $node->ownerDocument?->saveHTML($node);
                if (is_string($serialized) && $html === rtrim($serialized, "\n")) {
                    $this->keepRaw($node);
                }

                return [
                    [
                        'type' => 'raw_inline',
                        'content' => $html,
                        'format' => 'html',
                    ],
                ];
            }
            $image = [
                'type' => 'image',
                'src' => $node->getAttribute('src'),
                'alt' => $node->getAttribute('alt'),
            ];
            if ($node->hasAttribute('title')) {
                $image['title'] = $node->getAttribute('title');
            }
            if ($this->sourceSafe && $node->hasAttribute('data-djot-ref')) {
                $ref = $node->getAttribute('data-djot-ref');
                $image['ref'] = $ref;
                $image['rawRef'] = '![' . $node->getAttribute('alt') . ']'
                    . ($ref === $node->getAttribute('alt') ? '[]' : '[' . $ref . ']');
                $this->referenceDefinitions[$ref] = $node->getAttribute('src');
            }
            $this->attachAttrs($image, $node, ['src', 'alt', 'title', 'data-djot-ref']);

            return [$image];
        }
        if ($tag === 'a') {
            // Raw HTML would write a denied destination the report says was dropped.
            if ($this->importMode === 'roundtrip' && !self::holdsADeniedDestination($node)) {
                foreach ($node->getElementsByTagName('img') as $image) {
                    if (preg_match('/[\\[\\]\\\\]/', $image->getAttribute('alt')) === 1) {
                        $html = $node->ownerDocument?->saveHTML($node);
                        $this->keepRaw($node);

                        return [
                            [
                                'type' => 'raw_inline',
                                'content' => is_string($html) ? rtrim($html, "\n") : '',
                                'format' => 'html',
                            ],
                        ];
                    }
                }
            }
            $children = $this->inlines($this->children($node));
            if (self::carriesNoDestination($node->getAttribute('href'))) {
                $skip = ['href'];
                foreach ($node->attributes as $attribute) {
                    if (str_starts_with(strtolower($attribute->nodeName), 'data-djot-')) {
                        $skip[] = strtolower($attribute->nodeName);
                    }
                }
                $attrs = $this->attrs($node, $skip);
                if ($attrs !== []) {
                    return [['type' => 'span', 'children' => $children, 'attrs' => $attrs]];
                }

                return $children;
            }
            if ($node->hasAttribute('data-djot-inline-footnote-html')) {
                $content = $this->inlineHtml($node->getAttribute('data-djot-inline-footnote-html'));
                $class = $node->getAttribute('data-djot-inline-footnote-class');

                return [
                    [
                        'type' => 'span',
                        'children' => $content,
                        'attrs' => [
                            'classes' => [$class === '' ? 'fn' : $class],
                            'order' => ['.class'],
                        ],
                    ],
                ];
            }
            if ($node->hasAttribute('data-djot-footnote-label')) {
                return [
                    [
                        'type' => 'footnote_ref',
                        'label' => $node->getAttribute('data-djot-footnote-label'),
                    ],
                ];
            }
            if (strtolower($node->getAttribute('role')) === 'doc-noteref') {
                $href = $node->getAttribute('href');
                $fragment = str_starts_with($href, '#') ? substr($href, 1) : trim($node->textContent);

                return [['type' => 'footnote_ref', 'label' => $this->footnoteLabel($fragment)]];
            }
            if ($this->inFootnoteDefinition && str_starts_with($node->getAttribute('href'), '#fnref')) {
                return [];
            }
            if ($node->hasAttribute('data-djot-autolink')) {
                $autolink = [
                    'type' => 'autolink',
                    'href' => $node->getAttribute('href'),
                    'text' => trim($node->textContent),
                ];
                $this->attachAttrs($autolink, $node, ['href', 'data-djot-autolink']);

                return [$autolink];
            }
            $link = ['type' => 'link', 'href' => $node->getAttribute('href'), 'children' => $children];
            if ($node->hasAttribute('data-djot-ref')) {
                $labelText = $this->plainInlineText($children);
                $ref = $node->getAttribute('data-djot-ref');
                if ($ref === '') {
                    $ref = trim($labelText);
                }
                if ($ref !== '' && !str_contains($labelText, ']') && !str_contains($ref, ']')) {
                    $link['ref'] = $ref;
                    $collapsed = $labelText === $ref;
                    $link['rawRef'] = '[' . $labelText . ']'
                        . ($collapsed ? '[]' : '[' . $ref . ']');
                    $this->referenceDefinitions[$ref] = $node->getAttribute('href');
                }
            }
            if ($node->hasAttribute('title')) {
                $link['title'] = $node->getAttribute('title');
            }
            $skip = ['href', 'title', 'data-djot-ref', 'data-djot-autolink', 'data-djot-footnote-label'];
            if ($this->hasClass($node, 'index-backref')) {
                $skip[] = 'aria-label';
            }
            $this->attachAttrs($link, $node, $skip);

            return [$link];
        }
        if ($tag === 'code') {
            if ($node->textContent === '') {
                if (!$this->emptyCodeRunEndsAt($node)) {
                    return [];
                }

                return [['type' => 'code', 'value' => '']];
            }
            $code = ['type' => 'code', 'value' => $node->textContent];
            $this->attachAttrs($code, $node);

            return [$code];
        }
        if ($tag === 'q') {
            $opening = $this->quoteDepth % 2 === 0 ? '“' : '‘';
            $closing = $this->quoteDepth % 2 === 0 ? '”' : '’';
            ++$this->quoteDepth;
            try {
                $quoted = $this->inlines($this->children($node));
            } finally {
                --$this->quoteDepth;
            }
            $children = [
                ['type' => 'text', 'value' => $opening],
                ...$quoted,
                ['type' => 'text', 'value' => $closing],
            ];

            if ($node->hasAttribute('cite')) {
                return [
                    [
                        'type' => 'span',
                        'children' => $children,
                        'attrs' => [
                            'keyValues' => ['cite' => $node->getAttribute('cite')],
                            'order' => ['cite'],
                        ],
                    ],
                ];
            }

            return $children;
        }
        if ($tag === 'abbr' && $node->hasAttribute('title')) {
            $abbr = trim($node->textContent);
            $expansion = $node->getAttribute('title');
            if ($abbr !== '' && ($this->abbreviationDefinitions[$abbr] ?? null) === $expansion) {
                return [['type' => 'text', 'value' => $abbr]];
            }
        }

        $types = [
            'em' => 'emphasis',
            'i' => 'emphasis',
            'strong' => 'strong',
            'b' => 'strong',
            'u' => 'underline',
            's' => 'strike',
            'strike' => 'strike',
            'mark' => 'highlight',
            'ins' => 'insert',
            'del' => 'delete',
            'sup' => 'superscript',
            'sub' => 'subscript',
        ];
        if (isset($types[$tag])) {
            $type = $types[$tag];
            $lastType = $this->inlineTypeStack === []
                ? null
                : $this->inlineTypeStack[count($this->inlineTypeStack) - 1];
            $nestedSameKind = $lastType === $type;
            $this->inlineTypeStack[] = $type;
            try {
                $children = $this->inlines($this->children($node));
            } finally {
                array_pop($this->inlineTypeStack);
            }
            if ($this->sourceSafe && $nestedSameKind) {
                return $children;
            }
            $span = ['type' => $type];
            $attrs = $this->attrs($node, []);
            if ($attrs !== []) {
                $span['attrs'] = $attrs;
            }

            $span['children'] = $children;

            // An element the HTML left empty is dropped without a row (ruling
            // markup-carve/carve-rs#1719): an empty brace pair has no spelling.
            // Its attributes can still matter (an `id` is a link target), and an
            // empty span is spellable, so they move onto one.
            if ($children === []) {
                return $attrs === [] ? [] : [['type' => 'span', 'attrs' => $attrs, 'children' => []]];
            }

            return [$span];
        }

        if ($tag === 'span' || in_array($tag, ['abbr', 'time', 'samp', 'var', 'kbd', 'cite', 'dfn'], true)) {
            $span = ['type' => 'span', 'children' => $this->inlines($this->children($node))];
            $skip = [];
            $attrs = $this->attrs($node, $skip);
            if ($tag !== 'span') {
                $source = match ($tag) {
                    'abbr', 'dfn' => 'title',
                    'time' => 'datetime',
                    default => null,
                };
                $attrs['keyValues'][$tag] = $source !== null && $node->hasAttribute($source)
                    ? $node->getAttribute($source)
                    : '';
                if ($source !== null) {
                    $skip[] = $source;
                    $attrs = $this->attrs($node, $skip);
                    $attrs['keyValues'][$tag] = $node->hasAttribute($source) ? $node->getAttribute($source) : '';
                }
            }
            if ($attrs !== []) {
                $span['attrs'] = $attrs;
            }

            if ($tag === 'span' && $attrs === []) {
                if ($span['children'] === []) {
                    $this->droppedEmptyElements[$node] = null;
                }

                return $span['children'];
            }

            return [$span];
        }

        if ($tag === 'input' && strtolower($node->getAttribute('type')) === 'checkbox') {
            $bracket = $this->orderedTaskBrackets[spl_object_id($node)] ?? null;

            return $bracket === null ? [] : [['type' => 'text', 'value' => $bracket]];
        }

        if ($this->isBlock($node)) {
            $previousProjection = $this->inInlineProjection;
            $this->inInlineProjection = true;
            try {
                return $this->flattenBlocks($this->block($node));
            } finally {
                $this->inInlineProjection = $previousProjection;
            }
        }

        return $this->inlines($this->children($node));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ruby(DOMElement $element): array
    {
        $input = [];
        $rtc = [];
        foreach ($this->children($element) as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'rb') {
                $input[] = ['rb' => $child];
            } elseif ($child instanceof DOMElement && strtolower($child->tagName) === 'rtc') {
                $content = [];
                foreach ($this->children($child) as $component) {
                    if ($component instanceof DOMElement && strtolower($component->tagName) === 'rp') {
                        continue;
                    }
                    if ($component instanceof DOMElement && strtolower($component->tagName) === 'rt') {
                        array_push($content, ...$this->inlines($this->children($component)));
                    } else {
                        array_push($content, ...$this->inline($component));
                    }
                }
                $rtc[] = $content;
            } else {
                $input[] = $child;
            }
        }

        $output = [];
        $run = [];
        $base = [];
        $explicitBases = [];
        $associated = false;
        foreach ($input as $index => $child) {
            if (is_array($child)) {
                $explicitBases[] = $this->inlines($this->children($child['rb']));

                continue;
            }
            if ($child instanceof DOMComment) {
                continue;
            }
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'rp') {
                continue;
            }
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'rt') {
                $annotation = $this->inlines($this->children($child));
                if ($base === [] && isset($explicitBases[0])) {
                    $base = array_shift($explicitBases);
                    $associated = false;
                }
                if ($associated || $base === []) {
                    self::flushRubyRun($run, $output);
                    $output[] = ['type' => 'text', 'value' => '('];
                    array_push($output, ...$annotation);
                    $output[] = ['type' => 'text', 'value' => ')'];
                } else {
                    $run[] = ['base' => $base, 'annotation' => $annotation];
                    $base = [];
                    $associated = true;
                }

                continue;
            }
            if ($associated && $child instanceof DOMText && trim($child->textContent) === '') {
                $next = $index + 1;
                while (isset($input[$next]) && $input[$next] instanceof DOMText && trim($input[$next]->textContent) === '') {
                    $next++;
                }
                if (!isset($input[$next]) || ($input[$next] instanceof DOMElement && in_array(strtolower($input[$next]->tagName), ['rt', 'rp'], true))) {
                    continue;
                }
            }
            $associated = false;
            array_push($base, ...$this->inline($child));
        }
        if ($base !== []) {
            self::flushRubyRun($run, $output);
            array_push($output, ...$base);
        }
        foreach ($explicitBases as $unpaired) {
            self::flushRubyRun($run, $output);
            array_push($output, ...$unpaired);
        }
        self::flushRubyRun($run, $output);
        foreach ($rtc as $content) {
            $output[] = ['type' => 'text', 'value' => '('];
            array_push($output, ...$content);
            $output[] = ['type' => 'text', 'value' => ')'];
        }

        if ($output === []) {
            return [];
        }
        if (count($output) === 1 && ($output[0]['type'] ?? null) === 'ruby') {
            $this->attachAttrs($output[0], $element);

            return $output;
        }
        $span = ['type' => 'span', 'children' => $output];
        $attrs = $this->attrs($element, []);
        if ($attrs === []) {
            return $output;
        }
        $span['attrs'] = $attrs;

        return [$span];
    }

    /**
     * @param list<array<string, mixed>> $run
     * @param list<array<string, mixed>> $output
     */
    private static function flushRubyRun(array &$run, array &$output): void
    {
        if ($run === []) {
            return;
        }
        $output[] = ['type' => 'ruby', 'pairs' => $run];
        $run = [];
    }

    private function sanitizedElementHtml(DOMElement $node): string
    {
        $clone = $node->cloneNode(true);
        if (!$clone instanceof DOMElement) {
            return '';
        }
        $remove = [];
        foreach ($clone->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (
                str_starts_with($name, 'data-djot-')
                || str_starts_with($name, 'on')
            ) {
                $remove[] = $attribute->nodeName;
            }
        }
        foreach ($remove as $name) {
            $clone->removeAttribute($name);
        }
        $html = $node->ownerDocument?->saveHTML($clone);

        return is_string($html) ? rtrim($html, "\n") : '';
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function roundTripBlocks(DOMElement $node): ?array
    {
        if (
            !$this->trustedRoundTrip
            || $this->importMode !== 'roundtrip'
            || $this->inCaption
            || !$node->hasAttribute('data-djot-src')
        ) {
            return null;
        }
        $source = html_entity_decode(
            $node->getAttribute('data-djot-src'),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
        $tree = (new AstCodec())->encode(CarveConverter::create()->parse($source));
        $children = self::nodeList($tree['children'] ?? null);

        if (
            isset($children[0])
            && !preg_match('/`<(?:th|td|dt|dd)\b/i', $source)
        ) {
            if (!$this->sourceSafe) {
                return $children;
            }
            $this->setPrivateAttribute($children[0], "\0carve-stored-source", $source);

            return [$children[0]];
        }

        return $children;
    }

    private function isSupportedInlineTag(string $tag): bool
    {
        return in_array($tag, [
            'a', 'abbr', 'b', 'br', 'cite', 'code', 'del', 'dfn', 'em', 'i',
            'img', 'input', 'ins',
            'kbd', 'mark', 'q', 's', 'samp', 'span', 'strike', 'strong', 'sub', 'sup',
            'time', 'u', 'var',
            'script', 'style', 'template', 'noscript', 'caption', 'figcaption',
        ], true);
    }

    private function mathTex(DOMElement $node): ?string
    {
        foreach ($node->childNodes as $semantics) {
            if (!$semantics instanceof DOMElement || strtolower($semantics->tagName) !== 'semantics') {
                continue;
            }
            foreach ($semantics->childNodes as $annotation) {
                if (!$annotation instanceof DOMElement || strtolower($annotation->tagName) !== 'annotation') {
                    continue;
                }
                $encoding = strtolower(trim($annotation->getAttribute('encoding')));
                if (!in_array($encoding, ['application/x-tex', 'text/x-tex', 'latex'], true)) {
                    continue;
                }
                $content = trim($annotation->textContent);
                if ($content !== '') {
                    return $content;
                }
            }
        }
        $alttext = trim($node->getAttribute('alttext'));
        if ($alttext !== '') {
            return $alttext;
        }
        $alt = self::hiddenFormulaImageAlt($node);

        return $alt === '' ? null : $alt;
    }

    /**
     * The alt of a formula's fallback image, where the page hid the MathML so
     * the image renders instead (markup-carve/carve#2361). Adjacency alone is
     * no evidence.
     */
    public static function hiddenFormulaImageAlt(DOMElement $math): string
    {
        if (!self::mathIsHidden($math)) {
            return '';
        }
        $image = self::fallbackImage($math);

        return $image === null ? '' : trim($image->getAttribute('alt'));
    }

    /**
     * Whether an `<img>` is the fallback image of a formula that imported as
     * math with the image's alt as its content, so the formula imports once.
     */
    public static function isDroppedFormulaImage(DOMElement $image): bool
    {
        $alt = trim($image->getAttribute('alt'));
        if ($alt === '') {
            return false;
        }
        $previous = self::adjacentElement($image, false);
        if ($previous === null) {
            return false;
        }
        $math = strtolower($previous->tagName) === 'math' ? $previous : self::soleMath($previous);
        if ($math === null || self::fallbackImage($math) !== $image) {
            return false;
        }

        return self::mathTexContent($math) === $alt;
    }

    /**
     * The TeX a `<math>` imports as, from any of the three tiers, or `''`.
     */
    public static function mathTexContent(DOMElement $math): string
    {
        foreach ($math->childNodes as $semantics) {
            if (!$semantics instanceof DOMElement || strtolower($semantics->tagName) !== 'semantics') {
                continue;
            }
            foreach ($semantics->childNodes as $annotation) {
                if (!$annotation instanceof DOMElement || strtolower($annotation->tagName) !== 'annotation') {
                    continue;
                }
                $encoding = strtolower(trim($annotation->getAttribute('encoding')));
                if (!in_array($encoding, ['application/x-tex', 'text/x-tex', 'latex'], true)) {
                    continue;
                }
                $content = trim($annotation->textContent);
                if ($content !== '') {
                    return $content;
                }
            }
        }
        $alttext = trim($math->getAttribute('alttext'));

        return $alttext !== '' ? $alttext : self::hiddenFormulaImageAlt($math);
    }

    /**
     * The text of a `<math>` with no TeX, when flattening cannot change its
     * value (markup-carve/carve#2361): tokens in order, inside grouping
     * elements only. A fraction or a script would flatten into a different
     * number, so any other element refuses.
     */
    public static function linearMathText(DOMElement $math): ?string
    {
        $text = '';
        $spaced = false;
        $read = static function (iterable $nodes) use (&$read, &$text, &$spaced): bool {
            foreach ($nodes as $node) {
                if (self::isBlankOrComment($node)) {
                    continue;
                }
                if (!$node instanceof DOMElement) {
                    return false;
                }
                $tag = strtolower($node->tagName);
                if ($tag === 'semantics') {
                    $first = null;
                    foreach ($node->childNodes as $child) {
                        if (!self::isBlankOrComment($child)) {
                            $first = $child;

                            break;
                        }
                    }
                    if ($first === null || !$read([$first])) {
                        return false;
                    }
                } elseif (in_array($tag, ['mrow', 'mstyle', 'mpadded'], true)) {
                    if (!$read($node->childNodes)) {
                        return false;
                    }
                } elseif (in_array($tag, ['mi', 'mn', 'mo', 'mtext'], true)) {
                    $token = '';
                    foreach ($node->childNodes as $child) {
                        if (!$child instanceof DOMText) {
                            return false;
                        }
                        $token .= $child->textContent;
                    }
                    $collapsed = trim(preg_replace('/[ \t\n\r\f]+/', ' ', $token) ?? $token, ' ');
                    // A space keeps two words or numbers apart, so 1, space, 2 is not 12.
                    if ($spaced && preg_match('/[\p{L}\p{N}]$/u', $text) === 1 && preg_match('/^[\p{L}\p{N}]/u', $collapsed) === 1) {
                        $text .= ' ';
                    }
                    $text .= $collapsed;
                    $spaced = false;
                } elseif ($tag === 'mspace') {
                    $spaced = true;
                } else {
                    return false;
                }
            }

            return true;
        };

        return $read($math->childNodes) && $text !== '' ? $text : null;
    }

    /**
     * A pipe-table row is one line, so a comment holding a line break has no
     * spelling in a cell (markup-carve/carve#2372). A list-table cell is not a
     * row, so it is not held to that.
     */
    public static function commentBreaksACellRow(DOMComment $comment, bool $listTableForBlockCells): bool
    {
        if (preg_match('/[\r\n]/', $comment->textContent) !== 1) {
            return false;
        }
        $cell = self::enclosingCell($comment);

        return $cell !== null && !($listTableForBlockCells && self::tableHoldsABlockCell($cell));
    }

    /**
     * A heading is one line too, so the same comment has no spelling in one
     * (markup-carve/carve#2396).
     */
    public static function commentBreaksAHeadingLine(DOMComment $comment): bool
    {
        if (preg_match('/[\r\n]/', $comment->textContent) !== 1) {
            return false;
        }
        for ($parent = $comment->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (preg_match('/^h[1-6]$/', strtolower($parent->tagName)) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function enclosingCell(DOMNode $node): ?DOMElement
    {
        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (in_array(strtolower($parent->tagName), ['td', 'th'], true)) {
                return $parent;
            }
        }

        return null;
    }

    /**
     * Whether the table around this cell is written as a list table under
     * `listTableForBlockCells`, the same test `table()` applies.
     */
    public static function tableHoldsABlockCell(DOMElement $cell): bool
    {
        for ($table = $cell->parentNode; $table instanceof DOMElement; $table = $table->parentNode) {
            if (strtolower($table->tagName) !== 'table') {
                continue;
            }
            foreach ($table->getElementsByTagName('*') as $candidate) {
                if (in_array(strtolower($candidate->tagName), ['td', 'th'], true) && self::cellHoldsBlocks($candidate)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private static function isBlankOrComment(DOMNode $node): bool
    {
        return $node instanceof DOMComment
            || ($node instanceof DOMText && preg_match('/^[ \t\n\r\f]*$/D', $node->textContent) === 1);
    }

    private static function adjacentElement(DOMNode $node, bool $forward): ?DOMElement
    {
        $sibling = $forward ? $node->nextSibling : $node->previousSibling;
        while ($sibling !== null && self::isBlankOrComment($sibling)) {
            $sibling = $forward ? $sibling->nextSibling : $sibling->previousSibling;
        }

        return $sibling instanceof DOMElement ? $sibling : null;
    }

    /**
     * The `<math>` a `<span>` holds and nothing else.
     */
    private static function soleMath(DOMElement $wrapper): ?DOMElement
    {
        if (strtolower($wrapper->tagName) !== 'span') {
            return null;
        }
        $math = null;
        foreach ($wrapper->childNodes as $child) {
            if (self::isBlankOrComment($child)) {
                continue;
            }
            if ($math !== null || !$child instanceof DOMElement || strtolower($child->tagName) !== 'math') {
                return null;
            }
            $math = $child;
        }

        return $math;
    }

    /**
     * The `<img>` that renders a formula for a reader without MathML: the
     * next element after the `<math>`, or after a `<span>` holding only it.
     */
    private static function fallbackImage(DOMElement $math): ?DOMElement
    {
        $found = self::adjacentElement($math, true);
        $wrapper = $math->parentNode;
        if ($found === null && $wrapper instanceof DOMElement && self::soleMath($wrapper) === $math) {
            $found = self::adjacentElement($wrapper, true);
        }

        return $found !== null && strtolower($found->tagName) === 'img' ? $found : null;
    }

    private static function mathIsHidden(DOMElement $math): bool
    {
        if (self::hiddenByStyle($math)) {
            return true;
        }
        $wrapper = $math->parentNode;

        return $wrapper instanceof DOMElement && self::soleMath($wrapper) === $math && self::hiddenByStyle($wrapper);
    }

    /**
     * The effective inline `display` is `none`: the last declaration wins, an
     * `!important` one first.
     */
    private static function hiddenByStyle(DOMElement $node): bool
    {
        $display = null;
        $important = false;
        foreach (explode(';', $node->getAttribute('style')) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2 || strtolower(trim($parts[0])) !== 'display') {
                continue;
            }
            $value = strtolower(trim($parts[1]));
            $isImportant = preg_match('/!\s*important$/D', $value) === 1;
            if ($important && !$isImportant) {
                continue;
            }
            $display = trim((string)preg_replace('/!\s*important$/D', '', $value));
            $important = $isImportant;
        }

        return $display === 'none';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inlineHtml(string $html): array
    {
        $document = HtmlDomLoader::load('<carve-inline-root>' . $html . '</carve-inline-root>');
        $root = $document->getElementsByTagName('carve-inline-root')->item(0);

        if (!$root instanceof DOMElement) {
            return [];
        }
        $previous = $this->preserveInlineWhitespace;
        $this->preserveInlineWhitespace = true;
        try {
            return $this->inlines($this->children($root));
        } finally {
            $this->preserveInlineWhitespace = $previous;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function delimitedMath(DOMElement $node): ?array
    {
        $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
        $math = array_search('math', $classes, true);
        if ($math === false) {
            return null;
        }
        unset($classes[$math]);
        $display = null;
        foreach ($classes as $index => $class) {
            if ($class === 'inline' || $class === 'display') {
                $display = $class === 'display';
                unset($classes[$index]);

                break;
            }
        }
        if ($display === null) {
            return null;
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return null;
            }
        }
        $text = trim($node->textContent);
        $open = $display ? '\\[' : '\\(';
        $close = $display ? '\\]' : '\\)';
        if (!str_starts_with($text, $open) || !str_ends_with($text, $close)) {
            return null;
        }
        $content = trim((string)preg_replace('/\s+/u', ' ', substr($text, 2, -2)));
        if ($content === '') {
            return null;
        }
        $nodeValue = ['type' => 'math', 'display' => $display, 'content' => $content];
        $attrs = $this->attrs($node, strtolower(trim($node->getAttribute('role'))) === 'math' ? ['class', 'role'] : ['class']);
        if ($classes !== []) {
            $attrs['classes'] = array_values($classes);
            $available = array_fill_keys([...($attrs['order'] ?? []), '.class'], true);
            $order = [];
            foreach ($node->attributes as $attribute) {
                $slot = match (strtolower($attribute->nodeName)) {
                    'id' => '#id',
                    'class' => '.class',
                    default => strtolower($attribute->nodeName),
                };
                if (isset($available[$slot]) && !in_array($slot, $order, true)) {
                    $order[] = $slot;
                }
            }
            $attrs['order'] = $order;
        }
        if ($attrs !== []) {
            $nodeValue['attrs'] = $attrs;
        }

        return $nodeValue;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private function plainInlineText(array $nodes): string
    {
        $text = '';
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'text') {
                $text .= self::stringValue($node['value'] ?? null);
            } elseif (is_array($node['children'] ?? null)) {
                $text .= $this->plainInlineText(self::nodeList($node['children']));
            }
        }

        return $text;
    }

    private function emptyCodeRunEndsAt(DOMElement $node): bool
    {
        for ($sibling = $node->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
            if ($sibling instanceof DOMText && trim($sibling->textContent) === '') {
                continue;
            }

            return false;
        }

        if ($this->tableCellAllowsEmptyCode !== null) {
            return $this->tableCellAllowsEmptyCode;
        }

        $parent = $node->parentNode;
        if (!$parent instanceof DOMElement) {
            return true;
        }
        $tag = strtolower($parent->tagName);
        if (in_array($tag, ['em', 'i', 'strong', 'b', 'u', 's', 'strike', 'mark', 'ins', 'del', 'sup', 'sub'], true)) {
            return true;
        }
        if (in_array($tag, ['a', 'q', 'abbr', 'time', 'samp', 'var', 'kbd', 'cite', 'dfn'], true)) {
            return false;
        }
        if ($tag === 'span' && $this->attrs($parent, []) !== []) {
            return false;
        }
        if (isset(self::BLOCK_TAGS[$tag]) || in_array($tag, ['li', 'dt', 'dd', 'th', 'td'], true)) {
            return true;
        }

        return $this->emptyCodeRunEndsAt($parent);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     *
     * @return list<array<string, mixed>>
     */
    private function flattenBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $children = $this->projectToInlines($block);
            if ($out !== [] && $children !== []) {
                $out[] = ['type' => 'text', 'value' => ' '];
            }
            foreach ($children as $child) {
                $out[] = $child;
            }
        }

        return $this->coalesceText($out);
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<array<string, mixed>>
     */
    private function projectToInlines(array $node): array
    {
        static $inlineTypes = [
            'autolink' => true,
            'caption_number' => true,
            'code' => true,
            'comment' => true,
            'delete' => true,
            'emphasis' => true,
            'emoji' => true,
            'footnote_ref' => true,
            'hard_break' => true,
            'highlight' => true,
            'image' => true,
            'insert' => true,
            'link' => true,
            'math' => true,
            'raw_inline' => true,
            'soft_break' => true,
            'span' => true,
            'strike' => true,
            'strong' => true,
            'subscript' => true,
            'superscript' => true,
            'symbol' => true,
            'ruby' => true,
            'tag' => true,
            'text' => true,
            'underline' => true,
        ];
        $type = $node['type'] ?? null;
        // A block comment reaching an inline-only slot is spelled inline: a
        // line comment there would swallow the rest of the row.
        if ($type === 'comment' && ($node['block'] ?? false) === true) {
            $content = self::stringValue($node['content'] ?? null);
            if (str_contains($content, '%}') || preg_match('/\R\s*\R/u', $content) === 1) {
                return [];
            }

            return [['type' => 'comment', 'content' => $content, 'delimited' => true, 'block' => false]];
        }
        if (is_string($type) && isset($inlineTypes[$type])) {
            return [$node];
        }
        // A code block reaching an inline-only slot becomes a code SPAN, which is
        // the inline spelling of the same kind and the only one that keeps the
        // text as code (carve-php#2371). Its newlines fold to a space: a row ends
        // at the first one, and a caption continuation line that opens a block
        // would end the caption instead of continuing it.
        if ($type === 'code_block') {
            $content = is_string($node['content'] ?? null) ? $node['content'] : '';
            if ($content === '') {
                return [];
            }

            return [['type' => 'code', 'value' => str_replace("\n", ' ', $content)]];
        }
        // A raw region keeps its bytes here rather than projecting to nothing.
        // It reached an inline-only slot - a cell, a caption - and the generic
        // arm below recurses into `children`, which a raw node has none of, so
        // the element's whole content left the document in silence, and a cell
        // it emptied took its row and perhaps the table with it
        // (carve-php#2362).
        //
        // A ROW takes only a one-line region: it ends at the first newline, and
        // the table with it. Joining the region's lines is not the way in
        // either - that changes bytes the raw-keep report reads back, and a live
        // event handler would come back as an `attribute-dropped` row
        // (carve#2261). So a multi-line region in a cell stays dropped, which is
        // what it was before this arm. A caption is not a row and takes one.
        if ($type === 'raw_block') {
            $content = is_string($node['content'] ?? null) ? $node['content'] : '';
            if ($content !== '' && ($this->inCaption || !str_contains($content, "\n"))) {
                return [
                    [
                        'type' => 'raw_inline',
                        'content' => $content,
                        'format' => is_string($node['format'] ?? null) ? $node['format'] : 'html',
                    ],
                ];
            }

            return [];
        }
        if ($type === 'list') {
            $out = [];
            foreach (self::nodeList($node['items'] ?? null) as $item) {
                $projected = $this->projectToInlines($item);
                if ($out !== [] && $projected !== []) {
                    $out[] = ['type' => 'text', 'value' => ' '];
                }
                array_push($out, ...$projected);
            }

            return $out;
        }
        if ($type === 'table') {
            // A nested table's own caption is inline content that reached this
            // slot with the table, and neither arm below walks it, so it left
            // the document while the table's cells stayed (carve-php#2371).
            $caption = [];
            foreach (self::nodeList($node['caption'] ?? null) as $inline) {
                array_push($caption, ...$this->projectToInlines($inline));
            }
            if (!$this->inCaption) {
                $lines = [];
                foreach (self::nodeList($node['rows'] ?? null) as $row) {
                    $cells = [];
                    foreach (self::nodeList($row['cells'] ?? null) as $cell) {
                        $cells[] = trim($this->plainInlineText($this->projectToInlines($cell)));
                    }
                    if ($cells !== []) {
                        $lines[] = '| ' . implode(' | ', $cells) . ' |';
                    }
                }
                $head = trim($this->plainInlineText($caption));
                if ($head !== '') {
                    array_unshift($lines, $head);
                }

                return $lines === [] ? [] : [['type' => 'text', 'value' => implode(' ', $lines)]];
            }
            $out = $caption;
            foreach (self::nodeList($node['rows'] ?? null) as $row) {
                foreach (self::nodeList($row['cells'] ?? null) as $cell) {
                    $projected = $this->projectToInlines($cell);
                    if ($out !== [] && $projected !== []) {
                        $out[] = ['type' => 'text', 'value' => ' '];
                    }
                    array_push($out, ...$projected);
                }
            }

            return $out;
        }

        $out = [];
        // `title` leads, because it is a node's first visible text: an
        // admonition built from a `<details>` carries its `<summary>` there, and
        // walking only `children` left the summary out of the caption it reached
        // while the report called the element unwrapped (carve-php#2371).
        foreach (['title', 'children', 'items', 'rows', 'cells', 'caption'] as $slot) {
            foreach (self::nodeList($node[$slot] ?? null) as $child) {
                $projected = $this->projectToInlines($child);
                if (
                    $out !== []
                    && $projected !== []
                    && !isset($inlineTypes[$child['type'] ?? ''])
                    && !$this->inlineEndsWithSpace($out[count($out) - 1])
                    && !$this->inlineStartsWithSpace($projected[0])
                ) {
                    $out[] = ['type' => 'text', 'value' => ' '];
                }
                foreach ($projected as $inline) {
                    $out[] = $inline;
                }
            }
        }

        return $out;
    }

    /**
     * @phpstan-param T $target
     *
     * @template T of array<string, mixed>
     *
     * @param array<string, mixed> $target
     * @param \DOMElement $node
     * @param list<string> $skip
     */
    private function attachAttrs(array &$target, DOMElement $node, array $skip = []): void
    {
        $attrs = $this->attrs($node, $skip);
        if ($attrs !== []) {
            $target['attrs'] = $attrs;
        }
    }

    /**
     * @phpstan-param T $target
     *
     * @template T of array<string, mixed>
     *
     * @param array<string, mixed> $target
     * @param \DOMElement $node
     * @param list<string> $skip
     */
    private function attachAttrsInElementOrder(array &$target, DOMElement $node, array $skip = []): void
    {
        $attrs = $this->attrs($node, $skip);
        if ($attrs === []) {
            return;
        }
        $available = array_fill_keys($attrs['order'] ?? [], true);
        // Only an id's place is observable; without one the order stays canonical.
        if (!isset($available['#id'])) {
            $target['attrs'] = $attrs;

            return;
        }
        $order = [];
        foreach ($node->attributes as $attribute) {
            $slot = match (strtolower($attribute->nodeName)) {
                'id' => '#id',
                'class' => '.class',
                default => strtolower($attribute->nodeName),
            };
            if (isset($available[$slot])) {
                $order[] = $slot;
            }
        }
        foreach (array_keys($available) as $slot) {
            if (!in_array($slot, $order, true)) {
                $order[] = $slot;
            }
        }
        $attrs['order'] = $order;
        $target['attrs'] = $attrs;
    }

    /**
     * @param \DOMElement $node
     * @param list<string> $skip
     *
     * @return Attrs
     */
    private function attrs(DOMElement $node, array $skip): array
    {
        $skip = array_fill_keys(array_map('strtolower', $skip), true);
        $attrs = [];
        $classes = [];
        $keyValues = [];
        $order = [];
        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (
                isset($skip[$name])
                || $name === 'style'
                || ($name === 'role' && self::isDerivedRole($node, $attribute->value))
                || str_starts_with($name, 'on')
                || str_starts_with($name, 'data-djot-')
                || in_array($name, ['srcdoc', 'formaction'], true)
            ) {
                continue;
            }
            if ($name === 'id') {
                $attrs['id'] = $attribute->value;

                continue;
            }
            if ($name === 'class') {
                $classes = preg_split('/\s+/', $attribute->value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $name) !== 1) {
                continue;
            }
            // A quoted value stops at the line break (markup-carve/carve#2385).
            if (preg_match('/[\r\n]/', $attribute->value) === 1) {
                continue;
            }
            $keyValues[$name] = $attribute->value;
            if (in_array($name, ['srcset', 'imagesrcset', 'ping', 'attributionsrc'], true)) {
                $this->urlListCarriers[$node] = null;
            }
        }
        $tag = strtolower($node->tagName);
        $alignment = $this->styleEnum($node, 'text-align', ['left', 'right', 'center']);
        if ($alignment !== null && !in_array($tag, ['td', 'th'], true)) {
            $alignmentClass = $this->alignmentClasses[$alignment] ?? null;
            if (is_string($alignmentClass) && $alignmentClass !== '') {
                if (!in_array($alignmentClass, $classes, true)) {
                    $classes[] = $alignmentClass;
                }
            } elseif ($this->importMode !== 'safe') {
                $keyValues['align'] = $alignment;
            }
        }
        if ($classes !== []) {
            $attrs['classes'] = $classes;
        }
        if ($keyValues !== []) {
            $attrs['keyValues'] = $keyValues;
        }
        if (array_key_exists('id', $attrs)) {
            $order[] = '#id';
        }
        if ($classes !== []) {
            $order[] = '.class';
        }
        foreach ($keyValues as $name => $_value) {
            $order[] = $name;
        }
        if ($order !== []) {
            $attrs['order'] = $order;
        }

        return $attrs;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function coalesceText(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $last = array_key_last($out);
            if ($last !== null && ($node['type'] ?? null) === 'text' && ($out[$last]['type'] ?? null) === 'text') {
                $out[$last]['value'] = self::stringValue($out[$last]['value'] ?? null)
                    . self::stringValue($node['value'] ?? null);
            } else {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function trimBlockEdges(array $nodes): array
    {
        while (($nodes[0]['type'] ?? null) === 'text') {
            $value = self::stringValue($nodes[0]['value'] ?? null);
            $nodes[0]['value'] = preg_replace('/^[ \t]+/', '', $value) ?? $value;
            if ($nodes[0]['value'] !== '') {
                break;
            }
            array_shift($nodes);
        }
        while ($nodes !== []) {
            $last = array_key_last($nodes);
            if (($nodes[$last]['type'] ?? null) !== 'text') {
                break;
            }
            $value = self::stringValue($nodes[$last]['value'] ?? null);
            $nodes[$last]['value'] = preg_replace('/[ \t]+$/', '', $value) ?? $value;
            if ($nodes[$last]['value'] !== '') {
                break;
            }
            array_pop($nodes);
        }

        return $nodes;
    }

    /**
     * @return list<\DOMNode>
     */
    private function children(DOMNode $node): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        return $children;
    }

    /**
     * Does this `href`/`src` name no destination Carve can carry? Empty, or a
     * scheme the section 25 sink blanks, which is imported the same way
     * (markup-carve/carve#2254).
     */
    public static function carriesNoDestination(string $value): bool
    {
        return trim($value) === '' || HtmlRenderer::blankDangerousScheme($value) === '';
    }

    /**
     * Is this a non-empty destination whose scheme the section 25 sink blanks?
     */
    public static function hasDeniedScheme(string $value): bool
    {
        return trim($value) !== '' && HtmlRenderer::blankDangerousScheme($value) === '';
    }

    /**
     * Would keeping this element as raw HTML write a denied destination?
     */
    public static function holdsADeniedDestination(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        if (($tag === 'a' && self::hasDeniedScheme($element->getAttribute('href'))) || ($tag === 'img' && self::hasDeniedScheme($element->getAttribute('src')))) {
            return true;
        }
        foreach ($element->getElementsByTagName('a') as $anchor) {
            if (self::hasDeniedScheme($anchor->getAttribute('href'))) {
                return true;
            }
        }
        foreach ($element->getElementsByTagName('img') as $image) {
            if (self::hasDeniedScheme($image->getAttribute('src'))) {
                return true;
            }
        }

        return false;
    }

    private function hasClass(DOMElement $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [], true);
    }
}
