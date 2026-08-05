<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

/**
 * The PART 12 wire shape: carve-js's field names, and how this engine's
 * internals map onto them.
 *
 * PART 12 §1 makes the reference implementation's shape normative and says an
 * implementation whose internals differ "MAPS on the way out; it does not export
 * its internals", and §3 forbids inventing a synonym or exposing an internal
 * field the reference lacks. This table is that mapping, kept in one place so
 * the codec stays a walker rather than a pile of special cases.
 *
 * Three kinds of difference appear:
 *
 * - **Renames.** `content` is `value` on text and code, `destination` is `href`,
 *   `source` is `src`, `language` is `lang`, an inline footnote reference's
 *   `label` is `id`.
 * - **Container names.** The reference calls a list's children `items`, a
 *   table's `rows`, a row's `cells`. Only the key differs; the contents do not.
 * - **Derived state.** `ordered` is a boolean where this engine keeps a
 *   `listType` string, `checked` comes from a task marker, `header` from a
 *   cell's flag. Those convert in both directions rather than being renamed.
 *
 * Internal fields with no reference counterpart are NOT emitted (§3). They are
 * listed per type so the omission is a decision rather than an oversight, and so
 * AstCodecTest can assert the round trip still holds despite them - a field that
 * cannot be exported must be recomputable, or the round trip breaks.
 */
final class ReferenceShape
{
    /**
     * Internal property name => PART 12 field name, per node type.
     *
     * @var array<string, array<string, string>>
     */
    public const RENAMES = [
        'text' => ['content' => 'value'],
        'code' => ['content' => 'value'],
        'escaped_text' => ['content' => 'value'],
        // The reference calls it `text` here, not `value`: the content is
        // literal, so it is closer to a footnote's label than to a text node.
        'critic_comment' => ['content' => 'text'],
        'code_block' => ['content' => 'content', 'language' => 'lang'],
        'link' => ['destination' => 'href', 'title' => 'title', 'referenceLabel' => 'ref', 'rawReferenceLabel' => 'rawRef'],
        'image' => ['source' => 'src', 'alt' => 'alt', 'title' => 'title', 'referenceLabel' => 'ref', 'rawReferenceLabel' => 'rawRef'],
        // The INLINE reference, which the reference calls `footnote_ref` and
        // keys by `id`. The block definition is a different type and publishes
        // `label` in PART 12 §7.
        'footnote_ref' => ['label' => 'id'],
        // The reference calls a caption's number `n`, and the schema pins that
        // name with `additionalProperties: false` - publishing `number` is not a
        // cosmetic difference, it is invalid (carve-php#843).
        'caption_number' => ['number' => 'n'],
        'heading_ref' => ['targetId' => 'target', 'href' => 'href'],
        'math' => ['content' => 'content', 'display' => 'display'],
        'symbol' => ['name' => 'name'],
        // The reference calls an inline extension's name `name` and its body
        // `content` (the CONTAINERS entry below), where this engine keeps
        // `extensionType` and ordinary children.
        'inline_extension' => ['extensionType' => 'name'],
        // The reference publishes what the DOCUMENT says - the abbreviation and
        // what it expands to - not how this engine renders it. `title` is the
        // `<abbr title=...>` attribute; `abbr` is derived from the text child,
        // which the wire then does not repeat.
        'abbreviation' => ['title' => 'expansion'],
        'strong' => ['boldItalic' => 'boldItalic'],
        'heading' => ['level' => 'level'],
        // `style` holds the DIALECT (`a`, `A`, `i`, `I`), which the reference
        // calls `olType`; its `delim` is the delimiter CHARACTER, which this
        // engine keeps in `marker`. Publishing style as `delim` put a dialect
        // where a `.` or `)` belongs and left the real delimiter unnamed.
        'list' => ['start' => 'start', 'tight' => 'tight', 'marker' => 'bulletChar', 'style' => 'olType'],
        'table_cell' => [
            'alignment' => 'align',
            'spanMarker' => 'span',
        ],
        // The reference's `title` is an array of inline nodes, which is what
        // headerNodes holds; the raw header string is this engine's own.
        'div' => ['label' => 'label', 'headerNodes' => 'title'],
        // `value` is the AUTHOR'S SOURCE RUN (`...`, `->`), which this engine
        // keeps in `content`; `glyph` is the resolved character and is present
        // only where the parser fixed one (quotes are locale-dependent). The
        // map used to publish the GLYPH as `value`, so an ellipsis - which has
        // no glyph of its own - came back as `"value": null` and the source run
        // leaked under its internal name beside it.
        'smart_punctuation' => ['kind' => 'kind', 'content' => 'value', 'glyph' => 'glyph'],
        'thematic_break' => [],
        'document' => ['sourceLength' => 'srcByteLength'],
        // The reference shows an autolink's target twice - `href` is the
        // resolved target (a bare mail address gains `mailto:`), `text` is what
        // the author sees - and gives it no children.
        'autolink' => ['destination' => 'href'],
        // `kind` is the admonition word (`::: warning`), which this engine keeps
        // as a class; `title` is the quoted opener.
        'admonition' => ['headerNodes' => 'title'],
    ];

