<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\Frontmatter as FrontmatterBlock;
use MarkupCarve\Carve\Node\Block\AbbreviationDef;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\Footnote as FootnoteBlock;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Profile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use SplFileInfo;

/**
 * Encodes the AST as plain arrays (JSON) and reads it back.
 *
 * The reason this exists: every non-HTML integration currently has to pivot
 * through rendered HTML and re-parse it, because the AST is only reachable as
 * PHP objects. An interchange encoding turns that into a direct hop - editors
 * (ProseMirror/Tiptap), linters, structural diffing and cross-implementation
 * conformance all want the tree, not markup.
 *
 * ## Shape
 *
 * A document is
 *
 *     {"ast": 1, "type": "document", "children": [...]}
 *
 * and every node is
 *
 *     {"type": "heading", "level": 2, "attrs": {"id": "x"}, "children": [...]}
 *
 * - `type` is the node's own `getType()` value - the same snake_case vocabulary
 *   `Profile` uses for allow/deny lists, so the names are already public.
 * - `attrs` and `children` are omitted when empty, keeping small documents small.
 * - Everything else is the node's declared state, keyed by property name.
 *
 * Historical note: the encoder used to lift `frontmatter` and footnote
 * definitions out of `children` onto document-level fields. That matched the
 * old PART 12 §2 root form, but PART 12 §7 replaced it: the root now carries
 * exactly `type`, `children`, and `srcByteLength`, with frontmatter and
 * footnote definitions published as block nodes in the tree. The decoder still
 * adopts the old root fields so stored payloads remain readable.
 *
 * Field names are derived by reflection over properties declared on the node
 * class itself (the base class bookkeeping - parent, children, attributes,
 * attributeOrder - is excluded). That means a new node type is encodable the day
 * it is added, with no table to forget. The consequence is that renaming a
 * property is a wire-format change, which is what AstCodecSchemaTest pins: the
 * full type-to-fields map is a golden file (tests/fixtures/ast-schema.json), so
 * any rename shows up as a diff to approve rather than a silent break for
 * consumers.
 */
class AstCodec
{
    /**
     * Encoding version.
     *
     * NOT emitted: PART 12 §3 forbids exposing a field the reference shape does
     * not have, and carve-js's document root is `type`, `children`,
     * `srcByteLength` - no envelope. The shape is spec-defined, so a consumer
     * does not need to be told which version it is reading.
     *
     * It is still read: version 1 used this engine's internal field names
     * (`content` where the reference says `value`), so a payload announcing it
     * gets a message naming the change rather than a field-loss error.
     *
     * @var int
     */
    public const VERSION = 2;

    /**
     * Fields the reference publishes even when they hold this engine's default.
     *
     * @var array<string>
     */
    /**
     * Fields the reference publishes on EVERY node of that type, so this engine
     * must too - even when the value equals this engine's property default.
     *
     * Suppressing a default-valued field makes two documents differ in FIELD SET
     * rather than in value: a tight list dropped `tight` while a loose one kept
     * it, so a consumer could not tell "absent because it is the default" from
     * "absent because this engine does not support it". PART 12 section 3 exists
     * to remove exactly that guess.
     *
     * The list was DERIVED, not curated: every node type in the reference's
     * output over the 507-document corpus, keeping the fields present on all
     * occurrences of that type. It replaced a one-entry list that had been grown
     * a bug at a time (`heading.level`, after `# H` and `## H` disagreed).
     *
     * Keyed by the WIRE field name, so it is compared after the rename.
     *
     * @var array<string>
     */
    private const ALWAYS_PUBLISHED = [
        'abbreviation.abbr', 'abbreviation.expansion', 'abbreviation_def.abbr',
        'abbreviation_def.expansion', 'admonition.children', 'admonition.kind',
        'autolink.href', 'autolink.text', 'block_quote.children',
        'code.value', 'code_block.content', 'comment.block',
        'comment.content', 'critic_comment.text', 'definition_list.items',
        'delete.children', 'div.children', 'document.children',
        'document.srcByteLength', 'emphasis.children', 'escaped_text.value',
        'figure.caption', 'figure.target', 'footnote_ref.id',
        'frontmatter.content', 'frontmatter.format',
        'heading.children', 'heading.level', 'heading_ref.target',
        'highlight.children', 'image.alt', 'image.src',
        'inline_extension.content', 'inline_extension.name', 'inline_footnote.inline',
        'insert.children', 'line_block.children', 'link.children',
        'link.href', 'list.items', 'list.ordered',
        'list.tight', 'list_item.children', 'literal_inline.content',
        'math.content', 'math.display', 'mention.user',
        'paragraph.children', 'raw_block.content', 'raw_block.format',
        'raw_inline.content', 'raw_inline.format', 'smart_punctuation.kind',
        'smart_punctuation.value', 'span.attrs', 'span.children',
        'strike.children', 'strong.children', 'subscript.children',
        'substitution.newText', 'substitution.oldText', 'superscript.children',
        'symbol.name', 'table.rows', 'table_cell.children',
        'table_cell.header', 'table_row.cells', 'tag.name',
        'text.value', 'underline.children',
    ];

    /**
     * Internal node classes that never appear as wire node types.
     *
     * @var array<string>
     */
    private const WIRE_EXCLUDED_TYPES = ['caption', 'raw_text'];

    /**
     * Base-class state that is either structural (children) or not part of the
     * tree's identity (parent, and the attribute bookkeeping handled by attrs).
     *
     * @var array<string>
     */
    private const BASE_PROPERTIES = [
        'parent',
        'children',
        'attributes',
        'attributeOrder',
        // Handled explicitly rather than by the reflection walk: PART 12 §4
        // gives `pos` a defined shape, so it converts to and from the value
        // object instead of being assigned raw.
        'pos',
        // Derived state, not document content: whether a footnote reference
        // found its definition. The reference engine computes it at render time
        // from the definitions it already has, so publishing it would be a
        // field the reference shape does not carry (PART 12 §3). Re-derived
        // after decoding, in `resolveFootnoteRefs`.
        'unresolved',
    ];

    /**
     * @var array<string, class-string<\MarkupCarve\Carve\Node\Node>>|null
     */
    private static ?array $classMap = null;

    /**
     * Node classes registered by consuming applications, which is how an app's
     * own node types become encodable without patching this package.
     *
     * @var array<string, class-string<\MarkupCarve\Carve\Node\Node>>
     */
    private static array $registered = [];

    /**
     * Per-property default cache, keyed by class and property name.
     *
     * @var array<string, array{has: bool, value: mixed}>
     */
    private static array $defaults = [];

