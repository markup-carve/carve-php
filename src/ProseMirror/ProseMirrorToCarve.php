<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\ProseMirror;

use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Section;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\CriticComment;
use MarkupCarve\Carve\Node\Inline\Delete;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\Highlight;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\Insert;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Subscript;
use MarkupCarve\Carve\Node\Inline\Superscript;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\Underline;
use MarkupCarve\Carve\Node\Node;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Builds a Carve AST from a ProseMirror (Tiptap) document.
 *
 * The counterpart of ProseMirrorRenderer, and of `serializeToCarve` in
 * carve-grammars - except this reads the tree rather than emitting source, so an
 * application can go editor to AST to any renderer without a Node runtime.
 *
 * The inverse of the two shape differences applies: a text node's marks become
 * nested Carve inline elements (innermost mark closest to the text), and a
 * ProseMirror name is resolved back through the published schema map.
 *
 * Names the map does not know are an error rather than a silent skip. An editor
 * that grew a node type nobody mapped is exactly the case where dropping content
 * quietly is worst.
 */
class ProseMirrorToCarve
{
    /**
     * Carve types that hold their content as a string and take a ProseMirror
     * MARK rather than a node, so the text arrives on a text node and has to be
     * lifted back onto the Carve node.
     *
     * @var array<string, class-string<\MarkupCarve\Carve\Node\Inline\InlineNode>>
     */
    private const CONTENT_BEARING_MARKS = [
        'code' => Code::class,
        'critic_comment' => CriticComment::class,
    ];

    /**
     * @param array<string, mixed> $document
     *
     * @throws \RuntimeException When the payload is not a ProseMirror document.
     */
    public function convert(array $document): Document
    {
        $type = $document['type'] ?? null;
        if ($type !== 'doc') {
            throw new RuntimeException('The payload root must be a ProseMirror doc node');
        }

        $carveDocument = new Document();
        foreach ($this->childrenOf($document) as $child) {
            $node = $this->buildBlock($child);
            if ($node !== null) {
                $carveDocument->appendChild($node);
            }
        }

        return $carveDocument;
    }

    public function convertJson(string $json): Document
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->convert($data);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException When the ProseMirror name is not in the map.
     */
    protected function buildBlock(array $data): ?Node
    {
        $name = $data['type'] ?? null;
        if (!is_string($name)) {
            throw new RuntimeException('Every ProseMirror node needs a string type');
        }

        // The renderer emits a table caption as this leading child; put it back
        // on the table as state.
        if ($name === 'carveCaption') {
            $caption = new Caption();
            foreach ($this->childrenOf($data) as $child) {
                foreach ($this->buildInlines($child) as $built) {
                    $caption->appendChild($built);
                }
            }

            return $caption;
        }

        // The renderer hoists Carve sections into this wrapper; unwrap it.
        if ($name === 'carveSection') {
            $section = new Section();
            foreach ($this->childrenOf($data) as $child) {
                $built = $this->buildBlock($child);
                if ($built !== null) {
                    $section->appendChild($built);
                }
            }

            return $section;
        }

        $node = $this->instantiate($name, $data);
        $this->applyAttributes($node, $data);

        if ($node instanceof CodeBlock) {
            // Same asymmetry as inline code: the text is state, not children.
            $text = '';
            foreach ($this->childrenOf($data) as $child) {
                $text .= self::asString($child['text'] ?? '');
            }
            $this->setState($node, 'content', $text);

            return $node;
        }

        $inline = in_array($node->getType(), ['paragraph', 'heading', 'table_cell', 'definition_term', 'caption'], true);
        if ($inline) {
            $inlines = [];
            foreach ($this->childrenOf($data) as $child) {
                foreach ($this->buildInlines($child) as $built) {
                    $inlines[] = $built;
                }
            }
            foreach ($this->mergeAdjacentMarks($inlines) as $built) {
                $node->appendChild($built);
            }

            return $node;
        }

        foreach ($this->childrenOf($data) as $child) {
            $built = $this->buildBlock($child);
            if ($built === null) {
                continue;
            }
            if ($built instanceof Caption && $node instanceof Table) {
                $node->setCaption($built);

                continue;
            }
            $node->appendChild($built);
        }

        if ($node instanceof TableRow) {
            // ProseMirror marks header CELLS; Carve also carries the flag on the
            // row, which is what puts the row in <thead>.
            $cells = $node->getChildren();
            $allHeaders = $cells !== [];
            foreach ($cells as $cell) {
                if (!$cell instanceof TableCell || !$cell->isHeader()) {
                    $allHeaders = false;

                    break;
                }
            }
            if ($allHeaders) {
                $this->setState($node, 'isHeader', true);
            }
        }

        return $node;
    }

