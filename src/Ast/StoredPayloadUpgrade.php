<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use JsonException;
use MarkupCarve\Carve\Exception\AstDecodeException;

/**
 * Rewrites a payload stored before PART 12 §7 into the §7 shape.
 *
 * This engine used to READ five spellings that predate §7, normalizing each one
 * on the way in. They are gone: §12(d) validates the payload as literally
 * written, and a permanently tolerated pre-§7 spelling is exactly the leniency
 * that clause replaces - neither sibling engine accepts them, so keeping them
 * kept a cross-engine divergence in what an ingest accepts.
 *
 * The five, and what each becomes:
 *
 * - a root `abbreviations` map (with `abbreviationsBeforeBody`) becomes
 *   `abbreviation_def` block nodes, ahead of the body or after it exactly as
 *   the flag said;
 * - a root `frontmatter` object becomes the leading `frontmatter` block node;
 * - a root `footnoteDefs` map becomes trailing `footnote` block nodes, one per
 *   label;
 * - a `footnote` node keyed `id` is rekeyed `label`, the name §7 gives it;
 * - a `raw_text` node becomes the `text` node the encoder already mapped it to,
 *   because `raw_text` is this engine's own and PART 12 §5 keeps it off the
 *   wire.
 *
 * IT WORKS ON THE PAYLOAD, NOT ON THE SOURCE. An application holding stored
 * payloads may no longer have the Carve they were parsed from, so re-parsing is
 * not a migration path available to it - which is why this ships in the same
 * release as the removal rather than on a deprecation timeline.
 *
 * ```php
 * $payload = StoredPayloadUpgrade::upgrade(json_decode($stored, true));
 * $document = (new AstCodec())->decode($payload);
 * ```
 *
 * The result is what this engine would have published had it decoded the stored
 * payload and encoded it again, minus the resolution results §5 stamps at encode
 * time - those are recomputed by the next encode and are not the migration's
 * business.
 */
final class StoredPayloadUpgrade
{
    /**
     * Root fields §7 retired. The root carries exactly `type`, `children` and
     * `srcByteLength`.
     *
     * @var array<string, string>
     */
    public const RETIRED_ROOT_FIELDS = [
        'abbreviations' => 'a root `abbreviations` map',
        'abbreviationsBeforeBody' => 'a root `abbreviationsBeforeBody` flag',
        'frontmatter' => 'a root `frontmatter` object',
        'footnoteDefs' => 'a root `footnoteDefs` map',
    ];

    /**
     * Field renames a retired node type needs beyond its `type`.
     *
     * The types themselves are `AstCodec::NOT_ON_THE_WIRE`, so a type leaving
     * the wire cannot leave this migration behind. Only `raw_text` has any
     * state: `Caption` and `Section` declare no properties of their own, so
     * mapping the type is the whole conversion for those two.
     *
     * @var array<string, array<string, string>>
     */
    private const RETIRED_NODE_FIELDS = [
        'raw_text' => ['content' => 'value'],
    ];

    /**
     * The retired shapes `$payload` carries, in the order they are listed above.
     *
     * Used by the decoder to say which pre-§7 spelling it found rather than
     * reporting it as an anonymous schema violation, so the message can name
     * this class as the way out.
     *
     * @param array<mixed> $payload
     *
     * @return array<string>
     */
    public static function retiredShapesIn(array $payload): array
    {
        // `scan()` recurses, and this method is public as well as being what
        // the decoder calls to name a pre-§7 spelling. Same bound, applied for
        // the same reason as in `upgrade()`.
        self::assertDepthWithinBound($payload);

        $found = [];
        foreach (self::RETIRED_ROOT_FIELDS as $field => $description) {
            if (array_key_exists($field, $payload)) {
                $found[] = $description;
            }
        }

        $types = [];
        $labels = false;
        // The tree and the legacy definition map, and NOT the other retired
        // root fields: `abbreviations` is keyed by the abbreviation, so an
        // expansion map holding `type` would be read as a node.
        foreach (['children', 'footnoteDefs'] as $field) {
            if (is_array($payload[$field] ?? null)) {
                self::scan($payload[$field], $types, $labels);
            }
        }
        foreach (array_keys(AstCodec::NOT_ON_THE_WIRE) as $type) {
            if (isset($types[$type])) {
                $found[] = sprintf('a `%s` node', $type);
            }
        }
        if ($labels) {
            $found[] = 'a `footnote` node keyed `id` rather than `label`';
        }

        return $found;
    }