    /**
     * Teach the codec a node class defined outside this package.
     *
     * @param class-string<\MarkupCarve\Carve\Node\Node> $class
     */
    public static function register(string $class): void
    {
        $reflection = new ReflectionClass($class);
        /** @var \MarkupCarve\Carve\Node\Node $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        self::$registered[$instance->getType()] = $class;
        self::$classMap = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function encode(Document $document): array
    {
        return $this->encodeNode($document);
    }

    public function encodeJson(Document $document, int $flags = 0): string
    {
        return (string)json_encode($this->encode($document), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException When the payload is not a document this version can read.
     */
    public function decode(array $data): Document
    {
        $version = $data['ast'] ?? null;
        if ($version !== null && $version !== self::VERSION) {
            throw new RuntimeException(sprintf(
                'This payload announces AST encoding version %s; this codec writes %d. Version 1 used '
                    . "this engine's internal field names, which version 2 maps to the PART 12 "
                    . 'reference shape (`content` became `value`, a list\'s `children` became `items`).',
                is_scalar($version) ? (string)$version : get_debug_type($version),
                self::VERSION,
            ));
        }

        $data = $this->canonicalizeStoredPayload($data);

        $node = $this->decodeNode($data);
        if (!$node instanceof Document) {
            throw new RuntimeException('The payload root must be a document node');
        }

        // Compatibility with trees written before PART 12 §7 replaced the old
        // §2 root fields: stored payloads may still carry frontmatter and
        // footnote definitions on the document root. Adopt them into the tree
        // form; do not treat these as a second canonical spelling.
        if ($this->adoptFrontmatter($data, $node)) {
            $frontmatter = is_array($data['frontmatter'] ?? null) ? $data['frontmatter'] : [];
            if (!is_array($data['children'] ?? null)) {
                $data['children'] = [];
            }
            array_unshift($data['children'], [
                'type' => 'frontmatter',
                'format' => is_string($frontmatter['format'] ?? null) ? $frontmatter['format'] : 'yaml',
                'content' => is_string($frontmatter['content'] ?? null) ? $frontmatter['content'] : '',
            ]);
            unset($data['frontmatter']);
        }

        if ($this->adoptFootnoteDefs($data, $node)) {
            // Adopted, so it is not lost - the definitions are in the tree now.
            // The loss check compares the payload against a RE-ENCODE, and this
            // engine encodes definitions as nodes, so leaving the map in the
            // comparison would report the very field that was just honored.
            unset($data['footnoteDefs']);
        }

        $this->verifyNothingWasLost($data, $node);
        self::resolveFootnoteRefs($node);

        return $node;
    }

    /**
     * Mark footnote references whose label has no definition in the decoded tree.
     *
     * The wire deliberately carries no `unresolved` field - the reference shape
     * has none, and PART 12 §3 forbids inventing one. It is derived state, and
     * the definitions needed to derive it are right there in the tree, so a
     * decoded document computes it the same way a parsed one is given it.
     *
     * Without this, a decoded unresolved reference rendered as a real footnote:
     * a number, a backlink, and a link to a definition that does not exist.
     */
    private static function resolveFootnoteRefs(Document $document): void
    {
        $defined = [];
        foreach ($document->getChildren() as $child) {
            if ($child instanceof FootnoteBlock) {
                $defined[$child->getLabel()] = true;
            }
        }

        $walk = static function (Node $node) use (&$walk, $defined): void {
            if ($node instanceof FootnoteRef) {
                $node->setUnresolved(!isset($defined[$node->getLabel()]));
            }
            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };
        $walk($document);
    }

    /**
     * Re-encode what was just decoded and complain if a field went missing.
     *
     * A foreign tree used to decode WRONGLY and exit 0: carve-js writes `value`
     * where this codec read `content`, unrecognized keys were ignored, and a
     * missing content field defaulted to an empty string - so every text node
     * came back empty and nothing said so. PART 12 §6 calls that out: "a
     * serializer that loses a field is not a lossy convenience; it is a consumer
     * breaking silently one document later."
     *
     * Comparing the re-encoded tree against the input catches exactly that,
     * including fields lost for reasons nobody predicted. Keys the encoder does
     * not produce - `pos`, which this engine cannot yet emit - are ignored, so
     * accepting a conformant tree from another engine stays possible.
     *
     * @param array<string, mixed> $input
     * @param \MarkupCarve\Carve\Node\Document $document
     *
     * @throws \RuntimeException When decoding dropped content the input carried.
     */

    /**
     * Rebuild the frontmatter block from the root field old stored payloads
     * carried.
     *
     * @param array<string, mixed> $data
     * @param \MarkupCarve\Carve\Node\Document $document
     */
    private function adoptFrontmatter(array $data, Document $document): bool
    {
        $frontmatter = $data['frontmatter'] ?? null;
        if (!is_array($frontmatter)) {
            return false;
        }
        foreach ($document->getChildren() as $child) {
            if ($child instanceof FrontmatterBlock) {
                return false;
            }
        }

        $content = $frontmatter['content'] ?? '';
        $format = $frontmatter['format'] ?? 'yaml';
        $document->prependChild(new FrontmatterBlock(
            is_string($content) ? $content : '',
            is_string($format) ? $format : 'yaml',
        ));

        return true;
    }

    /**
     * Turn an old root-level `footnoteDefs` map into the block nodes this
     * engine uses.
     *
     * Appended at the end, which is where they render from regardless of where
     * they were written - a definition's position is not content.
     *
     * @param array<string, mixed> $data
     * @param \MarkupCarve\Carve\Node\Document $document
     *
     * @return bool Whether the payload carried a map.
     */
    private function adoptFootnoteDefs(array $data, Document $document): bool
    {
        $defs = $data['footnoteDefs'] ?? null;
        if (!is_array($defs)) {
            return false;
        }

        $existing = [];
        foreach ($document->getChildren() as $child) {
            if ($child instanceof FootnoteBlock) {
                $existing[$child->getLabel()] = true;
            }
        }

        foreach ($defs as $label => $blocks) {
            if (isset($existing[(string)$label]) || !is_array($blocks)) {
                continue;
            }

            $footnote = new FootnoteBlock((string)$label);
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }

                /** @var array<string, mixed> $decoded */
                $decoded = $block;
                $footnote->appendChild($this->decodeNode($decoded));
            }
            $document->appendChild($footnote);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $input
     * @param \MarkupCarve\Carve\Node\Document $document
     *
     * @throws \RuntimeException
     */
    private function verifyNothingWasLost(array $input, Document $document): void
    {
        $lost = [];
        $this->compareNode($input, $this->encodeNode($document), '', $lost);

        if ($lost !== []) {
            throw new RuntimeException(sprintf(
                'Decoding lost %d field(s) the payload carried: %s. The payload may come from an '
                    . 'engine whose field names differ; this decoder reads the PART 12 shape.',
                count($lost),
                implode(', ', array_slice($lost, 0, 6)),
            ));
        }
    }

    /**
     * Adopt old stored payload spellings into the canonical PART 12 shape before
     * decoding. These are compatibility reads, not alternate wire spellings.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function canonicalizeStoredPayload(array $data): array
    {
        if (($data['type'] ?? null) === 'raw_text') {
            $data['type'] = 'text';
            if (array_key_exists('content', $data) && !array_key_exists('value', $data)) {
                $data['value'] = $data['content'];
            }
            unset($data['content']);
        }

        if (($data['type'] ?? null) === 'figure' && is_array($data['children'] ?? null)) {
            foreach ($data['children'] as $child) {
                if (!is_array($child)) {
                    continue;
                }
                if (($child['type'] ?? null) === 'caption') {
                    $data['caption'] = is_array($child['children'] ?? null) ? $child['children'] : [];
                } elseif (!array_key_exists('target', $data)) {
                    $data['target'] = $child;
                }
            }
            unset($data['children']);
        }

        if (is_array($data['attrs'] ?? null)) {
            /** @var array<string, mixed> $attrs */
            $attrs = $data['attrs'];
            $canonicalKeys = ['id' => true, 'classes' => true, 'keyValues' => true, 'order' => true];
            $hasOldAttrKey = array_key_exists('class', $attrs);
            foreach ($attrs as $key => $_value) {
                if (!isset($canonicalKeys[(string)$key])) {
                    $hasOldAttrKey = true;

                    break;
                }
            }
            if ($hasOldAttrKey) {
                $data['attrs'] = $this->canonicalizeFlatAttrs($attrs);
            }
        }