    /**
     * Merge runs of the same mark element back into one.
     *
     * ProseMirror stores marks per text node, so `*bold with /italic/ inside*`
     * arrives as three separately-bolded pieces. Reassembling them literally
     * yields three adjacent <strong> elements where Carve had one, which is a
     * visible HTML difference even though the text is intact.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     *
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    protected function mergeAdjacentMarks(array $nodes): array
    {
        $merged = [];
        foreach ($nodes as $node) {
            $previous = $merged[count($merged) - 1] ?? null;

            if (
                $previous !== null
                && $previous::class === $node::class
                && SchemaMap::isMark($node->getType())
                && $previous->getAttributes() === $node->getAttributes()
            ) {
                foreach ($node->getChildren() as $child) {
                    $previous->appendChild($child);
                }
                // Recurse so nested runs inside the merged element coalesce too.
                $this->replaceChildren($previous, $this->mergeAdjacentMarks($previous->getChildren()));

                continue;
            }

            if (SchemaMap::isMark($node->getType())) {
                $this->replaceChildren($node, $this->mergeAdjacentMarks($node->getChildren()));
            }

            $merged[] = $node;
        }

        return $merged;
    }

    /**
     * Swap a node's children for a rebuilt list. Node exposes append and remove
     * rather than a bulk setter, so the old ones are removed first.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<\MarkupCarve\Carve\Node\Node> $children
     */
    protected function replaceChildren(Node $node, array $children): void
    {
        foreach ($node->getChildren() as $existing) {
            $node->removeChild($existing);
        }
        foreach ($children as $child) {
            $node->appendChild($child);
        }
    }

    /**
     * A ProseMirror inline node becomes one or more Carve nodes: a text node with
     * marks nests into mark elements, outermost mark first.
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException
     *
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    protected function buildInlines(array $data): array
    {
        $name = $data['type'] ?? null;
        if (!is_string($name)) {
            throw new RuntimeException('Every ProseMirror node needs a string type');
        }

        if ($name === 'text') {
            $text = self::asString($data['text'] ?? '');
            $marks = is_array($data['marks'] ?? null) ? $data['marks'] : [];

            // `code` and `critic_comment` are marks in ProseMirror but
            // content-bearing nodes in Carve: the text belongs on the node, and
            // appending it as a child would render an empty element.
            foreach (self::CONTENT_BEARING_MARKS as $carveType => $class) {
                $markIndex = null;
                foreach ($marks as $index => $mark) {
                    if (is_array($mark) && ($mark['type'] ?? null) === SchemaMap::nameFor($carveType)) {
                        $markIndex = $index;

                        break;
                    }
                }

                if ($markIndex !== null) {
                    unset($marks[$markIndex]);

                    return [$this->wrapInMarks(new $class($text), array_values($marks))];
                }
            }

            return [$this->wrapInMarks(new Text($text), $marks)];
        }

        $node = $this->instantiate($name, $data);
        $this->applyAttributes($node, $data);
        foreach ($this->childrenOf($data) as $child) {
            foreach ($this->buildInlines($child) as $built) {
                $node->appendChild($built);
            }
        }

        return [$this->wrapInMarks($node, $data['marks'] ?? [])];
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param mixed $marks
     */
    protected function wrapInMarks(Node $node, mixed $marks): Node
    {
        if (!is_array($marks) || $marks === []) {
            return $node;
        }

        // ProseMirror lists marks outermost-first, so wrap in reverse to keep the
        // innermost mark closest to the text.
        foreach (array_reverse($marks) as $entry) {
            if (!is_array($entry) || !isset($entry['type']) || !is_string($entry['type'])) {
                continue;
            }

            $markType = $entry['type'];
            /** @var array<string, mixed> $mark */
            $mark = $entry;
            $wrapper = $this->instantiate($markType, $mark);
            $this->applyAttributes($wrapper, $mark);
            $wrapper->appendChild($node);
            $node = $wrapper;
        }

        return $node;
    }