    /**
     * Refuse a payload this class cannot walk without exhausting the stack.
     *
     * `AstCodec::MAX_JSON_DEPTH` and not a number of this class's own: it is
     * the bound `upgradeJson()` already hands `json_decode`, so the array entry
     * points accept exactly the set the string one accepts. A migration that
     * refused what the reader beside it accepts would strand records nobody
     * could sweep.
     *
     * @param array<mixed> $payload
     *
     * @throws \MarkupCarve\Carve\Exception\AstDecodeException When the payload nests past the bound.
     */
    private static function assertDepthWithinBound(array $payload): void
    {
        if (PayloadDepth::within($payload, AstCodec::MAX_JSON_DEPTH)) {
            return;
        }

        throw new AstDecodeException(sprintf(
            'The stored payload nests deeper than %d levels, the bound `upgradeJson()` applies '
                . 'through `json_decode`. A record past it was not written by encoding a parsed '
                . 'document, and walking it would exhaust the stack rather than report anything.',
            AstCodec::MAX_JSON_DEPTH,
        ));
    }

    /**
     * @param array<mixed> $node
     * @param array<string, true> $types
     * @param bool $labels
     */
    private static function scan(array $node, array &$types, bool &$labels): void
    {
        $type = $node['type'] ?? null;
        if (is_string($type) && AstCodec::isApplicationType($type)) {
            return;
        }
        if (is_string($type)) {
            if (isset(AstCodec::NOT_ON_THE_WIRE[$type])) {
                $types[$type] = true;
            }
            if ($type === 'footnote' && !array_key_exists('label', $node) && array_key_exists('id', $node)) {
                $labels = true;
            }
        }

        foreach ($node as $key => $value) {
            // `attrs` and `pos` hold named slots, never nodes, and `attrs` can
            // legitimately carry an `id` - descending into them would read a
            // heading's id as a footnote label.
            if ($key === 'attrs' || $key === 'pos' || !is_array($value)) {
                continue;
            }
            self::scan($value, $types, $labels);
        }
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<mixed>
     */
    public static function upgrade(array $payload): array
    {
        // FIRST, ahead of every other question. `upgradeJson()` is bounded for
        // free because `json_decode` takes a depth argument; this entry point
        // takes a structure somebody else decoded, and `upgradeNodes` and
        // `scan` are plain recursion, so a deep enough payload exhausts the C
        // stack. This class exists to accept ARBITRARY stored payloads, which
        // makes it the least trusted input in the package.
        self::assertDepthWithinBound($payload);

        if (($payload['type'] ?? null) !== 'document') {
            // Only the root carried the retired FIELDS. A subtree handed over on
            // its own still gets the node-level rewrites.
            return self::upgradeNodes($payload);
        }

        $stored = $payload['children'] ?? null;
        if (!is_array($stored) || !array_is_list($stored)) {
            // NOT REPAIRED. A root whose `children` is missing, is not an array,
            // or is a JSON OBJECT rather than a list is refused by PART 12
            // §12(a) or (d) and always was. Supplying `[]` would turn a
            // truncated document into an empty one, and reindexing an object
            // would turn `{"p": {...}}` into a list the schema accepts - both
            // are the silent repair §12 exists to stop. This converts a
            // spelling; it does not mend a payload.
            return $payload;
        }

        self::refuseUnconvertibleRootFields($payload);

        // The tree first, so `withFootnoteDefs` can tell which labels are
        // already defined there by the name §7 gives them. The root fields are
        // walked where they are lifted rather than here: `abbreviations` is
        // keyed by the abbreviation, and a walk over it would read an expansion
        // map holding `type` as a node.
        $children = array_map(
            static fn (mixed $child): mixed => is_array($child) ? self::upgradeNodes($child) : $child,
            $stored,
        );
        $children = self::withFrontmatter($payload, $children);
        $children = self::withFootnoteDefs($payload, $children);
        $children = self::withAbbreviationDefs($payload, $children);
        $payload['children'] = $children;

        foreach (array_keys(self::RETIRED_ROOT_FIELDS) as $field) {
            unset($payload[$field]);
        }

        return $payload;
    }

    /**
     * The same conversion, JSON in and JSON out.
     *
     * A payload that needs no conversion is returned UNCHANGED rather than
     * re-encoded, because PHP cannot tell an empty JSON object from an empty
     * array and would write `"attrs": {}` back as `"attrs": []`.
     *
     * A payload that DOES need converting is re-encoded, and an empty JSON
     * object anywhere in it - an application node's own state, most plausibly,
     * since no engine publishes an empty `attrs` - is written as `[]`. Decode
     * it yourself and call `upgrade()` if that distinction matters to a
     * consumer of yours.
     *
     * @param string $json
     * @param int $flags Passed through to `json_encode`.
     *
     * @throws \MarkupCarve\Carve\Exception\AstDecodeException When the input is not a JSON object.
     */
    public static function upgradeJson(string $json, int $flags = 0): string
    {
        try {
            $decoded = json_decode($json, true, AstCodec::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AstDecodeException('The stored payload is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        // A LIST IS NOT AN OBJECT, and PHP reads both as an array. `[]` alone
        // stays acceptable: it is what an empty JSON object decodes to as well,
        // and the decoder refuses it as a root missing `type` either way.
        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            throw new AstDecodeException(sprintf(
                'The stored payload must be a JSON object; got %s.',
                is_array($decoded) ? 'a JSON array' : get_debug_type($decoded),
            ));
        }

        $upgraded = self::upgrade($decoded);
        if ($upgraded === $decoded) {
            // BYTE FOR BYTE, and not a re-encode of an identical structure.
            // PHP reads a JSON object as an array, so `{}` and `[]` arrive the
            // same and `json_encode` writes both as `[]` - re-encoding a
            // payload that needed nothing would rewrite `"attrs": {}` into
            // `"attrs": []`, which a consumer validating against the published
            // schema refuses. A store swept with this is untouched where it was
            // already current.
            return $json;
        }

        return (string)json_encode(
            $upgraded,
            $flags | JSON_THROW_ON_ERROR,
            AstCodec::MAX_JSON_DEPTH,
        );
    }

    /**
     * A retired root field whose VALUE cannot become the nodes it names.
     *
     * `"frontmatter": "oops"` names nothing that can be placed in the tree, and
     * dropping it would turn a corrupt record into a valid document while
     * discarding whatever it held - the same silent repair the missing-children
     * case refuses one method up, arriving through the field the migration was
     * written for. A migration that cannot convert a record says so; it does
     * not tidy it away.
     *
     * A WRONG TYPE ONLY. A field the tree already carries the canonical form of
     * is a stale duplicate rather than an unconvertible value, and is dropped -
     * which is what the encoder itself does with one.
     *
     * @param array<mixed> $payload
     *
     * @throws \MarkupCarve\Carve\Exception\AstDecodeException
     */
    private static function refuseUnconvertibleRootFields(array $payload): void
    {
        foreach (['abbreviations', 'frontmatter', 'footnoteDefs'] as $field) {
            if (array_key_exists($field, $payload) && !is_array($payload[$field])) {
                throw new AstDecodeException(sprintf(
                    'The payload carries a root `%s` holding %s, which names nothing this '
                        . 'migration can place in the tree. Dropping it would turn a corrupt '
                        . 'record into a valid document and discard whatever it held, so the '
                        . 'record is left for you to decide about.',
                    $field,
                    get_debug_type($payload[$field]),
                ));
            }
        }
    }

    /**
     * The leading `frontmatter` block the root field used to be adopted as.
     *
     * A payload that already has one keeps it and drops the field: the tree
     * form is the canonical one, so it wins rather than being published twice.
     *
     * @param array<mixed> $payload
     * @param array<mixed> $children
     *
     * @return array<mixed>
     */
    private static function withFrontmatter(array $payload, array $children): array
    {
        $frontmatter = $payload['frontmatter'] ?? null;
        if (!is_array($frontmatter)) {
            return $children;
        }
        foreach ($children as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'frontmatter') {
                return $children;
            }
        }

        $format = $frontmatter['format'] ?? null;
        $content = $frontmatter['content'] ?? null;
        array_unshift($children, [
            'type' => 'frontmatter',
            'content' => is_string($content) ? $content : '',
            'format' => is_string($format) ? $format : 'yaml',
        ]);

        return $children;
    }

    /**
     * Trailing `footnote` blocks, one per label the root map held.
     *
     * Appended, which is where they render from regardless of where they were
     * written - a definition's position is not content. A label already in the
     * tree is left alone.
     *
     * @param array<mixed> $payload
     * @param array<mixed> $children
     *
     * @return array<mixed>
     */
    private static function withFootnoteDefs(array $payload, array $children): array
    {
        $defs = $payload['footnoteDefs'] ?? null;
        if (!is_array($defs)) {
            return $children;
        }

        $existing = [];
        foreach ($children as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'footnote' && is_scalar($child['label'] ?? null)) {
                $existing[(string)$child['label']] = true;
            }
        }

        foreach ($defs as $label => $blocks) {
            if (isset($existing[(string)$label]) || !is_array($blocks)) {
                continue;
            }
            $children[] = [
                'type' => 'footnote',
                'label' => (string)$label,
                'children' => array_values(array_map(
                    static fn (array $block): array => self::upgradeNodes($block),
                    array_filter($blocks, 'is_array'),
                )),
            ];
        }

        return $children;
    }

    /**
     * `abbreviation_def` blocks for the root expansion map.
     *
     * `abbreviationsBeforeBody` said WHERE the `*[ABBR]: ...` lines sat, and the
     * nodes now say it by sitting there - the same round trip the encoder makes,
     * which reads the flag back off the placement.
     *
     * @param array<mixed> $payload
     * @param array<mixed> $children
     *
     * @return array<mixed>
     */
    private static function withAbbreviationDefs(array $payload, array $children): array
    {
        $abbreviations = $payload['abbreviations'] ?? null;
        if (!is_array($abbreviations) || $abbreviations === []) {
            return $children;
        }
        foreach ($children as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'abbreviation_def') {
                return $children;
            }
        }

        $defs = [];
        foreach ($abbreviations as $abbr => $expansion) {
            $defs[] = [
                'type' => 'abbreviation_def',
                'abbr' => (string)$abbr,
                'expansion' => is_scalar($expansion) ? (string)$expansion : '',
            ];
        }

        return ($payload['abbreviationsBeforeBody'] ?? false) === true
            ? array_merge($defs, $children)
            : array_merge($children, $defs);
    }