        if (($data['type'] ?? null) === 'table_cell') {
            if (($data['rowspan'] ?? null) === true && !array_key_exists('span', $data)) {
                $data['span'] = 'rowspan';
            } elseif (($data['colspan'] ?? null) === true && !array_key_exists('span', $data)) {
                $data['span'] = 'colspan';
            }
            unset($data['rowspan'], $data['colspan']);
        }

        foreach (['children', 'items', 'rows', 'cells', 'inline', 'caption'] as $key) {
            if (!is_array($data[$key] ?? null)) {
                continue;
            }
            foreach ($data[$key] as $index => $child) {
                if (is_array($child) && is_string($child['type'] ?? null)) {
                    /** @var array<string, mixed> $childNode */
                    $childNode = $child;
                    $data[$key][$index] = $this->canonicalizeStoredPayload($childNode);
                }
            }
        }
        if (is_array($data['target'] ?? null) && is_string($data['target']['type'] ?? null)) {
            /** @var array<string, mixed> $target */
            $target = $data['target'];
            $data['target'] = $this->canonicalizeStoredPayload($target);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $attrs
     *
     * @return array<string, mixed>
     */
    private function canonicalizeFlatAttrs(array $attrs): array
    {
        $canonical = [];
        if (is_string($attrs['id'] ?? null)) {
            $canonical['id'] = $attrs['id'];
        }
        if (is_string($attrs['class'] ?? null)) {
            $classes = preg_split('/\s+/', trim($attrs['class'])) ?: [];
            $classes = array_values(array_filter($classes, static fn (string $class): bool => $class !== ''));
            if ($classes !== []) {
                $canonical['classes'] = $classes;
            }
        }

        $keyValues = [];
        foreach ($attrs as $key => $value) {
            if ($key === 'id' || $key === 'class' || $key === 'order') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $keyValues[$key] = (string)$value;
            }
        }
        if ($keyValues !== []) {
            $canonical['keyValues'] = $keyValues;
        }
        if (is_array($attrs['order'] ?? null)) {
            $order = [];
            foreach ($attrs['order'] as $slot) {
                if (is_scalar($slot) && (string)$slot !== '') {
                    $order[] = (string)$slot;
                }
            }
            $canonical['order'] = $order;
        }

        return $canonical;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $roundTripped
     * @param string $path
     * @param array<string> $lost
     */
    private function compareNode(array $input, array $roundTripped, string $path, array &$lost): void
    {
        $type = is_string($input['type'] ?? null) ? $input['type'] : '?';
        $here = $path === '' ? $type : $path . '.' . $type;

        foreach ($input as $key => $value) {
            if ($key === 'ast' || $key === 'pos') {
                // Envelope, and a field this engine cannot yet produce (§4).
                continue;
            }
            if ($type === 'footnote' && $key === 'id' && ($roundTripped['label'] ?? null) === $value) {
                // Compatibility path for stored trees written before PART 12 §7:
                // `id` was consumed as the definition label and is not a second
                // published spelling.
                continue;
            }

            if (!array_key_exists($key, $roundTripped)) {
                // The encoder omits a field holding the node's own default, so a
                // payload that spells one out explicitly - `children: []` on an
                // empty document, `srcByteLength: 0` - is not losing anything.
                // Only a value the re-encode would have written counts.
                if (!self::isOmittedDefault($type, $key, $value)) {
                    $lost[] = $here . '.' . $key;
                }

                continue;
            }

            $mirrors = $roundTripped[$key];
            if (!is_array($value) || !is_array($mirrors) || !is_array($value[0] ?? null)) {
                continue;
            }

            foreach ($value as $index => $child) {
                $mirror = $mirrors[$index] ?? null;
                if (!is_array($child) || !is_array($mirror)) {
                    continue;
                }

                /** @var array<string, mixed> $childNode */
                $childNode = $child;
                /** @var array<string, mixed> $mirrorNode */
                $mirrorNode = $mirror;
                $this->compareNode($childNode, $mirrorNode, $here . '.' . $key, $lost);
            }
        }
    }

    /**
     * Whether the encoder legitimately omits this field/value pair.
     *
     * @param string $type
     * @param string $field
     * @param mixed $value
     */
    private static function isOmittedDefault(string $type, string $field, mixed $value): bool
    {
        if ($value === [] || $value === null) {
            // Empty children/attrs and an absent value carry nothing either way.
            return true;
        }

        $class = self::classMap()[ReferenceShape::classTypeFor($type)] ?? null;
        if ($class === null) {
            return false;
        }

        $reflection = new ReflectionClass($class);
        foreach (self::stateProperties($reflection) as $property) {
            if (ReferenceShape::fieldFor($type, $property->getName()) !== $field) {
                continue;
            }

            $default = self::defaultFor($reflection, $property);

            return $default['has'] && $value === $default['value'];
        }

        return false;
    }

    public function decodeJson(string $json): Document
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->decode($data);
    }