    /**
     * Wire node type => the class this engine builds it from.
     *
     * profiles.md is explicit that these are their OWN types, not a broader
     * type carrying a flag: "An `autolink` is its own type, not a `link` ...
     * folding it into `link` loses the authored form, so a round-trip could not
     * restore it", and the same for `admonition` versus `div`. carve-php models
     * both as the broader class plus a flag, which is exactly the internals-do-
     * not-match case §1 says to MAP on the way out.
     *
     * `Profile::canonicalTypeOf()` already made this distinction for profile
     * matching (carve#362); the codec now publishes the same name.
     *
     * @var array<string, string>
     */
    public const TYPE_ALIASES = [
        'autolink' => 'link',
        'admonition' => 'div',
        // `#tag` is its own AST type in the reference, and this engine models it
        // as a Mention carrying a `tag` css class. A profile still classifies
        // both as `mention` - that is a TRUST CLASS, not the node's identity
        // (profiles.md).
        'tag' => 'mention',
    ];

    /**
     * Types whose children are published under another key (PART 12 §3).
     *
     * @var array<string, string>
     */
    public const CONTAINERS = [
        'list' => 'items',
        'definition_list' => 'items',
        'table' => 'rows',
        'table_row' => 'cells',
        // An inline footnote's body is `inline` in the reference, not
        // `children` - it is the note's content, not nested structure
        // (carve#405).
        'inline_footnote' => 'inline',
        'inline_extension' => 'content',
    ];