    /**
     * @param string $proseMirrorName
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException When the name has no Carve type.
     */
    protected function instantiate(string $proseMirrorName, array $data): Node
    {
        $carveType = SchemaMap::carveTypeFor($proseMirrorName);
        if ($carveType === null) {
            throw new RuntimeException(sprintf(
                'ProseMirror node "%s" is not in the schema map; add it upstream in carve-grammars '
                    . 'rather than restating the mapping here',
                $proseMirrorName,
            ));
        }

        $node = $this->newNode($carveType);

        // State the map records as several ProseMirror names has to be put back:
        // the name is the only place that information survives.
        if ($node instanceof ListBlock) {
            $this->setState($node, 'listType', $proseMirrorName === 'orderedList' ? 'ordered' : 'bullet');
        } elseif ($node instanceof ListItem && $proseMirrorName === 'taskItem') {
            $itemAttrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
            $checked = self::asBool($itemAttrs['checked'] ?? false);
            $this->setState($node, 'taskMarker', $checked ? 'x' : ' ');
        } elseif ($node instanceof TableCell && $proseMirrorName === 'tableHeader') {
            $this->setState($node, 'isHeader', true);
        } elseif ($node instanceof Div) {
            $class = match ($proseMirrorName) {
                'carveTabSet' => 'tabs',
                'carveTab' => 'tab',
                default => null,
            };
            if ($class !== null) {
                $node->setAttribute('class', $class);
            }
        }

        return $node;
    }

    /**
     * Editor attrs back onto the node: the typed state each class needs, then
     * every remaining key as a Carve attribute, which is how an application's
     * `data-*` keys survive.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string, mixed> $data
     */
    protected function applyAttributes(Node $node, array $data): void
    {
        $attrs = $data['attrs'] ?? [];
        if (!is_array($attrs)) {
            return;
        }

        $consumed = [];
        foreach ($attrs as $key => $value) {
            $consumed[$key] = match (true) {
                $node instanceof Heading && $key === 'level' => $this->setState($node, 'level', self::asInt($value)),
                $node instanceof CodeBlock && $key === 'language' => $this->setState($node, 'language', self::asString($value)),
                $node instanceof ListBlock && $key === 'start' => $this->setState($node, 'start', self::asInt($value)),
                $node instanceof ListBlock && $key === 'tight' => $this->setState($node, 'tight', self::asBool($value)),
                $node instanceof TableCell && $key === 'colspan' => $this->setState($node, 'colspan', self::asInt($value)),
                $node instanceof TableCell && $key === 'rowspan' => $this->setState($node, 'rowspan', self::asInt($value)),
                $node instanceof TableCell && $key === 'alignment' => $this->setState($node, 'alignment', self::asString($value)),
                $node instanceof Image && $key === 'src' => $this->setState($node, 'source', self::asString($value)),
                $node instanceof Image && $key === 'alt' => $this->setState($node, 'alt', self::asString($value)),
                $node instanceof Link && $key === 'href' => $this->setState($node, 'destination', self::asString($value)),
                $node instanceof Math && $key === 'src' => $this->setState($node, 'content', self::asString($value)),
                $node instanceof Math && $key === 'display' => $this->setState($node, 'display', self::asBool($value)),
                $node instanceof Div && $key === 'label' => $this->setState($node, 'label', self::asString($value)),
                $node instanceof Mention && $key === 'cssClass' => $this->setState($node, 'cssClass', self::asString($value)),
                ($node instanceof Image || $node instanceof Link) && $key === 'title' => $this->setState($node, 'title', self::asString($value)),
                // Editor bookkeeping that has no Carve meaning.
                in_array($key, ['checked', 'languageRaw'], true) => true,
                default => false,
            };
        }

        foreach ($attrs as $key => $value) {
            if (($consumed[$key] ?? false) === true) {
                continue;
            }
            if (is_scalar($value)) {
                $node->setAttribute((string)$key, self::asString($value));
            }
        }
    }

    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    private static function asBool(mixed $value): bool
    {
        return is_scalar($value) && (bool)$value;
    }