    /**
     * The two node-level rewrites, everywhere in the tree.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function upgradeNodes(array $node): array
    {
        $type = $node['type'] ?? null;
        if (is_string($type) && AstCodec::isApplicationType($type)) {
            // An application node's state is an array this package did not
            // shape, so a key spelled `type` or `id` in there is data rather
            // than a node - and PART 12 §12(d) already leaves the subtree
            // alone for exactly that reason. Register the class before running
            // the migration, or its own fields are read as nodes.
            return $node;
        }
        if ($type === 'footnote' && !array_key_exists('label', $node) && array_key_exists('id', $node)) {
            // §7 renamed a footnote definition's `id` to `label`. Rebuilt rather
            // than assigned so the field keeps the slot `id` held, which is what
            // makes the upgraded payload compare byte for byte with an encode.
            $rebuilt = [];
            foreach ($node as $key => $value) {
                $rebuilt[$key === 'id' ? 'label' : $key] = $value;
            }
            $node = $rebuilt;
        }
        if (is_string($type) && isset(AstCodec::NOT_ON_THE_WIRE[$type])) {
            // A type the vocabulary has never held, published under the name it
            // maps to - the same map the encoder uses, so a stored payload
            // lands on the shape a fresh encode would produce.
            foreach (self::RETIRED_NODE_FIELDS[$type] ?? [] as $from => $to) {
                $value = $node[$from] ?? null;
                unset($node[$from]);
                $node[$to] = is_string($value) ? $value : '';
            }
            $node['type'] = AstCodec::NOT_ON_THE_WIRE[$type];
        }

        foreach ($node as $key => $value) {
            if ($key === 'attrs' || $key === 'pos' || !is_array($value)) {
                continue;
            }
            $node[$key] = self::upgradeNodes($value);
        }

        return $node;
    }
}