    /**
     * Every rename above was verified against carve-js's actual output, or is
     * stated outright in PART 12 §3. Types this engine has and the reference was
     * not observed producing - citations, inline extensions, substitutions,
     * mentions - are deliberately NOT mapped: §3 forbids inventing a synonym,
     * and a guessed name is worse than an unmapped one because it looks
     * conformant. They are listed in docs/ast-json.md as the remaining gap.
     *
     * Internal fields with no reference counterpart, per type.
     *
     * Each is either presentational state this engine keeps for its own writer
     * or a flag the reference derives from the tree. They are omitted on the way
     * out; the decoder recomputes them, which is what keeps §6's round trip
     * intact without exporting internals.
     *
     * @var array<string, array<string>>
     */
    public const INTERNAL_ONLY = [
        // `user` / `name` carry the mention, and the css class says which of the
        // two it is. The destination is always empty (nothing links anywhere)
        // and the title never set, so both would publish noise the schema
        // rejects outright.
        'mention' => ['cssClass', 'destination', 'title', 'referenceLabel', 'rawReferenceLabel', 'isAutolink', 'fromHeadingReference'],
        // `tag` is the same node class, so it hides the same internals - the
        // lookup is keyed by the WIRE type, and a tag publishes as `tag`.
        'tag' => ['cssClass', 'destination', 'title', 'referenceLabel', 'rawReferenceLabel', 'isAutolink', 'fromHeadingReference'],
        // `ordered` carries `listType`. It does NOT carry `bareMarker`: that
        // says the author wrote `.` with no number, which no other field
        // implies, and hiding it made a bare-dot list come back as `1.` after a
        // round trip - the authored form PART 11 §6 preserves. carve-js and
        // carve-rs both publish it (carve-php#711).
        'list' => ['listType'],
        // `checked` carries this; a non-task item simply has no `checked`.
        'list_item' => ['taskMarker'],
        // `header` carries this; the row flag is recomputed from its cells.
        // `rowspan`/`colspan` are internal bookkeeping this engine still keeps
        // for its own writer, the ProseMirror bridge, and layout renderers
        // that flatten a table into logical columns - none of it is the
        // reference's shape. carve-php#527: the reference records a span as a
        // real placeholder `table_cell` (`span: "rowspan"`/`"colspan"`), not a
        // count on the origin, and `additionalProperties: false` rejects the
        // count outright. The count is recomputable from the tree (every
        // renderer that needs an ACCURATE one - HTML, Carve, ANSI/plain/
        // Markdown - already resolves it fresh from the placeholders via
        // TableSpanGrid rather than trusting this field), so dropping it from
        // the wire loses nothing a consumer could read from it anyway.
        'table_cell' => ['isHeader', 'rowspan', 'colspan'],
        'table_row' => ['isHeader'],
        // Fence width is a writer concern, recomputed when formatting.
        'div' => ['typed', 'header'],
        // `typed` IS the type name on the wire; `header` is re-rendered from
        // `title`. `label` stays: the reference has it (`[Build]` on the opener
        // is authored content, verified against carve-js).
        'admonition' => ['typed', 'header'],
        // `isAutolink` IS the type name on the wire; the single text child is
        // published as `text`.
        'autolink' => ['isAutolink', 'referenceLabel', 'title', 'fromHeadingReference'],
        // Nothing. `label` is authored (`[NPM]` on the opener), and the
        // reference keeps `header` too - it is how a title written IN the fence
        // is told apart from one written on an attribute line above it, which
        // both land in `attrs.title`. A div's `header` is different: the
        // reference has no such field there, so that one stays internal.
        'code_block' => [],
        'link' => ['isAutolink', 'fromHeadingReference'],
        'thematic_break' => ['char'],
        // Fence WIDTH is a writer's concern, recomputed when formatting; the
        // wire carries `block`, which is the question a consumer asks (derived
        // in AstCodec).
        'comment' => ['fenceLength'],
        'table' => ['separatorWidths'],
        // `document` lists nothing. `abbreviations` and the flag recording
        // whether they preceded the body are authored content - dropping them
        // loses every `*[HTML]: ...` line, or moves it to the end. The reference
        // puts definitions in `abbreviation_def` nodes among the document's
        // children rather than in a field, so their POSITION is structural
        // there; this engine keeps a field, a divergence recorded in
        // docs/ast-json.md rather than a reason to drop the content.
        //
        // `abbreviationSpans` is internal outright: it records where each
        // definition line sat so the node built for it at serialization can
        // carry a position, and the reference has no field for it because it
        // never needed one - its definitions are already nodes.
        'document' => ['abbreviationSpans'],
    ];

    /**
     * The reference field name for an internal property, or null when the
     * reference has no such field and it must not be exported.
     */
    public static function fieldFor(string $carveType, string $property): ?string
    {
        if (in_array($property, self::INTERNAL_ONLY[$carveType] ?? [], true)) {
            return null;
        }

        $renames = self::RENAMES[$carveType] ?? null;
        if ($renames === null) {
            // A type with no entry publishes its own property names, which is
            // correct wherever this engine and the reference already agree.
            return $property;
        }

        return $renames[$property] ?? $property;
    }

    public static function containerFor(string $carveType): string
    {
        return self::CONTAINERS[$carveType] ?? 'children';
    }

    /**
     * The class-level type a wire type is built from.
     */
    public static function classTypeFor(string $wireType): string
    {
        return self::TYPE_ALIASES[$wireType] ?? $wireType;
    }
}
