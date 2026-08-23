<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use JsonException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Footnote as FootnoteBlock;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\UnresolvedReference;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\Renderer\CrossReferenceResolver;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
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
 * footnote definitions published as block nodes in the tree. The decoder used
 * to adopt the old root fields as well; it does not any more (carve-php#1002).
 * There is one wire shape, and a payload stored under the old one is converted
 * once by {@see \MarkupCarve\Carve\Ast\StoredPayloadUpgrade}.
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
    public const VERSION = 4;

    /**
     * Node types this engine has and the wire does not, and what each PUBLISHES
     * as instead (PART 12 §1 and §5).
     *
     * The class map is built by reflection, so a node class is publishable the
     * day it is added - which is what keeps the codec complete, and what makes
     * an internal one leak by default. All three of these leaked: the published
     * schema advertised them, and two of them REACHED THE WIRE, so this engine
     * encoded types its own decoder refuses and a round trip through its own
     * codec produced a payload it could not read back (carve-php#1002).
     *
     * - `raw_text` is the case §5 names: markup the parser declined, kept so the
     *   writer can reproduce it verbatim. Nothing has produced it since §3a.
     * - `caption` is this engine's wrapper for a figure's or a table's caption.
     *   In both of those positions the encoder already publishes the caption as
     *   the reference does, a FIELD holding inline content, so the node itself
     *   only ever survives the shape passes somewhere the reference has no
     *   caption at all - and there it is a block of inline content, which is a
     *   paragraph.
     * - `section` is a rendering wrapper the parser never builds; the ProseMirror
     *   bridge does. It holds blocks and says nothing else, so the published
     *   container is what it maps to.
     *
     * The mapping happens after the whole tree is encoded, not in `encodeNode`,
     * because `figureShape` and `captionShape` still have to recognise a caption
     * node while they are turning the legitimate ones into fields. `raw_text` is
     * the exception and maps in `encodeNode`: its `content` has to resolve
     * against `text` to be published as `value`.
     *
     * DECODING accepts none of them. Each is refused as a type the vocabulary
     * does not hold, and a payload naming one is upgraded by
     * {@see \MarkupCarve\Carve\Ast\StoredPayloadUpgrade}.
     *
     * @var array<string, string>
     */
    public const NOT_ON_THE_WIRE = [
        'caption' => 'paragraph',
        'raw_text' => 'text',
        'section' => 'div',
    ];

    /**
     * Wire type => the fields this encoder writes BY HAND, keyed by wire type.
     *
     * {@see self::schema()} derives its field lists by reflecting over node
     * properties, which sees every field the property walk in `encodeNode()`
     * publishes and NONE of the fields written outside it. Three code paths
     * write outside it: {@see self::derivedFields()}, which computes a field
     * from state this engine models differently (an admonition's `kind` is a
     * class here, a mention's `user` is the text of a child); the shape passes
     * at the end of `encodeNode()` (`listMarkerShape`, `figureShape`,
     * `captionShape`, `spanShape`); and the retypes at the top of it, which are
     * why `autolink`, `admonition` and `tag` have no node class to reflect over
     * at all.
     *
     * So the schema under-reported eleven types and omitted three outright,
     * while the encoder emitted all of them - a consumer validating a payload
     * against the published map was told a field it had just been sent does not
     * exist. Reflection missing a code path is a check that cannot fail, and
     * naming the path is what lets one fail: `AstCodecSchemaTest` encodes the
     * whole corpus and asserts that every field on the wire is a field the
     * schema names, so a twelfth hand-written field breaks that test rather
     * than quietly widening the gap.
     *
     * DECLARED, not derived, because the values are computed from a node's
     * state and only a real encode can say which appear. The test does the
     * deriving; this is what it checks against.
     *
     * @var array<string, array<string>>
     */
    public const HAND_WRITTEN_FIELDS = [
        'abbreviation' => ['abbr'],
        'admonition' => ['kind'],
        'autolink' => ['text'],
        'comment' => ['block'],
        // `caption` is a FIELD holding inline content, not a child container -
        // the schema requires it on every figure, beside `target`.
        'figure' => ['target', 'caption'],
        'list' => ['ordered', 'delim'],
        'list_item' => ['checked'],
        'mention' => ['user'],
        'table_cell' => ['header'],
        'tag' => ['name'],
    ];

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
        'citation_definition.children', 'citation_definition.key',
        'citation_group.items', 'citation_group.raw',
        'code.value', 'code_block.content', 'comment.block',
        'comment.content', 'critic_comment.text',
        'definition_description.children', 'definition_list.items',
        'definition_term.children',
        'delete.children', 'div.children', 'document.children',
        'document.srcByteLength', 'emphasis.children', 'escaped_text.value',
        'figure.caption', 'figure.target', 'figure_group.children',
        'footnote.children',
        'footnote.label', 'footnote_ref.id',
        'frontmatter.content', 'frontmatter.format',
        'heading.children', 'heading.level', 'heading_ref.target',
        'highlight.children', 'image.alt', 'image.src',
        'inline_extension.content', 'inline_extension.name', 'inline_footnote.inline',
        'insert.children', 'line_block.children', 'link.children',
        'link.href', 'link_reference_definition.href',
        'link_reference_definition.label', 'list.items', 'list.ordered',
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
     * The class map WITHOUT the application classes `register()` added.
     *
     * Kept apart because "is this node one of ours" is a different question
     * from "can this node be built", and two passes over the encoded tree have
     * to stop at a node that is not.
     *
     * @var array<string, class-string<\MarkupCarve\Carve\Node\Node>>|null
     */
    private static ?array $packageTypes = null;

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
        // A crossref publishes the id it RESOLVED to beside the one the author
        // wrote (PART 12 §3a, markup-carve/carve#614). Resolution happens on
        // the render path, and the AST is serialized without rendering - so the
        // narrow pass runs here. It stamps `href` and nothing else: the rest of
        // CrossReferenceResolver rewrites the tree for rendering, which the AST
        // must not show.
        $resolver = new CrossReferenceResolver();
        $resolver->resolveCrossReferenceTargets($document, new HeadingIdTracker());
        // PART 12 §5 serializes a caption number: it is a resolution result "a
        // consumer can recompute", and recomputing it means reimplementing PART
        // 9R. The number was assigned only on the render path, so `caption_number`
        // reached the wire with no `n` at all (carve-php#843).
        //
        // This is the same narrow treatment as the line above - the numbering pass
        // stamps the number and nothing else, where the rest of the resolver
        // rewrites the tree for rendering, which the AST must not show.
        $resolver->resolveNumberedCaptions($document, new HeadingIdTracker());
        // Footnote numbering is the other half of §5, and it lived only in
        // HtmlRenderer's render context - keyed by label in an array, never on the
        // node - so the published tree carried no number at all (carve-php#843).
        self::numberFootnotes($document);
        // A generated heading id is the third §5 result, and the clause names it:
        // "A `heading` whose id was slugged from its text rather than written
        // carries that id in `attrs.id`". It is not recomputable from one
        // subtree - dedup assigns the next free suffix in DOCUMENT ORDER, so
        // `Notes-2` needs every heading before it - which is exactly §5's test.
        // Computed here for the same reason the two passes above are: this
        // engine assigned ids on the render path only, so a published heading
        // carried no `attrs` at all (carve#750).
        self::stampHeadingIds($document);

        // The spans come from the Document rather than the encoded array: they
        // are internal, so `ReferenceShape` keeps them off the wire and the
        // encoder never puts them there.
        return self::mapInternalTypes(self::publishAbbreviationDefs(
            $this->encodeNode($document),
            $document->getAbbreviationSpans(),
        ));
    }

    /**
     * Publish the internal types under the names the vocabulary has (§1, §5).
     *
     * LAST, over the finished tree, because `figureShape` and `captionShape`
     * still have to recognise a `caption` node while they turn the legitimate
     * ones into the inline-content FIELD the reference publishes. By the time
     * this runs, every caption in a position the reference names is a field, so
     * a `caption` node reaching here is one the reference has no home for.
     *
     * `attrs` and `pos` hold named slots rather than nodes, and a `keyValues`
     * entry can be spelled `type` - descending into them would rename an
     * attribute.
     *
     * @param array<mixed> $encoded
     *
     * @return array<mixed>
     */
    private static function mapInternalTypes(array $encoded): array
    {
        $type = $encoded['type'] ?? null;
        if (is_string($type) && self::isApplicationType($type)) {
            // Its state is an array this package did not shape, so a key
            // spelled `type` in there is the application's data. §12(d) leaves
            // the subtree alone for the same reason.
            return $encoded;
        }
        if (is_string($type) && isset(self::NOT_ON_THE_WIRE[$type])) {
            $encoded['type'] = self::NOT_ON_THE_WIRE[$type];
        }

        foreach ($encoded as $key => $value) {
            if ($key === 'attrs' || $key === 'pos' || !is_array($value)) {
                continue;
            }
            $encoded[$key] = self::mapInternalTypes($value);
        }

        return $encoded;
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
        $authored = $encoded['abbreviationDefinitions'] ?? null;
        // All three are ENGINE state, never wire fields: PART 12 §7 puts the
        // definitions in the tree as nodes. Dropped up front so no early
        // return below can leave one on the root.
        unset(
            $encoded['abbreviations'],
            $encoded['abbreviationsBeforeBody'],
            $encoded['abbreviationDefinitions'],
        );
        if (!is_array($abbreviations) || $abbreviations === []) {
            return $encoded;
        }

        // The definitions are NODES in the tree now (carve-php#708), so they
        // encode like any other child and this synthesis would publish each one
        // twice. It stays for a document that has the expansion map without the
        // nodes -- one built through the API, or decoded from an older payload.
        $encodedChildren = is_array($encoded['children'] ?? null) ? $encoded['children'] : [];
        foreach ($encodedChildren as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'abbreviation_def') {
                return $encoded;
            }
        }

        if (!is_array($authored) || $authored === []) {
            $authored = [];
            foreach ($abbreviations as $abbr => $expansion) {
                $authored[] = ['abbr' => (string)$abbr, 'expansion' => $expansion];
            }
        }

        $defs = [];
        foreach ($authored as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $abbr = is_scalar($definition['abbr'] ?? null) ? (string)$definition['abbr'] : '';
            $expansion = $definition['expansion'] ?? '';
            $def = [
                'type' => 'abbreviation_def',
                'abbr' => $abbr,
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
     * The AUTHORED list travels beside the map: one entry per node, so a term
     * defined twice keeps both lines. The map keeps last-wins, which is what
     * resolution reads (PART 9R).
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>, 2: bool, 3: array<int, array<string, string>>}
     */
    private static function liftAbbreviationDefs(array $data): array
    {
        $children = is_array($data['children'] ?? null) ? $data['children'] : [];
        $kept = [];
        $abbreviations = [];
        $authored = [];
        $seenContent = false;
        $beforeBody = true;

        foreach ($children as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'abbreviation_def') {
                $abbr = $child['abbr'] ?? null;
                if (is_scalar($abbr)) {
                    $expansion = $child['expansion'] ?? '';
                    $expansion = is_scalar($expansion) ? (string)$expansion : '';
                    $abbreviations[(string)$abbr] = $expansion;
                    $authored[] = ['abbr' => (string)$abbr, 'expansion' => $expansion];
                    if ($seenContent) {
                        $beforeBody = false;
                    }
                }
            } else {
                // Only a real block counts as body content: the flag records
                // whether every definition sat ahead of the document's prose.
                $seenContent = true;
            }
            $kept[] = $child;
        }

        $data['children'] = $kept;

        return [$data, $abbreviations, $abbreviations !== [] && $beforeBody, $authored];
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
     * @throws \MarkupCarve\Carve\Exception\AstDecodeException When the payload is not a document this version can read.
     */
    public function decode(array $data): Document
    {
        // FIRST, ahead of every other question this method asks. `decodeJson`
        // is bounded for free because `json_decode` takes a depth argument;
        // this entry point is handed a structure somebody else decoded, and
        // everything below here - `refuseRetiredShapes`, `verifyNoUnnamedSlots`,
        // `AstSchema::firstViolation`, `decodeNode` - is plain recursion. A
        // payload past the bound exhausts the C stack, and a segmentation fault
        // is not a depth check failing, it is the absence of one.
        //
        // Ahead of the version envelope too, because a payload that cannot be
        // walked cannot be diagnosed: reporting which spelling it uses means
        // descending into it.
        if (!PayloadDepth::within($data, self::MAX_JSON_DEPTH)) {
            throw new AstDecodeException(sprintf(
                'AST payload nests deeper than %d levels. The parser caps nesting at %d AST '
                    . 'levels, whose deepest wire form stays well inside this bound, so a '
                    . 'payload past it was not produced by parsing a document. This is the '
                    . 'bound `decodeJson()` applies through `json_decode`; the array entry '
                    . 'point applies it by hand because nothing else does.',
                self::MAX_JSON_DEPTH,
                self::MAX_PARSER_NESTING_DEPTH,
            ));
        }

        // What the sender actually had to send, measured HERE and not further
        // down: everything below rewrites `$data` - `liftAbbreviationDefs`
        // moves definitions out of the root - so a later measurement would be
        // asking what this method's own bookkeeping costs rather than what
        // arrived. Used at the end of this method to bound the expansion
        // budgets; see there.
        $payloadBytes = PayloadSize::bytes($data, self::MAX_JSON_DEPTH);

        $version = $data['ast'] ?? null;
        if ($version !== null && $version !== self::VERSION) {
            throw new AstDecodeException(sprintf(
                'This payload announces AST encoding version %s; this codec writes %d. Version 1 used '
                    . "this engine's internal field names, which version 2 maps to the PART 12 "
                    . 'reference shape (`content` became `value`, a list\'s `children` became `items`).',
                is_scalar($version) ? (string)$version : get_debug_type($version),
                self::VERSION,
            ));
        }

        // PART 12 §12(a): a root missing any of §7's three fields is refused,
        // not defaulted. Checked HERE and not further down, because everything
        // below rewrites `$data` - `liftAbbreviationDefs` rewrites `children` -
        // so a later check would be asking about a payload this method wrote
        // rather than the one the caller handed over.
        //
        // AFTER the version envelope and only when the root already claims to be
        // a `document`. A version-1 payload and a ProseMirror `doc` are both
        // already ruled elsewhere (§9's root-type paragraph for the second), and
        // both deserve to hear which of those they are rather than a report about
        // a field a foreign format was never going to carry.
        //
        // `array_key_exists` rather than `isset`: (a) is about the field being
        // PRESENT, the VALUE of `srcByteLength` is explicitly not this clause's
        // business, and `isset` would read a null `children` as absent and cite
        // the wrong rule.
        if (($data['type'] ?? null) === 'document') {
            foreach (['children', 'srcByteLength'] as $required) {
                if (!array_key_exists($required, $data)) {
                    throw new AstDecodeException(sprintf(
                        'The payload root is missing `%s`. PART 12 §7 fixes the root at `type`, '
                            . '`children` and `srcByteLength`, and §12 refuses a root without one - '
                            . 'a reader that supplies a default has silently repaired the payload.',
                        $required,
                    ));
                }
            }
        }

        // The five pre-PART 12 §7 spellings this codec used to normalize on the
        // way in (carve-php#1002). They are refused now, and each one is a
        // shape the schema would refuse anyway - a root field §7 does not name,
        // a `footnote` missing `label`, a type the vocabulary does not hold. It
        // is asked FIRST so the answer names the spelling and the one-shot
        // migration rather than reporting the same payload as an anonymous
        // schema violation a reader cannot act on.
        //
        // None of the sixteen rows §12(d) tabulates carries one of these, so
        // this cannot answer in their place - PayloadIsValidatedAgainstTheSchema
        // Test measures that rather than assuming it.
        self::refuseRetiredShapes($data);

        // PART 12 §11, and on the payload as the CALLER wrote it: everything
        // below rewrites `$data`, and a check asking afterwards would be asking
        // about a payload this method produced. Before the decode as well as
        // before the loss comparison, so a tree carrying a property the format
        // does not have is refused rather than half-read.
        self::verifyNoUnnamedSlots($data);

        // PART 12 §12(d): the WHOLE payload against `resources/ast-schema.json`,
        // types and required fields together, refused with the same typed error
        // (a), (b) and (c) already require (markup-carve/carve#881).
        //
        // ON THE PAYLOAD AS THE CALLER WROTE IT, for the reason §11's pass gives
        // one line up: everything below rewrites `$data`. LAST of the three, so
        // the clauses with a message of their own - a version envelope, a root
        // missing one of §7's fields, an unnamed slot - still answer first and
        // say which rule the payload broke rather than where in the schema it
        // stopped matching.
        //
        // AND ONLY ONCE THE ROOT CLAIMS TO BE A CARVE DOCUMENT, which is how
        // §12(a) scopes itself one block up and for the same reason: a
        // ProseMirror `doc` fails the schema at `$` for want of `children`, and
        // reporting that would tell a foreign payload about a field it was
        // never going to carry instead of telling it that it is foreign.
        if (($data['type'] ?? null) === 'document') {
            self::verifySchema($data);
        }

        // PART 12 §21, and BEFORE every read of a VALUE below - before an
        // abbreviation half is joined into a pair key, before a label becomes
        // an array key, before anything reaches a renderer, which are the three
        // readings the clause names.
        //
        // THE PARSE BOUNDARY ALREADY DOES THIS: `BlockParser` replaces an
        // authored NUL with U+FFFD before the first line of a document is read,
        // and PART 9 §29 carves the character out of the content class on that
        // basis. The AST is a SECOND DOOR into the same renderers and it had no
        // equivalent, so an authored NUL and an ingested one stood on different
        // footings - one replaced, one content - which is the divergence §29
        // exists to remove.
        //
        // THE SUBJECT IS THE DECODED VALUE, not the bytes of a JSON document.
        // RFC 8259 forbids an unescaped U+0000 inside a string, so a raw byte in
        // JSON text is a `JsonException: Control character error` that
        // `decodeJson()` raises before any Carve rule is reached, and stays one.
        // What reaches here is the `\u0000` escape, or a string a host built in
        // memory and handed to this method directly - and THIS entry point takes
        // an array, so it has no JSON layer at all, which is why the clause is
        // stated on the value.
        //
        // NOT A REFUSAL, unlike §11's unnamed slot and §12's deviant root. Those
        // are structure a producer got wrong. This is the opposite case: the
        // replacement is what the parse boundary already does to the identical
        // string, so performing it is the documented reading rather than a
        // repair, and refusing would make an ingested document stricter than the
        // same document written as source.
        //
        // AFTER THE REFUSALS ABOVE, which costs §21 nothing: they read STRUCTURE
        // - a version envelope, a field name, a value's kind - and no refusal
        // outcome turns on this character, since a type spelled with a NUL is
        // not a known type and is not one with the NUL replaced either. It is
        // BEFORE `verifyNothingWasLost()`, which compares the payload against
        // the decoded node: normalizing after that comparison would report the
        // engine's own replacement as a lost value.
        $data = self::replaceNulValues($data);

        // Read BEFORE the walk: the definitions drive expansion, which is
        // engine state on the document rather than anything the block nodes
        // carry. The nodes themselves stay in `children` and decode like any
        // other block, so a tree survives the round trip (PART 12 §6).
        [$data, $abbreviations, $beforeBody, $authoredAbbreviations] = self::liftAbbreviationDefs($data);

        $node = $this->decodeNode($data);
        if (!$node instanceof Document) {
            throw new AstDecodeException('The payload root must be a document node');
        }

        // What the payload COST, recorded beside what it CLAIMS.
        // `srcByteLength` is kept exactly as written - PART 12 §7 makes it a
        // field of the payload, and a reader that rewrites it has silently
        // repaired the record - but the expansion budgets may not be sized from
        // it alone, because it arrives inside the payload and a hostile tree can
        // claim any number it likes for the price of the digits
        // (carve-php#1052). `Document::getExpansionBudgetLength()` takes the
        // smaller of the two.
        //
        // Recorded ONLY where it binds, so that a document decoded from this
        // engine's own output stays identical to the one that was encoded -
        // `RootFieldsTest::testBothSurviveEncodeDecodeRoundTrip` pins that, and
        // an encoded tree is several times the size of the source it came from,
        // so an honest payload never reaches this branch.
        if ($payloadBytes < $node->getSourceLength()) {
            $node->setIngestPayloadLength($payloadBytes);
        }

        if ($abbreviations !== []) {
            $node->setAbbreviations($abbreviations);
            $node->setAbbreviationDefinitions($authoredAbbreviations);
            $node->setAbbreviationsBeforeBody($beforeBody);
        }

        $this->verifyNothingWasLost($data, $node);
        self::resolveFootnoteRefs($node);

        return $node;
    }

    /**
     * Give every heading the id the renderer would give it, unless the source
     * wrote one.
     *
     * The id takes no `order` slot: `order` is the source-appearance order of
     * the slots in a `{#id .class key=value}` block, and a slugged id was never
     * written in one. An AUTHORED id keeps both its value and its slot.
     */
    private static function stampHeadingIds(Document $document): void
    {
        $tracker = new HeadingIdTracker();
        $walk = function (Node $node) use (&$walk, $tracker): void {
            if ($node instanceof Heading) {
                $id = $tracker->getIdForHeading($node);
                if ($id !== '' && $node->getAttribute('id') === null) {
                    $node->setSynthesizedAttribute('id', $id);
                }
            }
            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };
        $walk($document);
    }

    /**
     * Assign footnote numbers for the published tree.
     *
     * PART 12 §5 serializes them: a consumer cannot recompute a footnote number
     * without reimplementing PART 9R. The rule is the renderer's - first USE
     * order, a repeat reusing its number, an unresolved reference left unnumbered
     * because it never formed a footnote at all.
     *
     * AN INLINE FOOTNOTE TAKES A NUMBER FROM THE SAME SEQUENCE. carve-js and
     * carve-rs both number `[^a] ^[x] [^a] ^[y]` as 1, 2, 1, 3; counting only
     * references would disagree with both and with this engine's own HTML.
     *
     * A DEFINITION BODY IS NOT WALKED WHERE IT SITS. The definitions are hoisted
     * to the end of the tree in SOURCE order, so numbering them where they lie
     * numbers a note in the body of the first-defined footnote before one in the
     * body of the first-USED footnote, and numbers the body of a definition that
     * is never referenced at all - a body that renders nowhere. Both disagree
     * with this engine's own HTML, which walks the endnotes in use order and
     * emits nothing for an unreferenced definition. So the bodies are deferred
     * into a queue keyed on first use, exactly as carve-js does, and only a
     * referenced definition ever joins it.
     *
     * An inline note's own content is never searched for footnotes: a note
     * inside a note has no rendered home. Parsing cannot build one (`[^b]`
     * inside `^[...]` stays text), but a decoded tree can carry one.
     *
     * AN UNRESOLVED REFERENCE'S TEXT IS NOT SEARCHED EITHER. PART 9R R1
     * degrades such a reference to its literal source, so the text it holds is
     * discarded rather than written into the document, and R2 rules that a note
     * in that text "is not a reference": it gets no number, no endnote and no
     * backlink (markup-carve/carve#1198). `HtmlRenderer::renderLink()` already
     * returns the raw source BEFORE rendering its children, so nothing in there
     * is rendered - numbering it anyway published a number naming a footnote the
     * page does not contain, and in `a [t[^1]][nope] b [^1] c` it published the
     * SAME number as the one live noteref a reader can see.
     */
    private static function numberFootnotes(Document $document): void
    {
        $definitions = [];
        foreach ($document->getChildren() as $child) {
            // First definition wins, the same tie-break the renderer applies.
            if ($child instanceof FootnoteBlock && !isset($definitions[$child->getLabel()])) {
                $definitions[$child->getLabel()] = $child;
            }
        }

        $next = 1;
        $indexes = [];
        /** @var array<int, \MarkupCarve\Carve\Node\Block\Footnote> $pending bodies to walk, in first-use order */
        $pending = [];
        $walk = static function (Node $node) use (&$walk, &$next, &$indexes, &$pending, $definitions): void {
            if (UnresolvedReference::sourceOf($node) !== null) {
                // CLEARED, not skipped, for the same reason the unresolved
                // reference below is: the subtree renders nowhere, so a number
                // carried in from the wire would name a footnote that is not in
                // the document.
                self::clearFootnoteNumbers($node);

                return;
            }

            if ($node instanceof InlineFootnote) {
                $node->setNumber($next);
                $next++;

                return;
            }

            if ($node instanceof FootnoteRef) {
                // `isUnresolved()` and nothing else: it is the predicate
                // `HtmlRenderer::renderFootnoteRef()` reads, so the published
                // number cannot disagree with the rendered page.
                if ($node->isUnresolved()) {
                    // CLEARED, not skipped. The reference renders as its literal
                    // source now, so a number carried in from the wire would name
                    // a footnote that is no longer in the document (carve-js#698).
                    $node->setNumber(null);

                    return;
                }

                $label = $node->getLabel();
                if (!isset($indexes[$label])) {
                    $indexes[$label] = $next;
                    $next++;
                    // Queued on FIRST USE, which is what puts the bodies in use
                    // order rather than the order the definitions were hoisted in.
                    if (isset($definitions[$label])) {
                        $pending[] = $definitions[$label];
                    }
                }
                $node->setNumber($indexes[$label]);

                return;
            }

            if ($node instanceof FootnoteBlock) {
                return;
            }

            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };

        $walk($document);

        // The queue GROWS while it is drained: a body may cite a footnote
        // referenced nowhere else, which then takes the next number and has its
        // own body walked in turn.
        while ($pending !== []) {
            $body = array_shift($pending);
            foreach ($body->getChildren() as $child) {
                $walk($child);
            }
        }
    }

    /**
     * Drop every footnote number inside a subtree that renders nowhere.
     *
     * The parse path never puts one there, but a decoded tree can arrive with
     * one already set, and this engine republishes what it decodes. Both note
     * spellings are cleared - a `[^label]` use and an `^[content]` note are the
     * two things PART 9R R2 names - and the walk keeps descending, because the
     * discarded text can hold a whole nested construct.
     */
    private static function clearFootnoteNumbers(Node $node): void
    {
        if ($node instanceof FootnoteRef || $node instanceof InlineFootnote) {
            $node->setNumber(null);
        }

        foreach ($node->getChildren() as $child) {
            self::clearFootnoteNumbers($child);
        }
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
     * @throws \MarkupCarve\Carve\Exception\AstDecodeException When decoding dropped content the input carried.
     */

    /**
     * PART 12 §7's shape, and only it: refuse the five pre-§7 spellings this
     * codec used to normalize on the way in, and the node types this engine
     * used to publish that the vocabulary has never held.
     *
     * Every one of them is refused by the schema too - §7 fixes the root at
     * three fields, `label` is required on a `footnote`, and `raw_text`,
     * `caption` and `section` are types it does not list - so this adds no NEW
     * refusal. What it adds is the report: the spelling by name, and the one
     * command that converts a stored payload. Told only that a payload "does
     * not satisfy the AST schema", an application holding documents written by
     * an older version of this package would have to work out for itself that
     * the fix is a rewrite rather than a re-parse.
     *
     * @param array<mixed> $payload
     *
     * @throws \MarkupCarve\Carve\Exception\AstDecodeException
     */
    private static function refuseRetiredShapes(array $payload): void
    {
        $children = $payload['children'] ?? null;
        if (!is_array($children) || !array_is_list($children)) {
            // A root with no usable `children` is refused by §12(a) or (d), and
            // the upgrade cannot place a node in something that is not a list -
            // so answering here would send a caller to a migration that returns
            // the payload unchanged, and the second decode would say the same
            // thing again. Let the clause that can be acted on answer.
            return;
        }

        $found = StoredPayloadUpgrade::retiredShapesIn($payload);
        if ($found === []) {
            return;
        }

        throw new AstDecodeException(sprintf(
            'The payload carries %s, which this engine no longer reads. PART 12 §7 fixes the '
                . 'wire shape and §12(d) validates a payload as written, so a spelling that '
                . 'predates §7 is refused rather than normalized on the way in, and a node type '
                . 'the vocabulary has never held is refused rather than read back. Convert a '
                . 'stored payload once with %s::upgrade(), which needs the payload only and not '
                . 'the source it was parsed from.',
            implode(', ', $found),
            StoredPayloadUpgrade::class,
        ));
    }

    /**
     * @param array<string, mixed> $input
     * @param \MarkupCarve\Carve\Node\Document $document
     *
     * @throws \MarkupCarve\Carve\Exception\AstDecodeException
     */
    private function verifyNothingWasLost(array $input, Document $document): void
    {
        $lost = [];
        $this->compareNode($input, $this->encodeNode($document), '', $lost);

        if ($lost !== []) {
            throw new AstDecodeException(sprintf(
                'Decoding lost %d field(s) the payload carried: %s. The payload may come from an '
                    . 'engine whose field names differ; this decoder reads the PART 12 shape.',
                count($lost),
                implode(', ', array_slice($lost, 0, 6)),
            ));
        }
    }

    /**
     * The slots the AST schema names inside a node's two structured sub-objects.
     *
     * Mirrors `attrs` and `pos` in `resources/ast-schema.json`, both of which
     * carry `additionalProperties: false`. Kept as a constant rather than read
     * from the schema at runtime: the schema ships in the spec repo, which this
     * package pins as a TEST fixture and does not install at runtime.
     * `AstCodecSchemaTest` compares the two, so a slot added upstream cannot
     * drift past this list unnoticed.
     *
     * @var array<string, list<string>>
     */
    private const SCHEMA_NAMED_SLOTS = [
        'attrs' => ['id', 'classes', 'keyValues', 'order'],
        'pos' => ['startLine', 'endLine', 'startColumn', 'endColumn', 'startOffset', 'endOffset'],
    ];

    /**
     * PART 12 §12(d): refuse a payload the AST schema does not describe.
     *
     * The schema is the list. Every row this closes was divergent only because
     * nothing consulted it: a `srcByteLength` that was a string, a `children`
     * that was null and read as an empty document, a `text.value` that was the
     * number 7 and rendered `<p>7</p>`, and a child that was `null` or a string
     * and came back as a bare `TypeError` - untyped, which §9(b) forbids.
     *
     * AN APPLICATION TYPE REGISTERED WITH `register()` IS EXEMPT. The schema
     * names the types PART 9 §8 makes spec surface, and cannot name one this
     * package has never heard of, so §12(d) has nothing to decide about it -
     * see `AstSchema::firstViolation()`. The exemption is by name and only for
     * types actually registered, so a core type cannot be smuggled through it.
     *
     * @param array<mixed> $payload
     *
     * @throws \MarkupCarve\Carve\Exception\AstDecodeException
     */
    private static function verifySchema(array $payload): void
    {
        // REGISTERED APPLICATION TYPES ONLY. `NOT_ON_THE_WIRE` used to be exempt
        // here as well, which is what let a `raw_text` payload through §12(d);
        // the node is this engine's own and the wire has never had it, so an
        // ingest refuses it like any other unlisted type (carve-php#1002).
        $violation = AstSchema::firstViolation($payload, array_keys(self::$registered));
        if ($violation === null) {
            return;
        }

        // A TYPE THE SCHEMA DOES NOT LIST keeps the guidance the dedicated
        // check used to carry: the vocabulary is spec surface (PART 9 §8), so
        // the answer for an application's own node is to register the class,
        // not to relax the schema.
        $hint = str_contains($violation, '.type is the string')
            ? sprintf(' Application node types must be registered with %s::register().', self::class)
            : '';

        throw new AstDecodeException(sprintf(
            'The payload does not satisfy the AST schema: %s. PART 12 §12(d) validates the whole '
                . 'payload against `resources/ast-schema.json` - types and required fields together '
                . '- at decode, because a reader that supplies a default has silently repaired the '
                . 'payload and one that reads a wrong type has silently reinterpreted it.%s',
            $violation,
            $hint,
        ));
    }

    /**
     * Replace every U+0000 with U+FFFD in every string value of a payload.
     *
     * PART 12 §21. Values only: a NUL in a KEY is a slot the format does not
     * name, and `verifyNoUnnamedSlots()` has already refused the payload for it.
     *
     * WHAT IT MAKES SAFE HERE. `ConsumedAbbreviationDefinitions` joins a term
     * and an expansion on a NUL, on the premise that "both come from source
     * text that the writers strip control characters out of" - true of the parse
     * path and false of this one. Through the ingest, `("A" NUL "b", "c")` and
     * `("A", "b" NUL "c")` keyed identically, and rendering a document holding
     * both definitions with an occurrence of only the first DROPPED the second
     * definition line, deleting the author's text - the loss PART 11 §10f's
     * two-pass design exists to prevent. The separator does not have to change
     * once the character cannot arrive.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function replaceNulValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                if (str_contains($value, "\0")) {
                    $data[$key] = str_replace("\0", "\u{FFFD}", $value);
                }

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::replaceNulValues($value);
            }
        }

        return $data;
    }

    /**
     * PART 12 §11: refuse a property the schema does not name, saying which one
     * and where.
     *
     * The node's OWN keys are answered by the loss comparison, which reports a
     * key the re-encode did not reproduce. That comparison cannot see inside
     * `attrs` and `pos`, and must not be made to: `attrs` is NORMALIZED on the
     * way back out (a `class` slot re-encodes as `classes`), so comparing it
     * against the re-encode would report a wrong TYPE as an unnamed property,
     * and a wrong type is §11's neighbour rather than §11 itself
     * (markup-carve/carve#881). Names are therefore checked BY NAME.
     *
     * Walks the payload the CALLER handed over, before `decode()` rewrites it -
     * `liftAbbreviationDefs` rebuilds `children`, and a pass asking afterwards
     * would be asking about a payload this class wrote.
     *
     * @param array<mixed> $payload
     *
     * @throws \MarkupCarve\Carve\Exception\AstDecodeException
     */
    private static function verifyNoUnnamedSlots(array $payload): void
    {
        $unnamed = [];
        self::collectUnnamedSlots($payload, '', $unnamed);

        if ($unnamed === []) {
            return;
        }

        throw new AstDecodeException(sprintf(
            'The payload carries %d propert%s the AST schema does not name: %s. PART 12 §11 pins '
                . 'the wire shape with `additionalProperties: false` at every node, so an ingest '
                . 'refuses an unnamed property rather than dropping it or passing it through. '
                . '`attrs` is `{id, classes, keyValues, order}`; `pos` is `{startLine, endLine, '
                . 'startColumn, endColumn, startOffset, endOffset}`.',
            count($unnamed),
            count($unnamed) === 1 ? 'y' : 'ies',
            implode(', ', array_slice($unnamed, 0, 6)),
        ));
    }

    /**
     * @param array<mixed> $data
     * @param string $path
     * @param array<string> $unnamed
     */
    private static function collectUnnamedSlots(array $data, string $path, array &$unnamed): void
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : null;
        $here = $type === null ? $path : ($path === '' ? $type : $path . '.' . $type);

        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $step = $here === '' ? (string)$key : $here . '.' . $key;

            if (isset(self::SCHEMA_NAMED_SLOTS[$key])) {
                foreach (array_keys($value) as $slot) {
                    if (!in_array((string)$slot, self::SCHEMA_NAMED_SLOTS[$key], true)) {
                        $unnamed[] = $step . '.' . $slot;
                    }
                }

                continue;
            }

            self::collectUnnamedSlots($value, $step, $unnamed);
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
            // NOTHING HERE EXCUSES A PRE-§7 SPELLING ANY MORE. Two branches
            // used to: a `footnote` keyed `id`, whose re-encode carries `label`
            // instead, and a node type the wire does not have, whose fields
            // have no counterpart once the encoder maps it. Both payloads are
            // refused up front now (carve-php#1002), so both branches had become
            // conditions no input could satisfy.

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
                throw new AstDecodeException(sprintf(
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
     * Is `$type` an application's own node rather than one of this package's?
     *
     * PART 12 §12(d) already answers this question once: a type registered with
     * `register()` and ITS SUBTREE are outside the schema by construction, so
     * the rule has nothing to decide about them. The passes that rewrite an
     * encoded tree - publishing an internal type under a vocabulary name, and
     * the stored-payload upgrade - draw the same line, and for the same reason:
     * an application node's state is an array whose keys this package did not
     * choose, so a key spelled `type` there is data rather than a node
     * (carve-php#1002).
     *
     * Asked by WIRE name, so a canonical spelling this engine maps to
     * (`autolink` for a `link`, `admonition` for a typed `div`) is answered as
     * the package type it is.
     */
    public static function isApplicationType(string $type): bool
    {
        self::classMap();

        return !isset(self::$packageTypes[ReferenceShape::classTypeFor($type)]);
    }

    /**
     * The encodable fields per node type, and which of them a payload must
     * carry, for documentation and drift tests.
     *
     * A field is required when the node has no default for it - neither a
     * declared property default nor a constructor parameter default - so there
     * is nothing to fall back on when it is omitted.
     *
     * DERIVED OVER THE WIRE VOCABULARY, not over the class map. The class map is
     * built by reflection and is keyed by `Node::getType()`, so it holds this
     * engine's INTERNAL name for every node - and three wire types have no class
     * of their own: `encodeNode()` narrows a bare-URL `Link` to `autolink`, a
     * typed `Div` to `admonition`, and a `Mention` carrying the `tag` class to
     * `tag`. Reflection cannot see a retype, so all three were emitted by the
     * encoder and absent from the schema it published: a consumer validating
     * against this map was told an `admonition` is not a node type, while its
     * own copy of the encoder produced them (carve-php#1002 fixed the opposite
     * direction, for `caption` and `section`).
     *
     * {@see \MarkupCarve\Carve\Ast\ReferenceShape::TYPE_ALIASES} is the ONE
     * declaration of that narrowing, and both the encoder and this derivation
     * read it, so a fourth alias cannot reach the wire without reaching the
     * schema. Fields resolve under the WIRE name, exactly as `encodeNode()`
     * resolves them, or an `autolink` would advertise `destination` where the
     * encoder writes `href`.
     *
     * @return array<string, array{fields: array<string>, required: array<string>}>
     */
    public static function schema(): array
    {
        $classes = self::classMap();
        foreach (ReferenceShape::TYPE_ALIASES as $wireType => $classType) {
            if (isset($classes[$classType])) {
                $classes[$wireType] = $classes[$classType];
            }
        }

        $schema = [];
        foreach ($classes as $type => $class) {
            if (isset(self::NOT_ON_THE_WIRE[$type])) {
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
            // The fields written outside the property walk. None is REQUIRED:
            // each is computed from state the node already carries, so a
            // payload that omits it is repaired from that state rather than
            // being incomplete.
            foreach (self::HAND_WRITTEN_FIELDS[$type] ?? [] as $field) {
                if (!in_array($field, $fields, true)) {
                    $fields[] = $field;
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

        // AN ID WITH NO `#id` SLOT IS A GENERATED ONE, and a generated
        // attribute is emitted AFTER the authored ones - which is where the
        // renderer puts it when it assigns the id itself. Decoding it into the
        // map's first position instead rendered `<h1 id="Auto" a="b">` where a
        // fresh parse renders `<h1 a="b" id="Auto">`: same attributes, same
        // values, different bytes, and the round-trip test reports it as lost
        // HTML (carve#750).
        if (isset($attrs['id']) && !in_array('#id', $order, true)) {
            $generatedId = $attrs['id'];
            unset($attrs['id']);
            $attrs['id'] = $generatedId;
        }

        if ($order === []) {
            // NO ORDER ON THE WIRE MEANS NO ORDER, not "the order this map
            // happens to iterate in". The schema calls `order` the
            // source-appearance order of the slots and says it is absent on a
            // programmatically built tree, so inventing one turns "unknown"
            // into a positive claim about a block the author may never have
            // written - and it round-trips as a DIFFERENT tree from the one
            // that was encoded (carve#785).
            return [$attrs, []];
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
     * What is left for it is a tree the parser did not build: an extension or
     * the ProseMirror bridge can hand the encoder adjacent `Text` nodes, and
     * §1a is a property of the WIRE, so the run is joined on the way out
     * whatever produced it. Until §3a there was a second case - `RawText`
     * published as `text` and so formed a published run the tree pass had to
     * leave alone - and it is gone: nothing produces that node any more.
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
            // Nothing PRODUCES this node since §3a - an unresolved reference is
            // a link now - but the class is public, so an application or an
            // extension can still build one, and publishing it would put an
            // internal type on the wire: `raw_text` is not in the vocabulary,
            // so the document would be schema-invalid the moment it was saved.
            // PART 12 §5 excludes the node, §1 licenses the mapping ("an
            // implementation whose internals differ MAPS on the way out").
            //
            // HERE rather than in the pass over the finished tree, unlike the
            // other two internal types: the field walk below resolves `content`
            // against the type, and only `text` renames it to `value`.
            $type = self::NOT_ON_THE_WIRE['raw_text'];
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

            // AUTHORED alignment reaches the wire; INHERITED alignment does
            // not. A column declares its alignment once, in the delimiter row
            // or in the header cells' own markers, so republishing the resolved
            // value on every body cell states as per-cell something the source
            // says once - and leaves a consumer unable to tell the two apart
            // (carve#784).
            //
            // A body cell that carries its OWN marker (`|< 12`) still
            // publishes, and so does every cell of a HEADERLESS table, where
            // there is no header row to carry the column's value. Both are
            // authored, and both are what carve-js and carve-rs publish.
            //
            // The node keeps its alignment either way - this engine's renderer
            // aligns body cells from their own nodes - and `applyDerivedFields`
            // copies the column's value back down on decode.
            if (
                $field === 'align'
                && $node instanceof TableCell
                && !$node->isHeader()
                && self::inheritsColumnAlignment($node)
            ) {
                continue;
            }
            if (
                $field === 'valign'
                && $node instanceof TableCell
                && !$node->isHeader()
                && !$node->hasExplicitVerticalAlignment()
            ) {
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

        return self::citationShape(self::captionShape(self::spanShape(self::figureShape(self::listMarkerShape($encoded)))));
    }

    /**
     * The `[+@...]` group marker is `mode: "integral"` on the wire, absent when
     * parenthetical - the shape `$defs.citation_group` pins with
     * `additionalProperties: false`. This engine keeps a boolean `integral`,
     * which reached the wire under its internal name and made the codec's own
     * output schema-invalid: `decode(encode(x))` threw for every integral
     * group (carve-php#1285).
     *
     * @param array<string, mixed> $encoded
     *
     * @return array<string, mixed>
     */
    private static function citationShape(array $encoded): array
    {
        if (($encoded['type'] ?? null) !== 'citation_group' || !array_key_exists('integral', $encoded)) {
            return $encoded;
        }

        if ($encoded['integral'] === true) {
            $encoded['mode'] = 'integral';
        }
        unset($encoded['integral']);

        return $encoded;
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
                // The kind class is STRUCTURAL - the parser derives it from the
                // opener word and records no source slot for it - so writing it
                // must not add one either. setAttribute() records `.class`
                // unconditionally, which appended a slot the parsed tree does
                // not have and made `decode(encode(parse(x)))` differ from
                // `parse(x)` for `42-admonitions-5` (PART 12 §6): an attribute
                // line carrying `title=` plus an opener title came back with
                // `order` = `["title", ".class"]` against the parser's
                // `["title"]`. An AUTHORED class still names its own slot,
                // because that slot arrives in the wire's `order` and is
                // restored below.
                $order = $node->getAttributeOrder();
                $node->setAttribute('class', $kind);
                $node->setAttributeOrder($order);
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
            $node->setExplicitAlignment(array_key_exists('align', $data));
            self::writeProperty($node, 'hasExplicitVerticalAlignment', array_key_exists('valign', $data));

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

        if ($node instanceof Table) {
            // A column's alignment is on the wire ONCE, on the header cells,
            // because that is where the delimiter row declares it (carve#784).
            // This engine's renderer aligns each body cell from its own node,
            // so the column's value is copied back down here - the same
            // recomputation a consumer performs, rather than a field the
            // reference does not have.
            $columns = [];
            $verticalColumns = [];
            foreach ($node->getChildren() as $row) {
                if (!$row instanceof TableRow || !$row->isHeader()) {
                    continue;
                }
                $index = 0;
                foreach ($row->getChildren() as $cell) {
                    if ($cell instanceof TableCell) {
                        $columns[$index] = $cell->getAlignment();
                        $verticalColumns[$index] = $cell->getVerticalAlignment();
                        $index++;
                    }
                }

                break;
            }
            if ($columns !== []) {
                foreach ($node->getChildren() as $row) {
                    if (!$row instanceof TableRow || $row->isHeader()) {
                        continue;
                    }
                    $index = 0;
                    foreach ($row->getChildren() as $cell) {
                        if (!$cell instanceof TableCell) {
                            continue;
                        }
                        if ($cell->getAlignment() === TableCell::ALIGN_DEFAULT && isset($columns[$index])) {
                            self::writeProperty($cell, 'alignment', $columns[$index]);
                        }
                        if ($cell->getVerticalAlignment() === TableCell::VALIGN_DEFAULT && isset($verticalColumns[$index])) {
                            $cell->setVerticalAlignment($verticalColumns[$index], false);
                        }
                        $index++;
                    }
                }
            }
        }
    }

    /**
     * True when a body cell's alignment is its COLUMN's, taken from the header
     * row, rather than one the author wrote on the cell itself.
     *
     * A cell with no header row above it inherits nothing - a headerless table
     * carries its alignment on the cells that state it, which is where the
     * author wrote it.
     */
    private static function inheritsColumnAlignment(TableCell $cell): bool
    {
        if ($cell->hasExplicitAlignment()) {
            return false;
        }

        $row = $cell->getParent();
        if (!$row instanceof TableRow) {
            return false;
        }
        $table = $row->getParent();
        if (!$table instanceof Table) {
            return false;
        }

        $column = 0;
        foreach ($row->getChildren() as $sibling) {
            if ($sibling === $cell) {
                break;
            }
            if ($sibling instanceof TableCell) {
                $column++;
            }
        }

        foreach ($table->getChildren() as $candidate) {
            if (!$candidate instanceof TableRow || !$candidate->isHeader()) {
                continue;
            }
            $index = 0;
            foreach ($candidate->getChildren() as $headerCell) {
                if (!$headerCell instanceof TableCell) {
                    continue;
                }
                if ($index === $column) {
                    return $headerCell->getAlignment() === $cell->getAlignment();
                }
                $index++;
            }

            return false;
        }

        return false;
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
     * `mode: "integral"` back to the boolean this engine keeps. See
     * citationShape() for the outbound half.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function citationFromWire(array $data): array
    {
        if (($data['type'] ?? null) === 'citation_group' && array_key_exists('mode', $data)) {
            $data['integral'] = $data['mode'] === 'integral';
            unset($data['mode']);
        }

        return $data;
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
        // A table's caption and a composite figure's GROUP caption (PART 9
        // §4c) are both inline content on the wire and a Caption block here.
        if (
            in_array($data['type'] ?? null, ['table', 'figure_group'], true)
            && is_array($caption)
            && !isset($caption['type'])
        ) {
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
     * @throws \MarkupCarve\Carve\Exception\AstDecodeException When the node type is unknown.
     */
    private function decodeNode(array $data): Node
    {
        $type = $data['type'] ?? null;
        if (!is_string($type)) {
            throw new AstDecodeException('Every node needs a string type');
        }

        // Undo the wire shapes this codec writes, so a tree it produced decodes
        // back to the tree it came from - which is what PART 12 §6's round trip
        // asks for, and what the loss check verifies. Both were caught by that
        // check rather than by review.
        $data = self::citationFromWire(self::captionFromWire(self::spanFromWire(self::figureFromWire(self::listMarkerFromWire($data)))));

        $class = self::classMap()[ReferenceShape::classTypeFor($type)] ?? null;
        if ($class === null) {
            throw new AstDecodeException(sprintf(
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
     * @throws \MarkupCarve\Carve\Exception\AstDecodeException When a required field is missing.
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

            throw new AstDecodeException(sprintf(
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

        self::$packageTypes = $map;
        self::$classMap = $map + self::$registered;

        return self::$classMap;
    }
}