    /**
     * The encodable fields per node type, and which of them a payload must
     * carry, for documentation and drift tests.
     *
     * A field is required when the node has no default for it - neither a
     * declared property default nor a constructor parameter default - so there
     * is nothing to fall back on when it is omitted.
     *
     * @return array<string, array{fields: array<string>, required: array<string>}>
     */
    public static function schema(): array
    {
        $schema = [];
        foreach (self::classMap() as $type => $class) {
            if (in_array($type, self::WIRE_EXCLUDED_TYPES, true)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            $fields = [];
            $required = [];
            foreach (self::stateProperties($reflection) as $property) {
                // The schema describes the WIRE, so it reports PART 12 field
                // names; an internal with no reference counterpart is absent
                // rather than renamed.
                $field = ReferenceShape::fieldFor($type, $property->getName());
                if ($field === null) {
                    continue;
                }
                $fields[] = $field;
                if (!self::defaultFor($reflection, $property)['has']) {
                    $required[] = $field;
                }
            }
            $schema[$type] = ['fields' => $fields, 'required' => $required];
        }
        ksort($schema);

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeNode(Node $node): array
    {
        if ($node instanceof RawText) {
            $text = new Text($node->getContent());
            $text->setPos($node->getPos());

            return $this->encodeNode($text);
        }

        // PART 12 §3 and profiles.md: an autolink and an admonition are their
        // OWN types, not a link/div carrying a flag. This engine models them as
        // the broader class, so the canonical name is what goes on the wire -
        // the same distinction Profile already draws for profile matching.
        $type = match (true) {
            $node instanceof Mention && $node->getCssClass() === 'tag' => 'tag',
            $node instanceof Div && $node->isTyped() => 'admonition',
            default => Profile::canonicalTypeOf($node),
        };
        $encoded = ['type' => $type];
        /** @var \ReflectionClass<\MarkupCarve\Carve\Node\Node> $reflection */
        $reflection = new ReflectionClass($node);
        foreach (self::stateProperties($reflection) as $property) {
            if ($node instanceof Table && $property->getName() === 'caption') {
                continue;
            }
            $value = $property->isInitialized($node) ? $property->getValue($node) : null;
            $default = self::defaultFor($reflection, $property);

            // Omit a field only when it holds the node's own default. Omitting
            // every null/[]/false instead would lose information wherever the
            // default is not falsy: a loose list (tight = false, default true)
            // encoded without `tight` and decoded back as tight.
            // ALWAYS_PUBLISHED wins over the default suppression. The
            // reference emits a heading's `level` unconditionally, so dropping
            // it on `# H` - level 1 being the property default - produced a
            // heading whose field set did not match the reference's for that
            // type, while `## H` did. A consumer would have to treat the field
            // as optional and guess 1, which is the implicit rule PART 12 §3
            // exists to remove.
            // PART 12 §3: publish the reference field name, and never an
            // internal the reference does not have. Resolved BEFORE the
            // always-published test, because that list is keyed by the field
            // name that goes on the wire, not by this engine's property name
            // (`marker` is published as `bulletChar`).
            $field = ReferenceShape::fieldFor($type, $property->getName());
            if ($field === null) {
                continue;
            }

            $alwaysPublished = in_array(
                $type . '.' . $field,
                self::ALWAYS_PUBLISHED,
                true,
            );
            if (!$alwaysPublished && $default['has'] && $value === $default['value']) {
                continue;
            }

            $encoded[$field] = $this->encodeValue($value);
        }

        $attributes = $this->encodeAttrs($node);
        if ($attributes !== []) {
            $encoded['attrs'] = $attributes;
        }

        foreach ($this->derivedFields($node) as $field => $derived) {
            $encoded[$field] = $derived;
        }

        // PART 12 §4. Emitted when the parser recorded one, omitted when it
        // could not place the node honestly - §4 forbids inventing a position,
        // and forbids omitting one SILENTLY, which is why `--json` prints a
        // note saying the output is not yet conformant. Publishing what exists
        // makes the remaining gaps visible as "missing pos on X" rather than
        // hiding the whole feature behind an encoder that drops it.
        $span = $node->getPos();
        if ($span !== null && !$node instanceof Document) {
            $encoded['pos'] = $span->toArray();
        }

        if ($node instanceof Figure) {
            $this->encodeFigureChildren($node, $encoded);

            return $encoded;
        }

        $children = $node->getChildren();
        if ($type === 'autolink') {
            // The reference gives an autolink no children - `text` is the label,
            // and publishing both would be a second representation of the same
            // content. The decoder rebuilds the text node from it.
            $children = [];
        }
        if ($node instanceof Mention) {
            // The reference publishes the semantic handle (`user`/`name`) only.
            // The text child is this engine's rendering path.
            $children = [];
        }
        if ($node instanceof Abbreviation) {
            $children = [];
        }
        if ($node instanceof Document && $node->getAbbreviations() !== [] && !$this->hasAbbreviationDefChild($node)) {
            $defs = [];
            foreach ($node->getAbbreviations() as $abbr => $expansion) {
                $defs[] = new AbbreviationDef($abbr, $expansion);
            }
            $children = $node->hasAbbreviationsBeforeBody()
                ? array_merge($defs, $children)
                : array_merge($children, $defs);
        }
        // An EMPTY container is still published when the reference publishes it
        // unconditionally: a table cell claimed by a neighbour's span marker has
        // no children, and dropping the key made it differ from every other cell
        // in field set rather than in content.
        $container = ReferenceShape::containerFor($type);
        if ($children === [] && in_array($type . '.' . $container, self::ALWAYS_PUBLISHED, true)) {
            $encoded[$container] = [];
        }
        if ($node instanceof TableRow) {
            $children = $this->expandedTableRowCells($node);
        }
        if ($children !== []) {
            $encoded[$container] = array_map(
                fn (Node $child): array => $this->encodeNode($child),
                $children,
            );
        }

        return $encoded;
    }

    /**
     * Node-valued state (a div's header nodes, a table caption) is encoded the
     * same way as children, so nothing in the tree needs a second format.
     */

    /**
     * Fields the reference derives from state this engine keeps differently.
     *
     * `ordered` is a boolean over a `listType` string, `checked` comes from a
     * task marker, `header` from a cell flag. They are computed on the way out
     * and reversed on the way in, rather than exported as internals (PART 12 §3).
     *
     * @return array<string, mixed>
     */
    private function derivedFields(Node $node): array
    {
        if ($node instanceof Link && $node->isAutolink()) {
            // The reference gives an autolink no children: `href` is the
            // resolved target, `text` what the author sees.
            return ['text' => self::plainText($node)];
        }

        if ($node instanceof Div && ($node->isTyped() || Profile::canonicalTypeOf($node) === 'admonition')) {
            // `::: warning` - the word is the admonition kind, which this engine
            // keeps as a class rather than a field of its own.
            return ['kind' => (string)($node->getAttributes()['class'] ?? '')];
        }

        if ($node instanceof ListBlock) {
            $ordered = $node->getListType() === ListBlock::TYPE_ORDERED;
            $fields = ['ordered' => $ordered];
            $marker = $node->getMarker();
            $style = $node->getStyle();
            if ($ordered && ($style ?? $marker) === ')') {
                $fields['delim'] = ')';
            }
            if (!$ordered && $marker !== null && $marker !== '-') {
                $fields['bulletChar'] = $marker;
            }

            return $fields;
        }

        if ($node instanceof ListItem) {
            return $node->isTask() ? ['checked' => $node->isCompleted()] : [];
        }

        if ($node instanceof TableCell) {
            $fields = ['header' => $node->isHeader()];
            if ($node->getSpanMarker() === '^') {
                $fields['span'] = 'rowspan';
            } elseif ($node->getSpanMarker() === '<') {
                $fields['span'] = 'colspan';
            }

            return $fields;
        }

        if ($node instanceof Mention) {
            $label = self::plainText($node);

            return [$node->getCssClass() === 'tag' ? 'name' : 'user' => ltrim($label, '@#')];
        }

        if ($node instanceof Comment) {
            return ['block' => $node->getFenceLength() !== null];
        }

        if ($node instanceof Table && $node->getCaption() !== null) {
            return [

                'caption' => array_map(
                    fn (Node $child): array => $this->encodeNode($child),
                    $node->getCaption()->getChildren(),
                ),
            ];
        }

        if ($node instanceof Abbreviation) {
            return ['abbr' => self::plainText($node)];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeAttrs(Node $node): array
    {
        $attrs = $node->getAttributes();
        if ($attrs === []) {
            return [];
        }

        $encoded = [];
        if (isset($attrs['id'])) {
            $encoded['id'] = $attrs['id'];
        }
        if (isset($attrs['class'])) {
            $classes = preg_split('/\s+/', trim($attrs['class'])) ?: [];
            $classes = array_values(array_filter($classes, static fn (string $class): bool => $class !== ''));
            if ($classes !== []) {
                $encoded['classes'] = $classes;
            }
        }

        $keyValues = [];
        foreach ($attrs as $key => $value) {
            if ($key === 'id' || $key === 'class') {
                continue;
            }
            $keyValues[$key] = $value;
        }
        if ($keyValues !== []) {
            $encoded['keyValues'] = $keyValues;
        }

        $order = $node->getAttributeOrder();
        if ($order !== []) {
            $encoded['order'] = array_map(
                static fn (string $slot): string => $slot === 'class' ? '.class' : $slot,
                $order,
            );
        }

        return $encoded;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Figure $node
     * @param array<string, mixed> $encoded
     */
    private function encodeFigureChildren(Figure $node, array &$encoded): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Caption) {
                $encoded['caption'] = array_map(
                    fn (Node $inline): array => $this->encodeNode($inline),
                    $child->getChildren(),
                );

                continue;
            }

            $encoded['target'] = $this->encodeNode($child);
        }

        $encoded += ['target' => ['type' => 'paragraph', 'children' => []], 'caption' => []];
    }

    /**
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    private function expandedTableRowCells(TableRow $row): array
    {
        $expanded = [];
        foreach ($row->getChildren() as $child) {
            if (!$child instanceof TableCell) {
                $expanded[] = $child;

                continue;
            }

            $expanded[] = $child;
            for ($i = 1; $i < $child->getColspan(); $i++) {
                $expanded[] = new TableCell($child->isHeader(), $child->getAlignment(), 1, 1, '<');
            }
        }

        $parent = $row->getParent();
        if (!$parent instanceof Table) {
            return $expanded;
        }

        $rows = $parent->getChildren();
        $rowIndex = array_search($row, $rows, true);
        if (!is_int($rowIndex) || $rowIndex < 1) {
            return $expanded;
        }

        $markers = [];
        for ($previousIndex = 0; $previousIndex < $rowIndex; $previousIndex++) {
            $col = 0;
            foreach ($this->expandedTableRowCellsForRowspanScan($rows[$previousIndex] ?? null) as $cell) {
                if (!$cell instanceof TableCell) {
                    $col++;

                    continue;
                }
                for ($span = 1; $span < $cell->getRowspan(); $span++) {
                    if ($previousIndex + $span === $rowIndex) {
                        $markers[$col] = true;
                    }
                }
                $col++;
            }
        }

        foreach (array_keys($markers) as $col) {
            $expanded = $this->insertTableSpanMarker($expanded, (int)$col, '^');
        }

        return $expanded;
    }

    /**
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    private function expandedTableRowCellsForRowspanScan(?Node $row): array
    {
        if (!$row instanceof TableRow) {
            return [];
        }

        $expanded = [];
        foreach ($row->getChildren() as $child) {
            $expanded[] = $child;
            if (!$child instanceof TableCell) {
                continue;
            }
            for ($i = 1; $i < $child->getColspan(); $i++) {
                $expanded[] = new TableCell($child->isHeader(), $child->getAlignment(), 1, 1, '<');
            }
        }

        return $expanded;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $cells
     * @param string $marker
     * @param int $column
     *
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    private function insertTableSpanMarker(array $cells, int $column, string $marker): array
    {
        $markerCell = new TableCell(false, TableCell::ALIGN_DEFAULT, 1, 1, $marker);
        array_splice($cells, $column, 0, [$markerCell]);

        return $cells;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string, mixed> $data
     */
    private function applyDerivedFields(Node $node, array $data): void
    {
        if ($node instanceof Link && ($data['type'] ?? null) === 'autolink') {
            self::writeProperty($node, 'isAutolink', true);
            if ($node->getChildren() === []) {
                $text = $data['text'] ?? null;
                $node->appendChild(new Text(is_string($text) ? $text : (string)$node->getDestination()));
            }

            return;
        }

        if ($node instanceof Div && array_key_exists('kind', $data)) {
            // `kind` is the OPENER TYPE WORD, not an admonition marker: a
            // Tier-2 container (`::: sidebar`) carries one too, and it is the
            // only thing distinguishing it from a bare `:::` fence whose class
            // came from an attribute line. Keying this on `type === admonition`
            // dropped `typed` for every Tier-2 div, so `carve fmt` reproduced
            // `{.sidebar}` + `:::` instead of the `::: sidebar` the author wrote.
            self::writeProperty($node, 'typed', true);
            $kind = $data['kind'] ?? null;
            if (is_string($kind) && $kind !== '') {
                $node->setAttribute('class', $kind);
            }
            // Falls through to the Div branch below, which recomputes the raw
            // title string an admonition needs just as much as a plain div.
        }

        if ($node instanceof ListBlock && array_key_exists('ordered', $data)) {
            // Four internal list types collapse onto one boolean, so `bullet` is
            // not the only unordered answer: a list whose items carry `checked`
            // is a task list, and rendering it as a plain bullet list would drop
            // every `[x]` marker from the Carve output.
            $unordered = ListBlock::TYPE_BULLET;
            foreach ($node->getChildren() as $item) {
                if ($item instanceof ListItem && $item->isTask()) {
                    $unordered = ListBlock::TYPE_TASK;

                    break;
                }
            }

            self::writeProperty(
                $node,
                'listType',
                $data['ordered'] === true ? ListBlock::TYPE_ORDERED : $unordered,
            );
            if ($data['ordered'] === true) {
                $delim = $data['delim'] ?? $data['bulletChar'] ?? null;
                if (is_string($delim) && $delim !== '') {
                    self::writeProperty($node, 'marker', $delim);
                }
                $olType = $data['olType'] ?? null;
                if (is_string($olType) && $olType !== '') {
                    self::writeProperty($node, 'style', $olType);
                }
            } else {
                $marker = $data['bulletChar'] ?? null;
                if (is_string($marker) && $marker !== '') {
                    self::writeProperty($node, 'marker', $marker);
                }
            }

            return;
        }

        if ($node instanceof ListItem && array_key_exists('checked', $data)) {
            self::writeProperty($node, 'taskMarker', $data['checked'] === true ? 'x' : ' ');

            return;
        }

        if ($node instanceof TableCell && array_key_exists('header', $data)) {
            self::writeProperty($node, 'isHeader', $data['header'] === true);
            $span = $data['span'] ?? null;
            if ($span === 'rowspan') {
                $node->setSpanMarker('^');
            } elseif ($span === 'colspan') {
                $node->setSpanMarker('<');
            }

            return;
        }

        if ($node instanceof Mention) {
            $name = $data['name'] ?? $data['user'] ?? null;
            $isTag = ($data['type'] ?? null) === 'tag' || array_key_exists('name', $data);
            $label = ($isTag ? '#' : '@') . (is_string($name) ? $name : '');
            self::writeProperty($node, 'cssClass', $isTag ? 'tag' : 'mention');
            self::writeProperty($node, 'destination', '');
            self::writeProperty($node, 'title', null);
            self::writeProperty($node, 'referenceLabel', null);
            self::writeProperty($node, 'isAutolink', false);
            if ($node->getChildren() === []) {
                $node->appendChild(new Text($label));
            }

            return;
        }

        if ($node instanceof Comment && array_key_exists('block', $data)) {
            self::writeProperty(
                $node,
                'fenceLength',
                $data['block'] === true ? $this->commentFenceLength($node->getContent()) : null,
            );

            return;
        }

        if ($node instanceof Abbreviation) {
            $abbr = $data['abbr'] ?? null;
            if ($node->getChildren() === [] && is_string($abbr)) {
                $node->appendChild(new Text($abbr));
            }

            return;
        }

        if ($node instanceof Table) {
            if (is_array($data['caption'] ?? null)) {
                $caption = new Caption();
                foreach ($data['caption'] as $child) {
                    if (is_array($child) && is_string($child['type'] ?? null)) {
                        /** @var array<string, mixed> $childNode */
                        $childNode = $child;
                        $caption->appendChild($this->decodeNode($childNode));
                    }
                }
                $node->setCaption($caption);
            }
            $this->collapseTableSpanMarkers($node);

            return;
        }

        if ($node instanceof Document) {
            $this->adoptAbbreviationDefNodes($node);

            return;
        }

        if ($node instanceof Div) {
            if ($node->getClassList() !== [] && !in_array('.class', $node->getAttributeOrder(), true)) {
                self::writeProperty($node, 'typed', true);
            }
            // The reference publishes a container's title as inline NODES, and
            // has no field for this engine's raw title string. Rather than
            // export an internal (PART 12 §3) or lose the title (§6), the raw
            // form is recomputed by writing the nodes back to Carve source.
            $title = $node->getHeaderNodes();
            if ($title !== [] && $node->getHeader() === null) {
                self::writeProperty($node, 'header', self::sourceFor($title));
            }

            return;
        }

        if ($node instanceof TableRow) {
            // The reference has no row flag: a header row is one whose cells are
            // header cells, so recompute rather than invent a field.
            $cells = $node->getChildren();
            $allHeaders = $cells !== [];
            foreach ($cells as $cell) {
                if (!$cell instanceof TableCell || !$cell->isHeader()) {
                    $allHeaders = false;

                    break;
                }
            }
            self::writeProperty($node, 'isHeader', $allHeaders);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string, mixed> $attrs
     */
    private function decodeAttrs(Node $node, array $attrs): void
    {
        $flat = [];
        if (is_string($attrs['id'] ?? null)) {
            $flat['id'] = $attrs['id'];
        }
        if (is_array($attrs['classes'] ?? null)) {
            $classes = [];
            foreach ($attrs['classes'] as $class) {
                if (is_scalar($class) && (string)$class !== '') {
                    $classes[] = (string)$class;
                }
            }
            if ($classes !== []) {
                $flat['class'] = implode(' ', $classes);
            }
        }
        if (is_array($attrs['keyValues'] ?? null)) {
            foreach ($attrs['keyValues'] as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $flat[(string)$key] = (string)$value;
                }
            }
        }
        if ($flat === [] && $attrs !== []) {
            // Stored payload compatibility: the pre-PART-12 shape was a flat
            // attribute map. This is accepted as stored data, not as a second
            // canonical spelling.
            foreach ($attrs as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $flat[(string)$key] = (string)$value;
                }
            }
        }

        $order = [];
        if (is_array($attrs['order'] ?? null)) {
            foreach ($attrs['order'] as $slot) {
                if (is_scalar($slot) && (string)$slot !== '') {
                    $order[] = (string)$slot;
                }
            }
        }

        if ($order !== []) {
            $ordered = [];
            if (array_key_exists('class', $flat) && !in_array('.class', $order, true) && !in_array('class', $order, true)) {
                $ordered['class'] = $flat['class'];
            }
            foreach ($order as $slot) {
                $key = match ($slot) {
                    '#id' => 'id',
                    '.class', 'class' => 'class',
                    default => $slot,
                };
                if (array_key_exists($key, $flat)) {
                    $ordered[$key] = $flat[$key];
                }
            }
            foreach ($flat as $key => $value) {
                if (!array_key_exists($key, $ordered)) {
                    $ordered[$key] = $value;
                }
            }
            $node->setAttributesWithOrder($ordered, $order);
        } else {
            $node->setAttributes($flat);
        }
    }

    private function collapseTableSpanMarkers(Table $table): void
    {
        $origins = [];
        foreach ($table->getChildren() as $row) {
            if (!$row instanceof TableRow) {
                continue;
            }
            $col = 0;
            foreach ($row->getChildren() as $cell) {
                if (!$cell instanceof TableCell) {
                    $col++;

                    continue;
                }
                if ($cell->getSpanMarker() === '^') {
                    if (($origins[$col] ?? null) instanceof TableCell) {
                        $origin = $origins[$col];
                        $origin->setRowspan($origin->getRowspan() + 1);
                        $row->removeChild($cell);
                    }
                    $col++;

                    continue;
                }
                if ($cell->getSpanMarker() === '<') {
                    $previous = $this->previousTableCell($row, $cell);
                    if ($previous !== null) {
                        $previous->setColspan($previous->getColspan() + 1);
                        $row->removeChild($cell);
                    }
                    $col++;

                    continue;
                }

                $origins[$col] = $cell;
                $col += max(1, $cell->getColspan());
            }
        }
    }

    private function previousTableCell(TableRow $row, TableCell $cell): ?TableCell
    {
        $previous = null;
        foreach ($row->getChildren() as $candidate) {
            if ($candidate === $cell) {
                return $previous;
            }
            if ($candidate instanceof TableCell) {
                $previous = $candidate;
            }
        }

        return null;
    }

    private function adoptAbbreviationDefNodes(Document $document): void
    {
        $abbreviations = [];
        $beforeBody = true;
        foreach ($document->getChildren() as $child) {
            if ($child instanceof AbbreviationDef) {
                $abbreviations[$child->getAbbr()] = $child->getExpansion();

                continue;
            }
            if ($abbreviations === []) {
                $beforeBody = false;
            }
        }

        if ($abbreviations !== []) {
            $document->setAbbreviations($abbreviations);
            $document->setAbbreviationsBeforeBody($beforeBody);
        }
    }

    private function hasAbbreviationDefChild(Document $document): bool
    {
        foreach ($document->getChildren() as $child) {
            if ($child instanceof AbbreviationDef) {
                return true;
            }
        }

        return false;
    }

    private function commentFenceLength(string $content): int
    {
        preg_match_all('/%+/', $content, $matches);
        $longest = 0;
        foreach ($matches[0] as $match) {
            $longest = max($longest, strlen($match));
        }

        return max(3, $longest + 1);
    }

    private function shouldDecodeTextAsRawText(string $value): bool
    {
        return preg_match('/(?:^|\\s)\\[[^\\]\\n]+\\]:(?:\\t|\\S)|!?\\[[^\\]\\n]+\\]\\[[^\\]\\n]*\\]/u', $value) === 1;
    }

    /**
     * The Carve source for a run of inline nodes, used to recompute a raw form
     * the reference does not carry.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     */
    private static function sourceFor(array $nodes): string
    {
        $paragraph = new Paragraph();
        foreach ($nodes as $node) {
            $paragraph->appendChild($node);
        }

        $document = new Document();
        $document->appendChild($paragraph);

        $source = CarveConverter::carve()->getRenderer()->render($document);

        // Undo the block framing the writer adds; only the inline run is wanted.
        return trim($source);
    }

    /**
     * The concatenated text of a node's inline descendants.
     */
    private static function plainText(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            $text .= $child instanceof Text ? $child->getContent() : self::plainText($child);
        }

        return $text;
    }

    private static function writeProperty(Node $node, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($node);
        if ($reflection->hasProperty($property)) {
            $reflection->getProperty($property)->setValue($node, $value);
        }
    }

    private function encodeValue(mixed $value): mixed
    {
        if ($value instanceof Node) {
            return $this->encodeNode($value);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->encodeValue($item), $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException When the node type is unknown.
     */
    private function decodeNode(array $data): Node
    {
        $type = $data['type'] ?? null;
        if (!is_string($type)) {
            throw new RuntimeException('Every node needs a string type');
        }

        if ($type === 'text' && is_string($data['value'] ?? null) && $this->shouldDecodeTextAsRawText($data['value'])) {
            $node = new RawText($data['value']);
            if (is_array($data['pos'] ?? null)) {
                /** @var array<string, mixed> $span */
                $span = $data['pos'];
                $node->setPos(SourceSpan::fromArray($span));
            }

            return $node;
        }

        $class = self::classMap()[ReferenceShape::classTypeFor($type)] ?? null;
        if ($class === null) {
            throw new RuntimeException(sprintf(
                'Unknown node type: %s. Application node types must be registered with %s::register().',
                $type,
                self::class,
            ));
        }

        $reflection = new ReflectionClass($class);
        /** @var \MarkupCarve\Carve\Node\Node $node */
        $node = $reflection->newInstanceWithoutConstructor();

        foreach (self::stateProperties($reflection) as $property) {
            if ($node instanceof Table && $property->getName() === 'caption') {
                $this->initializeDefault($node, $property, $type);

                continue;
            }
            $name = ReferenceShape::fieldFor($type, $property->getName());
            if ($name === null) {
                $this->initializeDefault($node, $property, $type);

                continue;
            }
            if (
                $type === 'footnote'
                && $property->getName() === 'label'
                && !array_key_exists($name, $data)
                && array_key_exists('id', $data)
            ) {
                // Compatibility path for stored trees written before PART 12 §7
                // renamed footnote definitions from `id` to `label`.
                $name = 'id';
            }
            if (!array_key_exists($name, $data)) {
                // Omission means "the default". The constructor was bypassed, so
                // a typed property without a declared default would otherwise stay
                // uninitialized and throw on first read - initialize it here, or
                // reject the node when it has no sensible default to fall back on.
                $this->initializeDefault($node, $property, $type);

                continue;
            }
            $property->setValue($node, $this->decodeValue($data[$name], $property));
        }

        $this->decodeAttrs($node, is_array($data['attrs'] ?? null) ? $data['attrs'] : []);

        $container = ReferenceShape::containerFor($type);
        /** @var array<int, array<string, mixed>> $children */
        $children = is_array($data[$container] ?? null) ? $data[$container] : [];
        if ($node instanceof Figure) {
            $children = [];
            if (is_array($data['target'] ?? null)) {
                /** @var array<string, mixed> $target */
                $target = $data['target'];
                $children[] = $target;
            }
            if (is_array($data['caption'] ?? null)) {
                $children[] = ['type' => 'caption', 'children' => $data['caption']];
            }
        }
        foreach ($children as $child) {
            $node->appendChild($this->decodeNode($child));
        }

        $this->applyDerivedFields($node, $data);

        // PART 12 §4. Handled here rather than through the reflection walk: the
        // spec gives `pos` a defined shape, so it converts to the value object
        // instead of being assigned raw. This engine cannot yet PRODUCE spans
        // for every node, but it can carry one it is given.
        if (is_array($data['pos'] ?? null)) {
            /** @var array<string, mixed> $span */
            $span = $data['pos'];
            $node->setPos(SourceSpan::fromArray($span));
        }

        return $node;
    }

    /**
     * Give an omitted property the value the constructor would have given it.
     *
     * A non-nullable property with no declared default has no such value, so it
     * is required: omitting it used to yield a zero. That produced nonsense
     * rather than an error - a heading without a level rendered as `<h0>` - so
     * those are rejected instead. The encoder never omits them (it only omits
     * null, [] and false), so this only ever fires on hand-written or foreign
     * trees, which is exactly where it is wanted.
     *
     * @throws \RuntimeException When a required field is missing.
     */
    private function initializeDefault(Node $node, ReflectionProperty $property, string $nodeType): void
    {
        if ($node instanceof Mention && $property->getName() === 'cssClass') {
            $property->setValue($node, $nodeType === 'tag' ? 'tag' : 'mention');

            return;
        }
        if ($node instanceof Mention && in_array($property->getName(), ['destination', 'title', 'referenceLabel'], true)) {
            $property->setValue($node, $property->getName() === 'destination' ? '' : null);

            return;
        }
        if ($node instanceof Mention && $property->getName() === 'isAutolink') {
            $property->setValue($node, false);

            return;
        }

        $default = self::defaultFor(new ReflectionClass($node), $property);

        if (!$default['has']) {
            throw new RuntimeException(sprintf(
                'Node "%s" is missing the required field "%s"',
                $nodeType,
                $property->getName(),
            ));
        }

        $property->setValue($node, $default['value']);
    }

    /**
     * The value a node gives a property when nobody sets it: the declared
     * property default, else the matching constructor parameter default. When
     * there is neither, the field is required - inventing a scalar zero there
     * produced nonsense (a heading without a level rendered as `<h0>`).
     *
     * @param \ReflectionClass<\MarkupCarve\Carve\Node\Node> $reflection
     * @param \ReflectionProperty $property
     *
     * @return array{has: bool, value: mixed}
     */
    private static function defaultFor(ReflectionClass $reflection, ReflectionProperty $property): array
    {
        $cacheKey = $reflection->getName() . '::' . $property->getName();
        if (array_key_exists($cacheKey, self::$defaults)) {
            return self::$defaults[$cacheKey];
        }

        $default = ['has' => false, 'value' => null];

        if ($property->hasDefaultValue()) {
            $default = ['has' => true, 'value' => $property->getDefaultValue()];
        } else {
            $constructor = $reflection->getConstructor();
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                if ($parameter->getName() === $property->getName() && $parameter->isDefaultValueAvailable()) {
                    $default = ['has' => true, 'value' => $parameter->getDefaultValue()];

                    break;
                }
            }
        }

        self::$defaults[$cacheKey] = $default;

        return $default;
    }

    private function decodeValue(mixed $value, ReflectionProperty $property): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['type']) && is_string($value['type']) && $this->expectsNode($property)) {
            /** @var array<string, mixed> $value */
            return $this->decodeNode($value);
        }

