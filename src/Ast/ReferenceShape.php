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
 *   `source` is `src`, `language` is `lang`, a footnote's `label` is `id`.
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
        'link' => ['destination' => 'href', 'title' => 'title', 'referenceLabel' => 'ref'],
        'image' => ['source' => 'src', 'alt' => 'alt', 'title' => 'title', 'referenceLabel' => 'ref'],
        'footnote' => ['label' => 'id'],
        'heading_ref' => ['targetId' => 'target'],
        'math' => ['content' => 'content', 'display' => 'display'],
        'symbol' => ['name' => 'name'],
        'abbreviation' => ['title' => 'title'],
        'strong' => ['boldItalic' => 'boldItalic'],
        'heading' => ['level' => 'level'],
        'list' => ['start' => 'start', 'tight' => 'tight', 'marker' => 'bulletChar', 'style' => 'delim'],
        'table_cell' => [
            'alignment' => 'align',
            'rowspan' => 'rowspan',
            'colspan' => 'colspan',
            'spanMarker' => 'span',
        ],
        // The reference's `title` is an array of inline nodes, which is what
        // headerNodes holds; the raw header string is this engine's own.
        'div' => ['label' => 'label', 'headerNodes' => 'title'],
        'smart_punctuation' => ['kind' => 'kind', 'glyph' => 'value'],
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
        // `ordered` carries this on the wire.
        'list' => ['listType'],
        // `checked` carries this; a non-task item simply has no `checked`.
        'list_item' => ['taskMarker'],
        // `header` carries this; the row flag is recomputed from its cells.
        'table_cell' => ['isHeader'],
        'table_row' => ['isHeader'],
        // Fence width is a writer concern, recomputed when formatting.
        'div' => ['typed', 'header'],
        // `typed` IS the type name on the wire; `header` is re-rendered from
        // `title`. `label` stays: the reference has it (`[Build]` on the opener
        // is authored content, verified against carve-js).
        'admonition' => ['typed', 'header'],
        // `isAutolink` IS the type name on the wire; the single text child is
        // published as `text`.
        'autolink' => ['isAutolink', 'referenceLabel', 'title'],
        // Nothing. `label` is authored (`[NPM]` on the opener), and the
        // reference keeps `header` too - it is how a title written IN the fence
        // is told apart from one written on an attribute line above it, which
        // both land in `attrs.title`. A div's `header` is different: the
        // reference has no such field there, so that one stays internal.
        'code_block' => [],
        'link' => ['isAutolink'],
        'thematic_break' => ['char'],
        'table' => ['separatorWidths'],
        // `document` lists nothing. `abbreviations` and the flag recording
        // whether they preceded the body are authored content - dropping them
        // loses every `*[HTML]: ...` line, or moves it to the end. The reference
        // puts definitions in `abbreviation_def` nodes among the document's
        // children rather than in a field, so their POSITION is structural
        // there; this engine keeps a field, a divergence recorded in
        // docs/ast-json.md rather than a reason to drop the content.
        'document' => [],
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