    /**
     * Node state lives in protected properties with no setters for most classes,
     * so it is written by reflection - the same mechanism AstCodec decodes with.
     */
    protected function setState(Node $node, string $property, mixed $value): bool
    {
        $reflection = new ReflectionClass($node);
        if (!$reflection->hasProperty($property)) {
            return false;
        }

        $reflection->getProperty($property)->setValue($node, $value);

        return true;
    }

    protected function newNode(string $carveType): Node
    {
        $class = self::CLASS_MAP[$carveType] ?? null;
        if ($class === null) {
            throw new RuntimeException(sprintf('No node class for Carve type "%s"', $carveType));
        }

        $reflection = new ReflectionClass($class);
        /** @var \MarkupCarve\Carve\Node\Node $node */
        $node = $reflection->newInstanceWithoutConstructor();

        // Bypassing the constructor leaves typed properties uninitialized, which
        // throws on first read; give each its declared or constructor default.
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic() || $property->isInitialized($node)) {
                continue;
            }
            if ($property->hasDefaultValue()) {
                $property->setValue($node, $property->getDefaultValue());

                continue;
            }
            $default = null;
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                if ($parameter->getName() === $property->getName() && $parameter->isDefaultValueAvailable()) {
                    $default = $parameter->getDefaultValue();

                    break;
                }
            }
            $type = $property->getType();
            if ($default === null && $type instanceof ReflectionNamedType && !$type->allowsNull()) {
                $default = match ($type->getName()) {
                    'array' => [],
                    'bool' => false,
                    'int' => 0,
                    'float' => 0.0,
                    'string' => '',
                    default => null,
                };
            }
            $property->setValue($node, $default);
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, array<string, mixed>>
     */
    protected function childrenOf(array $data): array
    {
        $content = $data['content'] ?? [];
        if (!is_array($content)) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $children */
        $children = array_values(array_filter($content, 'is_array'));

        return $children;
    }

    /**
     * Carve type to node class. Only the types the map marks as mapped can
     * arrive here, so this covers the bridge's vocabulary rather than the whole
     * AST.
     *
     * @var array<string, class-string<\MarkupCarve\Carve\Node\Node>>
     */
    private const CLASS_MAP = [
        'paragraph' => Paragraph::class,
        'heading' => Heading::class,
        'text' => Text::class,
        'hard_break' => HardBreak::class,
        'block_quote' => BlockQuote::class,
        'code_block' => CodeBlock::class,
        'thematic_break' => ThematicBreak::class,
        'list' => ListBlock::class,
        'list_item' => ListItem::class,
        'table' => Table::class,
        'table_row' => TableRow::class,
        'table_cell' => TableCell::class,
        'image' => Image::class,
        'math' => Math::class,
        'footnote_ref' => FootnoteRef::class,
        'footnote' => Footnote::class,
        'div' => Div::class,
        'admonition' => Div::class,
        'definition_list' => DefinitionList::class,
        'definition_term' => DefinitionTerm::class,
        'definition_description' => DefinitionDescription::class,
        'mention' => Mention::class,
        'inline_extension' => InlineExtension::class,
        'strong' => Strong::class,
        'emphasis' => Emphasis::class,
        'underline' => Underline::class,
        'strike' => Strike::class,
        'code' => Code::class,
        'highlight' => Highlight::class,
        'subscript' => Subscript::class,
        'superscript' => Superscript::class,
        'insert' => Insert::class,
        'delete' => Delete::class,
        'span' => Span::class,
        'abbreviation' => Abbreviation::class,
        'link' => Link::class,
        'autolink' => Link::class,
    ];
}
