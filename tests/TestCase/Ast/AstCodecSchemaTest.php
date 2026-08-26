<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstSchema;
use MarkupCarve\Carve\Ast\ReferenceShape;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * Pins the AST encoding as a wire format.
 *
 * The codec derives field names by reflection, which is what keeps it complete
 * without a hand-maintained table - but it also means an innocent-looking
 * property rename silently changes JSON other implementations and applications
 * read. This test makes that a visible diff: a rename fails here, and the fix is
 * either to keep the old name or to bump AstCodec::VERSION deliberately and
 * refresh the golden file.
 */
class AstCodecSchemaTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/../../fixtures/ast-schema.json';

    public function testTheEncodedSchemaMatchesTheGoldenFile(): void
    {
        /** @var array<string, array<string>> $golden */
        $golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            $golden,
            AstCodec::schema(),
            'The AST wire format changed. Either keep the previous field names, or bump '
                . 'AstCodec::VERSION and refresh tests/fixtures/ast-schema.json on purpose.',
        );
    }

    public function testEveryNodeTypeInTheSchemaCanBeDecoded(): void
    {
        // A type present in the schema but missing from the class map would be a
        // one-way format: encodable, not readable. Types with required fields are
        // decoded with those fields supplied, which also checks that the schema's
        // "required" list is the one the decoder actually enforces.
        //
        // THE PAYLOAD MUST NOW BE SCHEMA-VALID, because PART 12 §12(d) validates
        // it at decode. So a required field carries a value of the TYPE the
        // schema gives it rather than a blank string, and an inline node is
        // wrapped in the paragraph that can hold it instead of being hung
        // directly off the root. Both were only ever true by accident.
        $outsideTheVocabulary = [];
        foreach (AstCodec::schema() as $type => $entry) {
            // `citation` is a typed inline node on the wire, but in PHP it is
            // deliberately group-owned state rather than independently
            // constructible. Its real decode path is covered by
            // TypedCitationItemTest.
            if ($type === 'citation') {
                continue;
            }
            if (!self::isInline($type) && !self::isBlock($type)) {
                // A type this codec will ENCODE that the schema does not name
                // as a node at all. `document` is the root and cannot be a
                // child of anything, which is the only reason left for a type to
                // land here; it is pinned below rather than skipped quietly.
                $outsideTheVocabulary[] = $type;

                continue;
            }
            $payload = ['type' => $type];
            // BOTH required lists. The reflection-derived one and the schema's
            // do not name the same fields for every type - `abbreviation` wants
            // `expansion` here and `abbr` there - and this test is about the
            // type being decodable, not about which list is right.
            foreach (array_unique(array_merge($entry['required'], self::schemaRequired($type))) as $field) {
                if ($field === 'type') {
                    continue;
                }
                $payload[$field] = self::sampleFor($type, (string)$field);
            }

            $children = self::isInline($type)
                ? [['type' => 'paragraph', 'children' => [$payload]]]
                : [$payload];
            $decoded = (new AstCodec())->decode(['type' => 'document', 'srcByteLength' => 0, 'children' => $children]);

            $node = self::isInline($type)
                ? $decoded->getChildren()[0]->getChildren()[0]
                : $decoded->getChildren()[0];
            // The CLASS the wire type resolves to. Three wire types are a
            // narrowing of a broader class rather than a class of their own -
            // an `autolink` is a `Link`, an `admonition` a `Div`, a `tag` a
            // `Mention` - so what comes back reports the class type, and the
            // narrowing is restored from the field that carries it. Comparing
            // the wire name to `getType()` only held while the schema named no
            // alias, which is the bug that put them there.
            $this->assertSame(ReferenceShape::classTypeFor($type), $node->getType());
        }

        // PINNED, not tolerated. `caption` and `section` used to sit here too:
        // encodable, and not in PART 12's vocabulary, so this engine published
        // two types §12(d) refuses to read back. They are off the wire now
        // (carve-php#1002), and `document` is left as the one entry with a
        // reason - it is the root, and a root is not a child of anything.
        //
        // `citation_definition` is a PIN LAG rather than a second such entry:
        // PART 12 §18 lands in the schema at spec 861498b and this branch's
        // submodule pin predates it, so the pinned `blockNode` enum does not
        // name the type yet. The expectation is read FROM the pinned schema, so
        // it resolves itself the moment the pin moves - and from that moment
        // the loop above decodes the type for real rather than skipping it.
        sort($outsideTheVocabulary);
        $expected = self::isBlock('citation_definition')
            ? ['document']
            : ['citation_definition', 'document'];
        $this->assertSame($expected, $outsideTheVocabulary);
    }

    /**
     * EVERY TYPE AND FIELD THE ENCODER ACTUALLY EMITS IS ONE THE SCHEMA NAMES,
     * measured by encoding the corpus rather than by reading the derivation.
     *
     * `AstCodec::schema()` reflects over node properties. That sees the property
     * walk in `encodeNode()` and nothing else, and three code paths write
     * outside it: the retypes at the top (a bare-URL `Link` is published as
     * `autolink`, a typed `Div` as `admonition`, a `#tag` `Mention` as `tag`),
     * `derivedFields()`, and the shape passes at the end. So the schema omitted
     * three types outright and under-reported the fields of eleven, while the
     * encoder emitted all of them.
     *
     * A reflection that misses a code path is a check that cannot fail. This one
     * can: it compares the published map against real output, so a new
     * hand-written field or a fourth retype breaks it instead of silently
     * widening the gap.
     */
    public function testTheSchemaNamesEveryTypeAndFieldTheEncoderEmits(): void
    {
        $schema = AstCodec::schema();
        $emitted = self::emittedShape();

        $this->assertNotSame([], $emitted, 'the corpus produced no nodes, so this test checked nothing');
        // The retyped three really are reached, or the corpus walk would prove
        // nothing about the code path that omitted them.
        foreach (['autolink', 'admonition', 'tag'] as $retyped) {
            $this->assertArrayHasKey($retyped, $emitted, $retyped . ' was never emitted by the sample documents');
        }

        $undeclared = [];
        foreach ($emitted as $type => $fields) {
            if (!isset($schema[$type])) {
                $undeclared[$type] = ['THE TYPE ITSELF'];

                continue;
            }
            $missing = array_values(array_diff($fields, $schema[$type]['fields']));
            if ($missing !== []) {
                $undeclared[$type] = $missing;
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            'the encoder emits types or fields the published schema does not name: '
                . json_encode($undeclared, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * AND EACH RETYPED WIRE NAME SURVIVES A ROUND TRIP under it.
     *
     * The assertion above relaxes `testEveryNodeTypeInTheSchemaCanBeDecoded` to
     * compare against the CLASS a wire type resolves to, so on its own it would
     * pass even if `admonition` came back as a plain `div` and re-encoded as
     * one. What makes the narrowing real is that the field carrying it survives:
     * encode, decode, encode again, and the wire type is still the narrow one.
     */
    public function testARetypedWireNameSurvivesTheRoundTrip(): void
    {
        $codec = new AstCodec();

        foreach (
            [
                'autolink' => "<https://example.com>\n",
                'admonition' => "::: note\nbody\n:::\n",
                'tag' => "#tag\n",
            ] as $wireType => $source
        ) {
            $once = $codec->encode((new CarveConverter())->parse($source));

            $this->assertContains(
                $wireType,
                array_keys(self::emittedShapeOf($once)),
                $wireType . ' was not emitted at all, so the round trip proves nothing',
            );
            $this->assertSame($once, $codec->encode($codec->decode($once)));
        }
    }

    /**
     * And nothing is DECLARED as hand-written that the encoder never writes, so
     * the list cannot be padded to make the test above pass.
     */
    public function testEveryHandWrittenFieldIsOneTheEncoderEmits(): void
    {
        $emitted = self::emittedShape();

        $unproduced = [];
        foreach (AstCodec::HAND_WRITTEN_FIELDS as $type => $fields) {
            foreach ($fields as $field) {
                if (!in_array($field, $emitted[$type] ?? [], true)) {
                    $unproduced[] = $type . '.' . $field;
                }
            }
        }

        $this->assertSame([], $unproduced);
    }

    /**
     * Every `type => list<field>` the encoder produces over the spec corpus plus
     * a handful of documents for shapes the corpus does not carry.
     *
     * Structural keys are excluded: they are the payload's frame (PART 12 §7),
     * not fields of a node type, and the schema does not list them. The CHILD
     * container is one of them and is resolved per type rather than by a fixed
     * list - `inline_extension` publishes its children under `content`, a list
     * under `items`, a table under `rows` - so excluding a fixed set both
     * hides a real field on one type and reports a container as a missing
     * field on another.
     *
     * @return array<string, array<string>>
     */
    private static function emittedShape(): array
    {
        $frame = ['type', 'attrs', 'pos', 'srcByteLength'];

        $sources = array_map(
            static fn (string $path): string => (string)file_get_contents($path),
            glob(__DIR__ . '/../../spec/tests/corpus/*.crv') ?: [],
        );
        foreach (self::SHAPES_THE_CORPUS_DOES_NOT_CARRY as $source) {
            $sources[] = $source;
        }

        $seen = [];
        $codec = new AstCodec();
        $walk = static function (array $node) use (&$walk, &$seen, $frame): void {
            $type = $node['type'] ?? null;
            if (is_string($type)) {
                $skip = array_merge($frame, [ReferenceShape::containerFor($type)]);
                foreach ($node as $key => $value) {
                    if (is_string($key) && !in_array($key, $skip, true)) {
                        $seen[$type][$key] = true;
                    }
                }
            }
            foreach ($node as $value) {
                if (!is_array($value)) {
                    continue;
                }
                if (isset($value['type'])) {
                    $walk($value);

                    continue;
                }
                foreach ($value as $child) {
                    if (is_array($child) && isset($child['type'])) {
                        $walk($child);
                    }
                }
            }
        };

        foreach ($sources as $source) {
            $walk($codec->encode((new CarveConverter())->parse($source)));
        }

        return self::sortedShape($seen);
    }

    /**
     * The same harvest over ONE already-encoded payload.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, array<string>>
     */
    private static function emittedShapeOf(array $payload): array
    {
        $frame = ['type', 'attrs', 'pos', 'srcByteLength'];
        $seen = [];
        $walk = static function (array $node) use (&$walk, &$seen, $frame): void {
            $type = $node['type'] ?? null;
            if (is_string($type)) {
                $skip = array_merge($frame, [ReferenceShape::containerFor($type)]);
                foreach ($node as $key => $value) {
                    if (is_string($key) && !in_array($key, $skip, true)) {
                        $seen[$type][$key] = true;
                    }
                }
            }
            foreach ($node as $value) {
                if (!is_array($value)) {
                    continue;
                }
                if (isset($value['type'])) {
                    $walk($value);

                    continue;
                }
                foreach ($value as $child) {
                    if (is_array($child) && isset($child['type'])) {
                        $walk($child);
                    }
                }
            }
        };
        $walk($payload);

        return self::sortedShape($seen);
    }

    /**
     * @param array<string, array<string, bool>> $seen
     *
     * @return array<string, array<string>>
     */
    private static function sortedShape(array $seen): array
    {
        $shape = [];
        foreach ($seen as $type => $fields) {
            $names = array_keys($fields);
            sort($names);
            $shape[$type] = $names;
        }
        ksort($shape);

        return $shape;
    }

    /**
     * @var array<string>
     */
    private const SHAPES_THE_CORPUS_DOES_NOT_CARRY = [
        "<https://example.com>\n",
        "::: note \"Heads up\"\nbody\n:::\n",
        "::: sidebar\nbody\n:::\n",
        "#tag and @user\n",
        "- [ ] a\n- [x] b\n",
        "1. a\n",
        "%%% c\n%%%\n",
        "%% inline\n",
        "*[HTML]: HyperText\n\nHTML\n",
        "| a | b |\n|---|---|\n| 1 | 2 |\n",
    ];

    public function testOmittingARequiredFieldIsRejected(): void
    {
        // The alternative was inventing a scalar zero, which rendered a heading
        // without a level as <h0> instead of failing. The report is PART 12
        // §12(d)'s now - the schema names the same field, and naming it twice in
        // two wordings is what §11's own docblock warns against.
        $required = array_filter(AstCodec::schema(), static fn (array $entry): bool => $entry['required'] !== []);
        $this->assertNotSame([], $required, 'expected at least one type with a required field');

        $type = (string)array_key_first($required);

        $this->expectExceptionMessage('which the schema requires');

        $children = self::isInline($type)
            ? [['type' => 'paragraph', 'children' => [['type' => $type]]]]
            : [['type' => $type]];
        (new AstCodec())->decode(['type' => 'document', 'srcByteLength' => 0, 'children' => $children]);
    }

    /**
     * The fields the AST schema itself requires on `$type`.
     *
     * @return list<string>
     */
    private static function schemaRequired(string $type): array
    {
        $required = AstSchema::schema()['$defs'][$type]['required'] ?? [];

        return is_array($required) ? array_values(array_filter($required, 'is_string')) : [];
    }

    /**
     * A MINIMAL value the schema accepts for `$field` on `$type`.
     *
     * Presence used to be the whole point and a blank string did for every
     * field. PART 12 §12(d) validates the payload, so the value has to satisfy
     * the schema too - which means resolving the field's own definition rather
     * than guessing from its name.
     */
    private static function sampleFor(string $type, string $field): mixed
    {
        $definition = AstSchema::schema()['$defs'][$type]['properties'][$field] ?? [];

        return is_array($definition) ? self::minimalFor($definition) : '';
    }

    /**
     * The smallest value satisfying `$definition`, over the keyword subset the
     * AST schema uses.
     *
     * @param array<string, mixed> $definition
     */
    private static function minimalFor(array $definition): mixed
    {
        if (isset($definition['$ref']) && is_string($definition['$ref'])) {
            $name = substr($definition['$ref'], strlen('#/$defs/'));
            $resolved = AstSchema::schema()['$defs'][$name] ?? [];

            return is_array($resolved) ? self::minimalFor($resolved) : '';
        }
        if (array_key_exists('const', $definition)) {
            return $definition['const'];
        }
        if (isset($definition['enum']) && is_array($definition['enum']) && $definition['enum'] !== []) {
            return $definition['enum'][0];
        }
        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (isset($definition[$keyword]) && is_array($definition[$keyword]) && $definition[$keyword] !== []) {
                $branch = $definition[$keyword][0];

                return is_array($branch) ? self::minimalFor($branch) : '';
            }
        }

        $declared = $definition['type'] ?? null;
        if ($declared === 'object') {
            $value = [];
            $required = is_array($definition['required'] ?? null) ? $definition['required'] : [];
            $properties = is_array($definition['properties'] ?? null) ? $definition['properties'] : [];
            foreach ($required as $name) {
                $sub = $properties[(string)$name] ?? [];
                $value[(string)$name] = is_array($sub) ? self::minimalFor($sub) : '';
            }

            return $value;
        }

        $floor = $definition['minimum'] ?? 0;

        return match ($declared) {
            'array' => [],
            'integer', 'number' => is_int($floor) || is_float($floor) ? $floor : 0,
            'boolean' => false,
            'null' => null,
            default => '',
        };
    }

    /**
     * Is `$type` a BLOCK node, which the schema lets sit at the root?
     */
    private static function isBlock(string $type): bool
    {
        $enum = AstSchema::schema()['$defs']['blockNode']['properties']['type']['enum'] ?? [];

        return is_array($enum) && in_array($type, $enum, true);
    }

    /**
     * Is `$type` an INLINE node, which the schema will not let sit at the root?
     */
    private static function isInline(string $type): bool
    {
        $enum = AstSchema::schema()['$defs']['inlineNode']['properties']['type']['enum'] ?? [];

        return is_array($enum) && in_array($type, $enum, true);
    }

    /**
     * An internal node type is not in the published schema either.
     *
     * The class map is built by reflection, so an internal node class is
     * advertised by default - which is how `raw_text` stayed in this schema
     * after the encoder stopped emitting it, and how `caption` and `section`
     * stayed in it while the encoder still DID emit them: a consumer validating
     * against `AstCodec::schema()` was told about types the spec's own schema
     * rejects (PART 12 §5).
     */
    public function testAFormatterInternalTypeIsNotAdvertised(): void
    {
        $schema = AstCodec::schema();

        foreach (AstCodec::NOT_ON_THE_WIRE as $type => $published) {
            $this->assertArrayNotHasKey($type, $schema);
            // And what it maps to IS advertised, or the mapping publishes a
            // second type nothing can read.
            $this->assertArrayHasKey($published, $schema);
        }
        // The list is not empty, or this test would pass by describing nothing.
        $this->assertNotSame([], AstCodec::NOT_ON_THE_WIRE);
    }

    /**
     * THE ROUND TRIP THROUGH THIS ENGINE'S OWN CODEC, for the two types that
     * failed it. A `section` and a `caption` reach the encoder from the
     * ProseMirror bridge, which builds both; the payload that came back named
     * them, and `decode()` refused it as a type the vocabulary does not hold.
     */
    public function testAnInternalTypeIsPublishedUnderAVocabularyName(): void
    {
        $document = (new ProseMirrorToCarve())->convert([
            'type' => 'doc',
            'content' => [
                [

                    'type' => 'carveSection',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'body']]],
                    ],
                ],
                ['type' => 'carveCaption', 'content' => [['type' => 'text', 'text' => 'cap']]],
            ],
        ]);

        // The tree really does hold them, or the encode below proves nothing.
        $this->assertSame(
            ['section', 'caption'],
            array_map(static fn (object $child): string => $child->getType(), $document->getChildren()),
        );

        $codec = new AstCodec();
        $encoded = $codec->encode($document);

        $this->assertSame(['div', 'paragraph'], array_column($encoded['children'], 'type'));
        $this->assertSame(
            'body',
            $encoded['children'][0]['children'][0]['children'][0]['value'],
            'the section\'s content has to survive the mapping',
        );
        $this->assertSame('cap', $encoded['children'][1]['children'][0]['value']);

        // And the payload its own encoder produced is one its own decoder reads.
        $this->assertSame(
            $encoded,
            $codec->encode($codec->decode($encoded)),
        );
    }

    /**
     * AND THE DECODER REFUSES EACH OF THEM. The mapping above only holds while
     * the wire name is the only one an ingest reads back: an internal type that
     * is refused on the way out but accepted on the way in would leave two
     * spellings for one node, which is what §12(d) exists to end.
     */
    public function testAnInternalTypeIsRefusedOnIngest(): void
    {
        foreach (array_keys(AstCodec::NOT_ON_THE_WIRE) as $type) {
            try {
                (new AstCodec())->decode([
                    'type' => 'document',
                    'srcByteLength' => 0,
                    'children' => [['type' => $type, 'children' => []]],
                ]);
                $this->fail(sprintf('`%s` was accepted on ingest', $type));
            } catch (AstDecodeException $e) {
                $this->assertStringContainsString($type, $e->getMessage());
            }
        }
    }

    /**
     * AN ATTRIBUTE NAMED `type` IS NOT A NODE. `attrs` and `pos` hold named
     * slots, and `keyValues` takes whatever the author wrote - so a walk that
     * descended into them would rewrite `{type=section}` into `{type=div}` and
     * change the rendered HTML.
     */
    public function testAnAttributeIsNotMistakenForANodeType(): void
    {
        $encoded = (new AstCodec())->encode((new CarveConverter())->parse('[x]{type=section}'));

        $this->assertSame(
            'section',
            $encoded['children'][0]['children'][0]['attrs']['keyValues']['type'],
        );
    }

    public function testAFalseValueThatIsNotTheDefaultSurvives(): void
    {
        // A loose list is tight = false against a default of true. Omitting every
        // falsy value would have encoded it as tight.
        $converter = new CarveConverter();
        $codec = new AstCodec();

        $document = $converter->parse("- one\n\n- two\n");
        $encoded = $codec->encode($document);

        $this->assertFalse($encoded['children'][0]['tight']);
        $this->assertSame($converter->render($document), $converter->render($codec->decode($encoded)));
    }
}