        return array_map(function (mixed $item) use ($property): mixed {
            if (is_array($item) && isset($item['type']) && is_string($item['type'])) {
                /** @var array<string, mixed> $item */
                return $this->decodeNode($item);
            }

            return $this->decodeValue($item, $property);
        }, $value);
    }

    private function expectsNode(ReflectionProperty $property): bool
    {
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }

        return is_a($type->getName(), Node::class, true);
    }

    /**
     * Properties that carry the node's own state, parent-class bookkeeping aside.
     *
     * @param \ReflectionClass<\MarkupCarve\Carve\Node\Node> $reflection
     *
     * @return array<\ReflectionProperty>
     */
    private static function stateProperties(ReflectionClass $reflection): array
    {
        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic() || in_array($property->getName(), self::BASE_PROPERTIES, true)) {
                continue;
            }
            $properties[] = $property;
        }

        return $properties;
    }

    /**
     * Type name to node class, discovered from the node directories so a new
     * node type is encodable without registering it anywhere.
     *
     * @return array<string, class-string<\MarkupCarve\Carve\Node\Node>>
     */
    private static function classMap(): array
    {
        if (self::$classMap !== null) {
            return self::$classMap;
        }

        $map = [];
        $root = dirname(__DIR__);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            // Node classes are not confined to Node/: extensions ship their own
            // (Extension\Frontmatter), which the corpus round-trip caught.
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'MarkupCarve\\Carve\\' . str_replace('/', '\\', $relative);
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(Node::class)) {
                continue;
            }

            /** @var \MarkupCarve\Carve\Node\Node $instance */
            $instance = $reflection->newInstanceWithoutConstructor();
            /** @var class-string<\MarkupCarve\Carve\Node\Node> $nodeClass */
            $nodeClass = $class;
            $map[$instance->getType()] = $nodeClass;
        }

        self::$classMap = $map + self::$registered;

        return self::$classMap;
    }
}
