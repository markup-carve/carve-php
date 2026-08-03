<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use JsonException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\Frontmatter as FrontmatterBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Footnote as FootnoteBlock;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
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
use MarkupCarve\Carve\Parser\BlockParser;
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
     * Node types this engine has and the wire does not (PART 12 §5).
     *
     * The class map is built by reflection, so a node class is publishable the
     * day it is added - which is what keeps the codec complete, and what makes
     * an internal one leak by default. `raw_text` is the case §5 names: markup
     * the parser declined, kept so the writer can reproduce it verbatim.
     *
     * Encoding maps it to `text` (see `encodeNode`); this list keeps it out of
     * the PUBLISHED schema as well, so a consumer validating against
     * {@see self::schema()} is not told about a type the encoder cannot produce
     * and the spec's own schema rejects.
     *
     * DECODING still accepts it. This engine emitted `raw_text` payloads until
     * now, a stored document cannot be recalled, and reading one back as the
     * node it names loses nothing - it is only the publishing side that §5
     * governs.
     *
     * @var array<string>
     */
    public const NOT_ON_THE_WIRE = ['raw_text'];

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
        // The spans come from the Document rather than the encoded array: they
        // are internal, so `ReferenceShape` keeps them off the wire and the
        // encoder never puts them there.
        return self::publishAbbreviationDefs(
            $this->encodeNode($document),
            $document->getAbbreviationSpans(),
        );
    }

    /**
     * Move abbreviation definitions off the ROOT and into the tree.
     *
     * PART 12 §7 fixes the root at `type`, `children` and `srcByteLength`, and
     * this engine kept two more fields there: the `abbr => expansion` map and a
     * flag recording whether the definitions preceded the body. Both are
     * authored content - dropping them would lose every `*[ABBR]: ...` line -
     * so they move into `abbreviation_def` block nodes, which is where the
     * reference publishes them.
     *
     * The flag does not need a field of its own: it says WHERE the definitions
     * were, and the nodes are now somewhere. Publishing them first or last says
     * the same thing, and `decode` reads it back off the placement - the same
     * trick `comment.block` uses for a fence width.
     *
     * @param array<string, mixed> $encoded
     *
     * @return array<string, mixed>
     */

    /**
     * @param array<string, mixed> $encoded
     * @param array<string, array<string, int>> $spans
     *
     * @return array<string, mixed>
     */
    private static function publishAbbreviationDefs(array $encoded, array $spans = []): array
    {
        $abbreviations = $encoded['abbreviations'] ?? null;
        $beforeBody = ($encoded['abbreviationsBeforeBody'] ?? false) === true;
        unset($encoded['abbreviations'], $encoded['abbreviationsBeforeBody']);
        if (!is_array($abbreviations) || $abbreviations === []) {
            return $encoded;
        }

        $defs = [];
        foreach ($abbreviations as $abbr => $expansion) {
            $def = [
                'type' => 'abbreviation_def',
                'abbr' => (string)$abbr,
                'expansion' => is_scalar($expansion) ? (string)$expansion : '',
            ];
            // The `*[ABBR]: …` line the definition came from, when the parser
            // was tracking positions. Absent rather than invented otherwise.
            $span = $spans[$abbr] ?? null;
            if (is_array($span) && $span !== []) {
                $def['pos'] = $span;
            }
            $defs[] = $def;
        }

        $children = is_array($encoded['children'] ?? null) ? $encoded['children'] : [];
        $encoded['children'] = $beforeBody
            ? array_merge($defs, $children)
            : array_merge($children, $defs);

        return $encoded;
    }

    /**
     * The inverse: `abbreviation_def` children back onto the document.
     *
     * `abbreviationsBeforeBody` is DERIVED from where they sat - before any
     * other block, or after - rather than read from a field the reference shape
     * does not have (§3).
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: bool}
     */
    private static function liftAbbreviationDefs(array $data): array
    {
        $children = is_array($data['children'] ?? null) ? $data['children'] : [];
        $kept = [];
        $abbreviations = [];
        $seenContent = false;
        $beforeBody = true;

        foreach ($children as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'abbreviation_def') {
                $abbr = $child['abbr'] ?? null;
                if (is_scalar($abbr)) {
                    $expansion = $child['expansion'] ?? '';
                    $abbreviations[(string)$abbr] = is_scalar($expansion) ? (string)$expansion : '';
                    if ($seenContent) {
                        $beforeBody = false;
                    }
                }

                continue;
            }
            $seenContent = true;
            $kept[] = $child;
        }

        $data['children'] = $kept;

        return [$data, $abbreviations, $abbreviations !== [] && $beforeBody];
    }

    public function encodeJson(Document $document, int $flags = 0): string
    {
        // json_encode caps nesting at 512 levels too, and a document at the
        // parser's own cap runs past that: a 200-deep list ladder is 805
        // structural levels on the wire. Left at the default, this threw on a
        // document the parser had just produced.
        return (string)json_encode(
            $this->encode($document),
            $flags | JSON_THROW_ON_ERROR,
            self::MAX_JSON_DEPTH,
        );
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

        // Taken out BEFORE the walk: `abbreviation_def` is a wire node this
        // engine has no class for - it keeps definitions on the document - so
        // decoding one as a block would fail on a payload it wrote itself.
        [$data, $abbreviations, $beforeBody] = self::liftAbbreviationDefs($data);

        $node = $this->decodeNode($data);
        if (!$node instanceof Document) {
            throw new RuntimeException('The payload root must be a document node');
        }

        if ($abbreviations !== []) {
            $node->setAbbreviations($abbreviations);
            $node->setAbbreviationsBeforeBody($beforeBody);
        }

        // A root-level map is what this engine published before §7; stored
        // payloads carry it, and it is not a second canonical spelling.
        $storedAbbreviations = $data['abbreviations'] ?? null;
        if ($abbreviations === [] && is_array($storedAbbreviations) && $storedAbbreviations !== []) {
            $map = [];
            foreach ($storedAbbreviations as $abbr => $expansion) {
                $map[(string)$abbr] = is_scalar($expansion) ? (string)$expansion : '';
            }
            $node->setAbbreviations($map);
            $node->setAbbreviationsBeforeBody(($data['abbreviationsBeforeBody'] ?? false) === true);
        }
        unset($data['abbreviations'], $data['abbreviationsBeforeBody']);

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

            if ($key !== 'children' && in_array($type, self::NOT_ON_THE_WIRE, true)) {
                // A payload naming a node the wire does not have was written by
                // an earlier version of this codec. It still DECODES - a stored
                // document cannot be recalled - but re-encoding publishes it
                // under its mapped type, so its own fields have no counterpart
                // by design. That is §5's stated outcome, not a lost field, and
                // reporting it would make the check cry wolf on the one case it
                // is documented to allow. Children are still compared.
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

    /**
     * The parser's own nesting cap. Taken FROM the parser rather than mirrored:
     * a copy drifts silently, and the drift shows up as the decoder refusing
     * documents the parser accepts.
     *
     * @var int
     */
    private const MAX_PARSER_NESTING_DEPTH = BlockParser::MAX_NESTING_DEPTH;

    /**
     * The longest chain of wire levels ONE nesting level can cost.
     *
     * A div costs two - its object, then its `children` array. A list costs
     * four: `list`, `items`, `list_item`, `children`. A table costs six, the
     * deepest chain any container has, which is why the multiplier is 6 and not
     * the 2:1 the div shape suggests.
     *
     * @var int
     */
    private const LONGEST_WIRE_CHAIN = 6;

    /**
     * Deepest JSON nesting `decodeJson()` will read.
     *
     * The bound is on JSON STRUCTURAL levels, which is NOT the unit the parser
     * caps: one AST level costs several structural levels on the wire - two for
     * a div (its object plus its `children` array), four for a list, six for a
     * table. Equating the two numbers is how carve-rs came to reject ASTs its
     * own encoder had produced (carve-rs#389), so this number comes from
     * measurement instead. At the parser's cap of 200 the deepest wire forms
     * are 405 structural levels for a div ladder, 405 for blockquotes, 805 for a
     * list ladder and 402 for a table under a deep chain; 1200 clears the worst
     * of them by half again.
     *
     * Below this the reader used to inherit `json_decode()`'s default of 512,
     * which stood in for a decision nobody had made and reported a payload past
     * it as a raw JsonException.
     *
     * DERIVED, not written down: 1200 was right for a parser capped at 200 and
     * silently wrong for any other number. Raising the parser's cap now raises
     * this with it, which is the whole invariant - the decoder must accept
     * anything the encoder can emit, whatever that limit becomes. carve-rs
     * makes the same bound the same way (carve-rs#394).
     *
     * @var int
     */
    public const MAX_JSON_DEPTH = self::MAX_PARSER_NESTING_DEPTH * self::LONGEST_WIRE_CHAIN + 16;

    public function decodeJson(string $json): Document
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            if (str_contains($e->getMessage(), 'stack depth')) {
                throw new RuntimeException(sprintf(
                    'AST JSON nests deeper than %d levels. The parser caps nesting at %d AST '
                        . 'levels, whose deepest wire form stays well inside this bound, so a '
                        . 'payload past it was not produced by parsing a document.',
                    self::MAX_JSON_DEPTH,
                    self::MAX_PARSER_NESTING_DEPTH,
                ), 0, $e);
            }

            throw $e;
        }

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
            if (in_array($type, self::NOT_ON_THE_WIRE, true)) {
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
     * Drop the opener word from a typed div's `class` attribute.
     *
     * @param array<string, string> $attributes
     * @param string|null $kind
     *
     * @return array<string, string>
     */
    private static function withoutOpenerClass(array $attributes, ?string $kind): array
    {
        if ($kind === null || !isset($attributes['class'])) {
            return $attributes;
        }

        $classes = preg_split('/\s+/', trim($attributes['class'])) ?: [];
        $first = array_search($kind, $classes, true);
        if ($first !== false) {
            unset($classes[$first]);
        }

        if ($classes === []) {
            unset($attributes['class']);

            return $attributes;
        }

        $attributes['class'] = implode(' ', $classes);

        return $attributes;
    }

    /**
     * The opener type word of a typed div (`::: note` -> `note`).
     *
     * Stored as the FIRST class: the parser puts the structural class it derived
     * from the opener ahead of anything an attribute line contributes. Not
     * `Div::admonitionKind()`, which answers the narrower Tier-1 question - a
     * `::: sidebar` has an opener word but is not a callout, and the wire
     * publishes its kind all the same.
     */
    private static function openerKind(Div $node): ?string
    {
        $classes = preg_split('/\s+/', trim((string)($node->getAttributes()['class'] ?? ''))) ?: [];

        return ($classes[0] ?? '') === '' ? null : $classes[0];
    }

    /**
     * This engine's flat attribute map to the reference's structured block.
     *
     * The wire shape is `{id, classes[], keyValues{}, order[]}` with
     * `additionalProperties: false`. This engine stores what the author wrote as
     * a flat `name => value` map, so every attribute became a top-level key -
     * `{"class": "note"}` where the schema wants `{"classes": ["note"]}`, and
     * `title`, `style`, `onclick` and friends alongside it. The published schema
     * rejects all of them.
     *
     * `order` is not reconstructed - it is already recorded, because the
     * formatter needs the author's slot order to reproduce a source line.
     *
     * @param array<string, string> $attributes
     * @param list<string> $order
     *
     * @return array<string, mixed>
     */
    private static function attrsToWire(array $attributes, array $order): array
    {
        $wire = [];
        if (isset($attributes['id'])) {
            $wire['id'] = $attributes['id'];
        }
        if (isset($attributes['class']) && $attributes['class'] !== '') {
            // A class attribute holds a whitespace-separated list; the reference
            // publishes it split, which is also how a consumer wants it.
            $wire['classes'] = preg_split('/\s+/', trim($attributes['class'])) ?: [];
        }

        $keyValues = [];
        foreach ($attributes as $name => $value) {
            if ($name === 'id' || $name === 'class') {
                continue;
            }
            $keyValues[(string)$name] = $value;
        }
        if ($keyValues !== []) {
            $wire['keyValues'] = $keyValues;
        }
        if ($order !== []) {
            $wire['order'] = $order;
        }

        return $wire;
    }

    /**
     * The inverse: the reference's block back to this engine's flat map.
     *
     * Tolerant of the older flat form, because trees written before this change
     * are stored: a key that is not one of the four structured ones is taken as
     * an attribute under its own name, which is exactly what it used to mean.
     *
     * @param array<string, mixed> $wire
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private static function attrsFromWire(array $wire): array
    {
        $attrs = [];
        $order = [];

        if (is_string($wire['id'] ?? null)) {
            $attrs['id'] = $wire['id'];
        }
        if (is_array($wire['classes'] ?? null)) {
            $classes = array_filter($wire['classes'], 'is_string');
            if ($classes !== []) {
                $attrs['class'] = implode(' ', $classes);
            }
        }
        if (is_array($wire['keyValues'] ?? null)) {
            foreach ($wire['keyValues'] as $name => $value) {
                if (is_string($value)) {
                    $attrs[(string)$name] = $value;
                }
            }
        }
        if (is_array($wire['order'] ?? null)) {
            $order = array_values(array_filter($wire['order'], 'is_string'));
        }

        foreach ($wire as $name => $value) {
            if (in_array($name, ['id', 'classes', 'keyValues', 'order'], true)) {
                continue;
            }
            if (is_string($value)) {
                $attrs[(string)$name] = $value;
            }
        }

        if ($order === []) {
            foreach (array_keys($attrs) as $name) {
                $order[] = $name === 'id' ? '#id' : ($name === 'class' ? '.class' : (string)$name);
            }

            return [$attrs, $order];
        }

        // Rebuild the map in the AUTHOR'S order, not the order this function
        // happened to collect the slots in. The renderer emits attributes in
        // storage order, so `{key=c .a #b}` came back as `{#b .a key=c}` and
        // six corpus documents round-tripped to different HTML.
        //
        // An attribute the order does not name is STRUCTURAL rather than
        // authored - an admonition's kind class is set by the parser, not
        // written in a block - and the parser stores those FIRST. Appending
        // them instead put `{title, class}` where `{class, title}` was, which
        // is the one case that survived the first fix.
        $named = [];
        foreach ($order as $slot) {
            $named[$slot === '#id' ? 'id' : ($slot === '.class' ? 'class' : $slot)] = true;
        }

        $ordered = [];
        foreach ($attrs as $name => $value) {
            if (!isset($named[$name])) {
                $ordered[$name] = $value;
            }
        }
        foreach ($order as $slot) {
            $name = $slot === '#id' ? 'id' : ($slot === '.class' ? 'class' : $slot);
            if (array_key_exists($name, $attrs)) {
                $ordered[$name] = $attrs[$name];
            }
        }

        return [$ordered, $order];
    }

    /**
     * Joins adjacent published `text` nodes (PART 12 §1a).
     *
     * The parsed tree is already coalesced - `Ast\TextRunCoalescer` runs at the
     * end of `CarveConverter::parse`, because §6 requires `parse(x)` serialized
     * and deserialized to equal `parse(x)` and a serializer-only merge breaks
     * that (#623). So this is NOT the primary pass.
     *
     * What is left for it is the one case the tree cannot express: `RawText` is
     * an internal type with no counterpart in the AST vocabulary and publishes
     * as `text`, so `Text` + `RawText` + `Text` is one node in the vocabulary
     * and three in the tree. The tree pass deliberately leaves it alone -
     * merging would make the Markdown and Carve writers escape source that must
     * not be re-escaped - so the join has to happen here or the wire carries the
     * run. Those documents consequently do not satisfy §6; they are pinned in
     * tests/TestCase/Ast/TextRunRoundTripTest.php and tracked in #624, which is
     * where the type question is decided.
     *
     * `escaped_text` is NOT merged: PART 12 §5 keeps it distinct from `text`
     * because an escape is authored form.
     *
     * @param array<array<string, mixed>> $nodes
     *
     * @return array<array<string, mixed>>
     */
    private static function coalesceTextRuns(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $previous = $out === [] ? null : $out[count($out) - 1];
            if (
                $previous !== null
                && ($previous['type'] ?? null) === 'text'
                && ($node['type'] ?? null) === 'text'
                && is_string($previous['value'] ?? null)
                && is_string($node['value'] ?? null)
            ) {
                $merged = $previous;
                $merged['value'] = $previous['value'] . $node['value'];
                // Contiguous only. Two pieces that are not adjacent in the
                // source join into a value that is not a slice of it at any
                // offset, and PART 12 §4 rates a span selecting the wrong text
                // worse than no span at all.
                $previousPos = $previous['pos'] ?? null;
                $nodePos = $node['pos'] ?? null;
                $span = is_array($previousPos) && is_array($nodePos)
                    ? self::mergedSpan($previousPos, $nodePos)
                    : null;
                if ($span === null) {
                    unset($merged['pos']);
                } else {
                    $merged['pos'] = $span;
                }
                $out[count($out) - 1] = $merged;

                continue;
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * @param array<mixed> $left
     * @param array<mixed> $right
     *
     * @return array<mixed>|null
     */
    private static function mergedSpan(array $left, array $right): ?array
    {
        if (($left['endOffset'] ?? null) !== ($right['startOffset'] ?? null)) {
            return null;
        }
        foreach (['endLine', 'endColumn', 'endOffset'] as $end) {
            if (isset($right[$end])) {
                $left[$end] = $right[$end];
            }
        }

        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeNode(Node $node): array
    {
        // PART 12 §3 and profiles.md: an autolink and an admonition are their
        // OWN types, not a link/div carrying a flag. This engine models them as
        // the broader class, so the canonical name is what goes on the wire -
        // the same distinction Profile already draws for profile matching.
        $type = Profile::canonicalTypeOf($node);
        // `canonicalTypeOf` answers the PROFILE question - `#tag` is classified
        // as `mention` because the two are one trust class. The wire asks a
        // different question: PART 12 §3 and profiles.md both keep `tag` as its
        // own AST type, so a profile classification must not decide the type
        // this publishes.
        if ($node instanceof Mention && $node->getCssClass() === 'tag') {
            $type = 'tag';
        }
        if ($node instanceof Div && $node->isTyped() && $type === 'div') {
            // A TYPED container is an admonition on the wire, whatever word the
            // author used. `Profile::canonicalTypeOf` answers the profile
            // question and only knows the Tier-1 kinds, so `::: footnotes` and
            // `::: sidebar` came out as a `div` carrying a `kind` the shape has
            // no field for - while the reference publishes both as an
            // `admonition`. profiles.md draws the line at TYPED, not at the
            // built-in list: "an admonition is its own type rather than a div
            // carrying a class", so a profile denying callouts can say so.
            $type = 'admonition';
        }
        if ($node instanceof RawText) {
            // PART 12 §5: a formatter-internal node is not serialized. This one
            // holds markup the parser DECLINED - the `[a][]` of an unresolved
            // reference - which this engine keeps so its writer can reproduce
            // the source verbatim instead of escaping brackets it never
            // interpreted. The reference has no such node: carve-js and carve-rs
            // both publish `["text", "text", "text"]` for `see [a][] here`.
            //
            // So it publishes as `text`, which §1 already licenses - "an
            // implementation whose internals differ MAPS on the way out; it does
            // not export its internals" - and the mapping is one-way on purpose.
            // The live tree still holds `RawText`, so `fmt` is unaffected: it
            // reads the tree it was handed, not a decoded payload. What a
            // consumer loses is the authored form AFTER a round trip through the
            // JSON, `[a][]` coming back as `\[a\]\[\]`, which is the cost §5
            // accepted when it excluded these nodes. The alternative - guessing
            // on decode which text nodes were declined markup - trades a stated
            // loss for a silent one (carve-php#531).
            $type = 'text';
        }
        $encoded = ['type' => $type];
        $reflection = new ReflectionClass($node);
        foreach (self::stateProperties($reflection) as $property) {
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

        $attributes = $node->getAttributes();
        if ($node instanceof Div && $node->isTyped()) {
            // The opener word is published as `kind` and is NOT an attribute the
            // author wrote in a block, so it must not appear in `attrs.classes`
            // as well - carve-js and carve-rs both leave `classes` to what the
            // attribute line contributed (carve-php#552). Dropping the whole
            // `class` attribute when the opener was its only class keeps an
            // empty `classes: []` off the wire.
            $attributes = self::withoutOpenerClass($attributes, self::openerKind($node));
        }
        if ($attributes !== []) {
            $encoded['attrs'] = self::attrsToWire($attributes, $node->getAttributeOrder());
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

        $children = $node->getChildren();
        if ($node instanceof Abbreviation) {
            // `abbr` carries the abbreviation and `expansion` what it stands
            // for; the Text child holds the abbreviation again, and publishing
            // both would be two representations of one string - the same rule
            // a mention already follows. The decoder rebuilds it.
            $children = [];
        }
        if ($node instanceof Mention) {
            // `user` / `name` carry the content; the Text child holds the same
            // thing with its sigil, and publishing both would be two
            // representations of one string. The decoder rebuilds it.
            $children = [];
        }
        if ($type === 'autolink') {
            // The reference gives an autolink no children - `text` is the label,
            // and publishing both would be a second representation of the same
            // content. The decoder rebuilds the text node from it.
            $children = [];
        }
        // An EMPTY container is still published when the reference publishes it
        // unconditionally: a table cell claimed by a neighbour's span marker has
        // no children, and dropping the key made it differ from every other cell
        // in field set rather than in content.
        $container = ReferenceShape::containerFor($type);
        if ($children === [] && in_array($type . '.' . $container, self::ALWAYS_PUBLISHED, true)) {
            $encoded[$container] = [];
        }
        if ($children !== []) {
            $encoded[$container] = self::coalesceTextRuns(array_map(
                fn (Node $child): array => $this->encodeNode($child),
                $children,
            ));
        }

        return self::captionShape(self::spanShape(self::figureShape(self::listMarkerShape($encoded))));
    }

    /**
     * An ordered list's marker is its DELIM, not its bullet character.
     *
     * This engine keeps one `marker` field holding whichever the author wrote,
     * and it was published as `bulletChar` for both kinds - so `1. a` came out
     * as `bulletChar: "."`, a value the schema's `["-", "*"]` does not allow,
     * while `delim` (`[".", ")"]`) went unset. A consumer reading `delim` to
     * reproduce an ordered list got nothing.
     *
     * Both are AUTHOR-CHOICE fields under PART 11 §6, and §11 makes them
     * semantic: a sibling item with a different delimiter starts a NEW list.
     *
     * @param array<string, mixed> $encoded
     *
     * @return array<string, mixed>
     */
    private static function listMarkerShape(array $encoded): array
    {
        if (($encoded['type'] ?? null) !== 'list' || !array_key_exists('bulletChar', $encoded)) {
            return $encoded;
        }
        if (($encoded['ordered'] ?? false) !== true) {
            return $encoded;
        }

        $marker = $encoded['bulletChar'];
        unset($encoded['bulletChar']);
        $encoded['delim'] = $marker;

        return $encoded;
    }

    /**
     * A span marker is `rowspan` or `colspan` on the wire, not `^` or `<`.
     *
     * This engine keeps the MARKER the author typed, which is the right thing
     * to keep - a formatter reproduces the character - and the wrong thing to
     * publish: the schema's enum is `["rowspan", "colspan"]`, and `<` means
     * nothing to a consumer that did not parse Carve.
     *
     * @param array<string, mixed> $encoded
     *
     * @return array<string, mixed>
     */
    private static function spanShape(array $encoded): array
    {
        if (($encoded['type'] ?? null) !== 'table_cell' || !isset($encoded['span'])) {
            return $encoded;
        }

        $named = match ($encoded['span']) {
            '^' => 'rowspan',
            '<' => 'colspan',
            default => null,
        };
        if ($named === null) {
            return $encoded;
        }
        $encoded['span'] = $named;

        return $encoded;
    }

    /**
     * A table's caption is the inline content, not a node wrapping it.
     *
     * Same mapping the figure already gets, and the same reason: this engine
     * models a caption as a block node, and the reference has no such type -
     * `caption` is an array of inline nodes wherever it appears.
     *
     * @param array<string, mixed> $encoded
     *
     * @return array<string, mixed>
     */
    private static function captionShape(array $encoded): array
    {
        $caption = $encoded['caption'] ?? null;
        if (!is_array($caption) || ($caption['type'] ?? null) !== 'caption') {
            return $encoded;
        }

        $encoded['caption'] = $caption['children'] ?? [];

        return $encoded;
    }

    /**
     * Publish a figure as the reference does: a `target` and a `caption`.
     *
     * This engine models a figure as CHILDREN - the thing being captioned,
     * followed by a `caption` block - so the wire carried a `children` array
     * where the reference has two named fields, and a `caption` node type the
     * reference has none of. PART 12 §1: an implementation whose internals
     * differ MAPS on the way out.
     *
     * The tree is untouched, exactly as with the root fields.
     *
     * @param array<string, mixed> $encoded
     *
     * @return array<string, mixed>
     */
    private static function figureShape(array $encoded): array
    {
        if (($encoded['type'] ?? null) !== 'figure' || !is_array($encoded['children'] ?? null)) {
            return $encoded;
        }

        $target = null;
        $caption = null;
        foreach ($encoded['children'] as $child) {
            if (!is_array($child)) {
                continue;
            }
            if (($child['type'] ?? null) === 'caption') {
                // The reference's `caption` is the inline content itself, not a
                // node wrapping it.
                $caption = $child['children'] ?? [];

                continue;
            }
            $target ??= $child;
        }

        if ($target === null) {
            return $encoded;
        }

        unset($encoded['children']);
        $encoded['target'] = $target;
        if ($caption !== null) {
            $encoded['caption'] = $caption;
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

        if ($node instanceof Div && $node->isTyped()) {
            // `::: warning` - the word is the admonition kind, which this engine
            // keeps as a class rather than a field of its own.
            //
            // The FIRST class, not the whole attribute. The parser stores the
            // opener word first and an attribute line appends after it, so
            // `{.x}` above a `::: note` published `kind: "note x"` - a string
            // that is not a kind and matches nothing a consumer can look up
            // (carve-php#552). carve-js and carve-rs publish `kind: "note"`
            // with the extra class left in `attrs.classes`.
            return ['kind' => self::openerKind($node) ?? ''];
        }

        if ($node instanceof Abbreviation) {
            // The reference publishes what the DOCUMENT says: the abbreviation
            // and its expansion. This engine keeps the expansion as the
            // `<abbr title=...>` attribute and the abbreviation as a text
            // child, which is how it RENDERS one.
            return ['abbr' => self::plainText($node)];
        }

        if ($node instanceof Mention) {
            // The reference publishes the NAME, not the rendered label: a
            // mention is `{type, user}` and a tag `{type: "tag", name}`. This
            // engine models both as a Link subclass whose Text child holds the
            // literal `@user` / `#tag`, and whose css class says which.
            $label = self::plainText($node);
            $isTag = $node->getCssClass() === 'tag';

            return [$isTag ? 'name' : 'user' => ltrim($label, $isTag ? '#' : '@')];
        }

        if ($node instanceof ListBlock) {
            return ['ordered' => $node->getListType() === ListBlock::TYPE_ORDERED];
        }

        if ($node instanceof ListItem) {
            return $node->isTask() ? ['checked' => $node->isCompleted()] : [];
        }

        if ($node instanceof TableCell) {
            return ['header' => $node->isHeader()];
        }

        if ($node instanceof Comment) {
            // Which form the author wrote: the fenced `%%%` block, or an inline
            // `%%`. This engine records it as the fence WIDTH, which is a
            // writer's concern and not on the wire; the reference publishes the
            // question a consumer actually asks.
            return ['block' => $node->getFenceLength() !== null];
        }

        return [];
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string, mixed> $data
     */
    private function applyDerivedFields(Node $node, array $data): void
    {
        if ($node instanceof Abbreviation && $node->getChildren() === []) {
            $abbr = $data['abbr'] ?? null;
            if (is_string($abbr) && $abbr !== '') {
                $node->appendChild(new Text($abbr));
            }
        }

        if ($node instanceof Comment && ($data['block'] ?? null) === true) {
            // The wire says WHICH FORM the author wrote, not how wide the fence
            // was. Restoring a floor is enough: the Carve writer widens a fence
            // past any run of `%` in the content anyway, because it has to.
            self::writeProperty($node, 'fenceLength', 3);
        }

        if ($node instanceof Link && ($data['type'] ?? null) === 'autolink') {
            self::writeProperty($node, 'isAutolink', true);
            if ($node->getChildren() === []) {
                $text = $data['text'] ?? null;
                $node->appendChild(new Text(is_string($text) ? $text : (string)$node->getDestination()));
            }

            return;
        }

        if ($node instanceof Mention) {
            $isTag = ($data['type'] ?? null) === 'tag';
            $name = $data[$isTag ? 'name' : 'user'] ?? null;
            self::writeProperty($node, 'cssClass', $isTag ? 'tag' : 'mention');
            self::writeProperty($node, 'destination', '');
            if (is_string($name) && $node->getChildren() === []) {
                $node->appendChild(new Text(($isTag ? '#' : '@') . $name));
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

            return;
        }

        if ($node instanceof ListItem && array_key_exists('checked', $data)) {
            self::writeProperty($node, 'taskMarker', $data['checked'] === true ? 'x' : ' ');

            return;
        }

        if ($node instanceof TableCell && array_key_exists('header', $data)) {
            self::writeProperty($node, 'isHeader', $data['header'] === true);

            return;
        }

        if ($node instanceof Div) {
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
     * `delim` back to the single `marker` field this engine keeps.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function listMarkerFromWire(array $data): array
    {
        if (($data['type'] ?? null) === 'list' && array_key_exists('delim', $data)) {
            // `delim` is this engine's `marker`, which the encoder publishes
            // under whichever name the list kind calls for.
            //
            //
            // A payload written before this codec separated the two carries the
            // DIALECT here (`a`, `i`), and decodes as a marker. Reinterpreting
            // it by value was tried and rejected: the loss check compares the
            // payload against a re-encode, so a `delim` that comes back as
            // `olType` reads as a dropped field and the whole document fails to
            // decode. Silently mis-reading one field beats refusing the
            // document, and that field was never readable by another engine
            // anyway - `a` is not a value `delim` is allowed to hold.
            $data['bulletChar'] = $data['delim'];
            unset($data['delim']);
        }

        return $data;
    }

    /**
     * `rowspan` / `colspan` back to the marker this engine keeps.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function spanFromWire(array $data): array
    {
        if (($data['type'] ?? null) === 'table_cell' && isset($data['span'])) {
            $data['span'] = match ($data['span']) {
                'rowspan' => '^',
                'colspan' => '<',
                default => $data['span'],
            };
        }

        return $data;
    }

    /**
     * A caption array back to the block node this engine models it with.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function captionFromWire(array $data): array
    {
        $caption = $data['caption'] ?? null;
        if (($data['type'] ?? null) === 'table' && is_array($caption) && !isset($caption['type'])) {
            $data['caption'] = ['type' => 'caption', 'children' => $caption];
        }

        return $data;
    }

    /**
     * `target` and `caption` back to the children this engine models a figure
     * with: the thing being captioned, then a `caption` block wrapping the
     * caption's inline content.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function figureFromWire(array $data): array
    {
        if (($data['type'] ?? null) !== 'figure' || !is_array($data['target'] ?? null)) {
            return $data;
        }

        $children = [$data['target']];
        if (is_array($data['caption'] ?? null)) {
            $children[] = ['type' => 'caption', 'children' => $data['caption']];
        }
        unset($data['target'], $data['caption']);
        $data['children'] = $children;

        return $data;
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

        // Undo the wire shapes this codec writes, so a tree it produced decodes
        // back to the tree it came from - which is what PART 12 §6's round trip
        // asks for, and what the loss check verifies. Both were caught by that
        // check rather than by review.
        $data = self::captionFromWire(self::spanFromWire(self::figureFromWire(self::listMarkerFromWire($data))));

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
            $name = ReferenceShape::fieldFor($type, $property->getName()) ?? $property->getName();
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

        /** @var array<string, mixed> $wire */
        $wire = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
        // A typed div's opener word is published as `kind` rather than as a
        // class, so put it back BEFORE the attributes are built: attrsFromWire
        // places `class` ahead of the key/values, which is the order the parser
        // stores and the renderer emits. Re-adding it afterwards put `title`
        // first and round-tripped `42-admonitions-5` to different HTML
        // (carve-php#552).
        if ($node instanceof Div && is_string($data['kind'] ?? null) && $data['kind'] !== '') {
            $classes = is_array($wire['classes'] ?? null)
                ? array_values(array_filter($wire['classes'], 'is_string'))
                : [];
            array_unshift($classes, $data['kind']);
            $wire['classes'] = $classes;
        }
        [$attrs, $order] = self::attrsFromWire($wire);
        if ($attrs !== []) {
            $node->setAttributesWithOrder($attrs, $order);
        }

        $container = ReferenceShape::containerFor($type);
        /** @var array<int, array<string, mixed>> $children */
        $children = is_array($data[$container] ?? null) ? $data[$container] : [];
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
        $default = self::defaultFor(new ReflectionClass($node), $property);

        if (!$default['has']) {
            // A field this codec never PUBLISHES cannot be required on input.
            // `mention` keeps a css class, a destination and a title internally
            // and puts none of them on the wire, so demanding them of a payload
            // asks for something no conformant producer can send. Whatever sets
            // them - a derived field, or the constructor - has already run.
            if (ReferenceShape::fieldFor($nodeType, $property->getName()) === null) {
                return;
            }

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
