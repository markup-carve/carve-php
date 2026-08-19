<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstSchema;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * PART 12 §12(d): AN INGEST VALIDATES THE WHOLE PAYLOAD AGAINST
 * `resources/ast-schema.json` (markup-carve/carve#881).
 *
 * Types and required fields together, at DECODE, refused with the same typed
 * error §12(a), (b) and (c) already require.
 *
 * THE VALID DOCUMENT IS ASSERTED FIRST, and it is the one every invalid shape
 * is BUILT FROM. Without it, sixteen rejections of a document that was never
 * valid in the first place would read exactly like a clause being enforced -
 * which is the shape markup-carve/carve#755 catalogues, and the reason the
 * clause's own write-up insists on this assertion.
 *
 * THE SIGNOFF PREMISE IS MEASURED, NOT INHERITED. carve#881 says the schema
 * needed no tightening because it already rejects all sixteen. That was
 * measured in the SPEC repo; here it is measured again, against the vendored
 * copy this engine actually reads, in `testTheSchemaItselfRejectsEveryShape` -
 * separately from whether the CODEC consults it, so a green run cannot be a
 * validator that refuses everything.
 */
class PayloadIsValidatedAgainstTheSchemaTest extends TestCase
{
    /**
     * The document every invalid shape below is a single edit away from.
     *
     * @return array<string, mixed>
     */
    private static function valid(): array
    {
        $span = [
            'startLine' => 1,
            'endLine' => 1,
            'startColumn' => 1,
            'endColumn' => 2,
            'startOffset' => 0,
            'endOffset' => 1,
        ];

        return [
            'type' => 'document',
            'srcByteLength' => 2,
            'children' => [
                [
                    'type' => 'paragraph',
                    'pos' => $span,
                    'children' => [['type' => 'text', 'value' => 'a', 'pos' => $span]],
                ],
            ],
        ];
    }

    /**
     * The sixteen shapes markup-carve/carve#881 tabulates, each ONE edit from
     * the valid document above.
     *
     * The third element, where present, is what the DECODER says instead: an
     * unnamed slot is §11's row and keeps its own clause, which carve#881 says
     * outright.
     *
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string, 2?: string}>
     */
    public static function invalidShapes(): array
    {
        return [
            'root srcByteLength of the wrong type' => [
                static function (array $d): array {
                    $d['srcByteLength'] = 'two';

                    return $d;
                },
                'srcByteLength is the string "two" where the schema requires integer',
            ],
            'root srcByteLength negative' => [
                static function (array $d): array {
                    $d['srcByteLength'] = -1;

                    return $d;
                },
                'below the schema minimum 0',
            ],
            'root children of the wrong type' => [
                static function (array $d): array {
                    $d['children'] = 'x';

                    return $d;
                },
                'children is the string "x" where the schema requires array',
            ],
            // §12's own objection, arriving through a door the clause did not
            // cover: a reader that supplies a default has turned a truncated
            // document into an empty one.
            'root children null' => [
                static function (array $d): array {
                    $d['children'] = null;

                    return $d;
                },
                'children is null where the schema requires array',
            ],
            'paragraph missing children' => [
                static function (array $d): array {
                    unset($d['children'][0]['children']);

                    return $d;
                },
                'is missing `children`, which the schema requires',
            ],
            'text missing value' => [
                static function (array $d): array {
                    unset($d['children'][0]['children'][0]['value']);

                    return $d;
                },
                'is missing `value`, which the schema requires',
            ],
            'text value a number' => [
                static function (array $d): array {
                    $d['children'][0]['children'][0]['value'] = 7;

                    return $d;
                },
                'value is the number 7 where the schema requires string',
            ],
            // Both used to come back as a bare PHP TypeError out of
            // `AstCodec::decodeNode()`, which PART 12 §9(b) forbids.
            'a child that is null' => [
                static function (array $d): array {
                    $d['children'][0]['children'][] = null;

                    return $d;
                },
                'is null where the schema requires object',
            ],
            'a child that is a string' => [
                static function (array $d): array {
                    $d['children'][0]['children'][] = 'text';

                    return $d;
                },
                'is the string "text" where the schema requires object',
            ],
            // The mistake a producer will actually make: `class` is what the
            // rendered HTML calls the thing.
            'attrs written as a class map' => [
                static function (array $d): array {
                    $d['children'][0]['attrs'] = ['class' => 'x'];

                    return $d;
                },
                'carries `class`, which the schema does not name',
                // §11 keeps its own clause and answers first (carve#881 says so
                // outright), so the DECODER cites §11 where the schema cites (d).
                'the AST schema does not name: ',
            ],
            'attrs with a bogus key beside keyValues' => [
                static function (array $d): array {
                    $d['children'][0]['attrs'] = ['keyValues' => ['k' => 'v'], 'bogus' => 1];

                    return $d;
                },
                'carries `bogus`, which the schema does not name',
                // §11 keeps its own clause and answers first (carve#881 says so
                // outright), so the DECODER cites §11 where the schema cites (d).
                'the AST schema does not name: ',
            ],
            'attrs of the wrong type' => [
                static function (array $d): array {
                    $d['children'][0]['attrs'] = 'x';

                    return $d;
                },
                'attrs is the string "x" where the schema requires object',
            ],
            'pos with an extra key' => [
                static function (array $d): array {
                    $d['children'][0]['pos']['extra'] = 1;

                    return $d;
                },
                'carries `extra`, which the schema does not name',
                // §11 keeps its own clause and answers first (carve#881 says so
                // outright), so the DECODER cites §11 where the schema cites (d).
                'the AST schema does not name: ',
            ],
            'pos missing endOffset' => [
                static function (array $d): array {
                    unset($d['children'][0]['pos']['endOffset']);

                    return $d;
                },
                'is missing `endOffset`, which the schema requires',
            ],
            'pos offset of the wrong type' => [
                static function (array $d): array {
                    $d['children'][0]['pos']['startOffset'] = 'x';

                    return $d;
                },
                'startOffset is the string "x" where the schema requires integer',
            ],
            // THE ONE WIDE-OPEN OBJECT in the schema: `keyValues` names no
            // property and constrains every value instead. A validator that
            // only descends where `properties` is non-empty stops here, and the
            // decoder then DROPS the non-string value while reporting success -
            // which is the whole failure §12(d) exists to end, surviving inside
            // the rule meant to end it.
            'an attribute value that is not a string' => [
                static function (array $d): array {
                    $d['children'][0]['attrs'] = ['id' => 'x', 'keyValues' => ['foo' => 7]];

                    return $d;
                },
                'keyValues.foo is the number 7 where the schema requires string',
            ],
            // `describe()` has to name a boolean and a list, and the report is
            // the thing a producer acts on, so both are exercised by a real
            // shape rather than left to a branch nothing reaches.
            'a text value that is a boolean' => [
                static function (array $d): array {
                    $d['children'][0]['children'][0]['value'] = true;

                    return $d;
                },
                'value is true where the schema requires string',
            ],
            'a pos written as a list' => [
                static function (array $d): array {
                    $d['children'][0]['pos'] = [1, 2, 3];

                    return $d;
                },
                'pos is an array where the schema requires object',
                // §11 gets there first: a list's indices are properties `pos`
                // does not name.
                'the AST schema does not name: ',
            ],
            // A `oneOf` that NO branch satisfies. `figure.target` is one of an
            // image, a table, a code block or a paragraph; a heading is none
            // of them, and the report names the admitted node types rather
            // than a required field from whichever branch happens to be first.
            'a figure target that is none of its alternatives' => [
                static function (array $d): array {
                    $d['children'][0] = [
                        'type' => 'figure',
                        'pos' => $d['children'][0]['pos'],
                        'target' => ['type' => 'heading', 'level' => 1, 'children' => []],
                        'caption' => [],
                    ];

                    return $d;
                },
                '$.children[0].target holds a "heading" node where the schema admits only block_quote, code_block, image, paragraph, table',
            ],
            'a type the vocabulary does not hold' => [
                static function (array $d): array {
                    $d['children'][0]['type'] = 'not_a_type';

                    return $d;
                },
                'type is the string "not_a_type", which the schema does not list',
            ],
        ];
    }

    public function testTheDocumentTheShapesAreBuiltFromIsValid(): void
    {
        $this->assertNull(
            AstSchema::firstViolation(self::valid()),
            'the base document must satisfy the schema, or every rejection below proves nothing',
        );

        $document = (new AstCodec())->decode(self::valid());
        $this->assertSame("<p>a</p>\n", (new HtmlRenderer())->render($document));
    }

    /**
     * A HEADING is the example on purpose: it is not a captionable host under any
     * version of the clause, so this case does not move when the admitted set does.
     * A `block_quote` would have read better and was rejected for exactly that
     * reason: markup-carve/carve#1161 removed it from the set and
     * markup-carve/carve#1213 has since put it back, so a case built on it
     * would have asserted the opposite of the pinned schema within days. The
     * admitted set in the expectation moves with the pin; the refused type
     * does not.
     */
    public function testFigureTargetReportsARefusedNodeTypeAndEveryAdmittedType(): void
    {
        $payload = self::valid();
        $payload['children'][0] = [
            'type' => 'figure',
            'target' => [
                'type' => 'heading',
                'level' => 1,
                'children' => [],
            ],
            'caption' => [],
        ];

        $violation = AstSchema::firstViolation($payload);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('holds a "heading" node where the schema admits only', (string)$violation);
        $this->assertStringContainsString('$.children[0].target', (string)$violation);
        $this->assertStringNotContainsString('src', (string)$violation);

        try {
            (new AstCodec())->decode($payload);
            $this->fail('the decoder accepted a heading as a figure target');
        } catch (AstDecodeException $e) {
            $this->assertStringContainsString((string)$violation, $e->getMessage());
            $this->assertStringContainsString('PART 12 §12(d)', $e->getMessage());
            $this->assertStringNotContainsString('src', $e->getMessage());
        }
    }

    public function testFigureMayTargetACompleteImage(): void
    {
        $payload = self::valid();
        $payload['children'][0] = [
            'type' => 'figure',
            'target' => ['type' => 'image', 'src' => 'figure.png', 'alt' => 'Figure'],
            'caption' => [],
        ];

        $this->assertNull(AstSchema::firstViolation($payload));
    }

    public function testFigureTargetReportsAMissingFieldForAnAdmittedNodeType(): void
    {
        $payload = self::valid();
        $payload['children'][0] = [
            'type' => 'figure',
            'target' => ['type' => 'image'],
            'caption' => [],
        ];

        $this->assertSame(
            '$.children[0].target is missing `src`, which the schema requires',
            AstSchema::firstViolation($payload),
        );
    }

    public function testCompositionWithoutAStringNodeTypeKeepsTheFirstBranchFailure(): void
    {
        $payload = self::valid();
        $payload['children'][0] = [
            'type' => 'figure',
            'target' => [],
            'caption' => [],
        ];

        $this->assertSame(
            '$.children[0].target is missing `type`, which the schema requires',
            AstSchema::firstViolation($payload),
        );
    }

    /**
     * The premise carve#881 signed off on, measured against the copy THIS
     * engine reads rather than against the spec repo's.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $break
     * @param string $expected
     * @param string|null $fromDecoder
     */
    #[DataProvider('invalidShapes')]
    public function testTheSchemaItselfRejectsEveryShape(callable $break, string $expected, ?string $fromDecoder = null): void
    {
        $violation = AstSchema::firstViolation($break(self::valid()));

        $this->assertNotNull($violation, 'the schema accepts a payload PART 12 §12(d) calls invalid');
        $this->assertStringContainsString($expected, $violation);
    }

    /**
     * And the CODEC consults it. Separate from the assertion above on purpose:
     * a schema that rejects everything and a decoder that ignores the schema
     * produce the same green run when they are asserted together.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $break
     * @param string $expected
     * @param string|null $fromDecoder
     */
    #[DataProvider('invalidShapes')]
    public function testTheDecoderRefusesEveryShape(callable $break, string $expected, ?string $fromDecoder = null): void
    {
        try {
            (new AstCodec())->decode($break(self::valid()));
            $this->fail('the decoder accepted a payload the schema rejects');
        } catch (AstDecodeException $e) {
            // TYPED, which is the other half of the clause: two of these shapes
            // used to come back as a bare `TypeError`.
            $this->assertStringContainsString($fromDecoder ?? $expected, $e->getMessage());
            $this->assertStringContainsString(
                $fromDecoder === null ? 'PART 12 §12(d)' : 'PART 12 §11',
                $e->getMessage(),
            );
        }
    }

    /**
     * No shape may fail as anything other than the typed error, which is what
     * §9(b) asks for and what a `TypeError` out of `decodeNode()` was not.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $break
     * @param string $expected
     * @param string|null $fromDecoder
     */
    #[DataProvider('invalidShapes')]
    public function testNoShapeFailsUntyped(callable $break, string $expected, ?string $fromDecoder = null): void
    {
        try {
            (new AstCodec())->decode($break(self::valid()));
        } catch (Throwable $e) {
            $this->assertInstanceOf(AstDecodeException::class, $e, 'refused, but not with the typed error');

            return;
        }

        $this->fail('the decoder accepted a payload the schema rejects');
    }

    /**
     * The vendored schema is the spec repo's, byte for byte.
     *
     * A runtime rule cannot read the submodule - the spec ships as a TEST
     * fixture here - so the copy in `resources/` is what §12(d) consults, and a
     * copy that can drift from its source is a rule that quietly stops being
     * the one that was ruled.
     */
    public function testTheVendoredSchemaMatchesTheSpecSubmodule(): void
    {
        $vendored = dirname(__DIR__, 3) . '/resources/ast-schema.json';
        $upstream = dirname(__DIR__, 3) . '/tests/spec/resources/ast-schema.json';
        if (!is_file($upstream)) {
            $this->markTestSkipped('the spec submodule is not checked out');
        }

        $upstreamSchema = json_decode((string)file_get_contents($upstream), true, 512, JSON_THROW_ON_ERROR);
        $vendoredSchema = json_decode((string)file_get_contents($vendored), true, 512, JSON_THROW_ON_ERROR);

        // NO CARVE-OUT. PART 9 §21a's `comment.delimited` was admitted here
        // while it lived on a draft spec branch and this repository had to keep
        // the released corpus pin; the pin has moved past it, so the exemption
        // is gone with it. An allowance kept after its reason expires does not
        // fail, it just stops comparing the field it names.
        $this->assertSame($upstreamSchema, $vendoredSchema);
    }

    /**
     * A KEYWORD THE SCHEMA STARTS USING AND THE VALIDATOR DOES NOT SUPPORT
     * would be skipped, which means silently accepting everything it was added
     * to reject - the exact failure mode this whole clause exists to end. So
     * the schema is walked and every keyword in it must be one of the two
     * lists.
     */
    public function testTheSchemaUsesNoKeywordTheValidatorIgnores(): void
    {
        $unsupported = [];
        self::collectKeywords(AstSchema::schema(), $unsupported);

        $this->assertSame([], $unsupported);
    }

    /**
     * @param array<mixed> $schema
     * @param array<string> $unsupported
     */
    private static function collectKeywords(array $schema, array &$unsupported): void
    {
        $known = array_merge(AstSchema::SUPPORTED_KEYWORDS, AstSchema::ANNOTATION_KEYWORDS);
        foreach ($schema as $key => $value) {
            // `properties` and `$defs` hold NAMES, not keywords; descend
            // through them without judging the keys.
            if ($key === 'properties' || $key === '$defs') {
                if (is_array($value)) {
                    foreach ($value as $subschema) {
                        if (is_array($subschema)) {
                            self::collectKeywords($subschema, $unsupported);
                        }
                    }
                }

                continue;
            }
            if (is_string($key) && !in_array($key, $known, true)) {
                $unsupported[] = $key;
            }
            if (is_array($value) && ($key === 'allOf' || $key === 'anyOf' || $key === 'oneOf')) {
                foreach ($value as $branch) {
                    if (is_array($branch)) {
                        self::collectKeywords($branch, $unsupported);
                    }
                }

                continue;
            }
            if (is_array($value) && in_array($key, ['items', 'if', 'then', 'additionalProperties'], true)) {
                self::collectKeywords($value, $unsupported);
            }
        }
    }

    /**
     * THE SHAPE ASSUMPTIONS THE VALIDATOR IS ALLOWED TO MAKE, asserted here so
     * they are not defended against in code no input reaches.
     *
     * Each one replaced a branch that could not be exercised: a `type` written
     * as a LIST of names, a composition branch that is not a schema, a bounded
     * field with no declared type, and a `$ref` that does not resolve. A guard
     * for a case that cannot arise cannot be wrong and cannot be right either -
     * and the ones here are the assumptions whose violation would make the
     * validator silently accept rather than loudly fail.
     */
    public function testTheSchemaSpellsEveryTypeAsOneSupportedName(): void
    {
        $supported = ['object', 'array', 'string', 'integer', 'number', 'boolean'];
        $wrong = [];
        self::walk(AstSchema::schema(), static function (array $node) use ($supported, &$wrong): void {
            if (!array_key_exists('type', $node) || is_array($node['type'])) {
                if (is_array($node['type'] ?? null)) {
                    $wrong[] = 'a type written as a list';
                }

                return;
            }
            if (!is_string($node['type']) || !in_array($node['type'], $supported, true)) {
                $wrong[] = (string)json_encode($node['type']);
            }
        });

        $this->assertSame([], $wrong);
    }

    public function testEveryCompositionBranchIsASchema(): void
    {
        $wrong = [];
        self::walk(AstSchema::schema(), static function (array $node) use (&$wrong): void {
            foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
                if (!array_key_exists($keyword, $node)) {
                    continue;
                }
                if (!is_array($node[$keyword]) || $node[$keyword] === []) {
                    $wrong[] = $keyword . ' is not a non-empty list';

                    continue;
                }
                foreach ($node[$keyword] as $branch) {
                    if (!is_array($branch)) {
                        $wrong[] = $keyword . ' holds a branch that is not a schema';
                    }
                }
            }
        });

        $this->assertSame([], $wrong);
    }

    public function testEveryBoundedFieldAlsoDeclaresItsType(): void
    {
        $wrong = [];
        self::walk(AstSchema::schema(), static function (array $node) use (&$wrong): void {
            foreach (['minimum', 'exclusiveMinimum', 'maximum'] as $keyword) {
                if (array_key_exists($keyword, $node) && !in_array($node['type'] ?? null, ['integer', 'number'], true)) {
                    $wrong[] = $keyword . ' without a numeric type';
                }
            }
        });

        $this->assertSame([], $wrong);
        // And the walk found some, or this proves nothing.
        $bounded = 0;
        self::walk(AstSchema::schema(), static function (array $node) use (&$bounded): void {
            if (array_key_exists('minimum', $node) || array_key_exists('exclusiveMinimum', $node) || array_key_exists('maximum', $node)) {
                $bounded++;
            }
        });
        $this->assertGreaterThan(0, $bounded, 'no bounded field was examined');
    }

    public function testARefThatCannotResolveConstrainsNothing(): void
    {
        // The two ways `resolve()` gives up. It returns an empty schema rather
        // than throwing, so a schema mistake cannot turn into a runtime failure
        // on a payload that has done nothing wrong - and the test above is what
        // says this schema contains no such ref.
        $this->assertSame([], AstSchema::resolve('https://example.com/schema', AstSchema::schema()));
        $this->assertSame([], AstSchema::resolve('#/$defs/not_a_definition', AstSchema::schema()));
    }

    public function testEveryRefInTheSchemaResolves(): void
    {
        $schema = AstSchema::schema();
        $unresolved = [];
        $seen = 0;
        self::walk($schema, static function (array $node) use ($schema, &$unresolved, &$seen): void {
            if (!is_string($node['$ref'] ?? null)) {
                return;
            }
            $seen++;
            if (AstSchema::resolve($node['$ref'], $schema) === []) {
                $unresolved[] = $node['$ref'];
            }
        });

        $this->assertSame([], $unresolved);
        $this->assertGreaterThan(0, $seen, 'no ref was examined');
    }

    /**
     * Every SCHEMA OBJECT in the tree.
     *
     * `properties` and `$defs` are keyed by NAME, not by keyword, so their
     * members are schemas while the maps themselves are not - and a property
     * legitimately named `type` is not a `type` keyword. Descending blindly
     * reported six of those as violations, which is the walk being wrong rather
     * than the schema.
     *
     * @param array<mixed> $node
     * @param callable(array<mixed>): void $visit
     */
    private static function walk(array $node, callable $visit): void
    {
        $visit($node);
        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($key === 'properties' || $key === '$defs') {
                foreach ($value as $subschema) {
                    if (is_array($subschema)) {
                        self::walk($subschema, $visit);
                    }
                }

                continue;
            }
            if (in_array($key, ['allOf', 'anyOf', 'oneOf'], true)) {
                foreach ($value as $branch) {
                    if (is_array($branch)) {
                        self::walk($branch, $visit);
                    }
                }

                continue;
            }
            if (in_array($key, ['items', 'if', 'then', 'additionalProperties'], true)) {
                self::walk($value, $visit);
            }
        }
    }

    /**
     * An application's own node type is outside the schema by construction, so
     * §12(d) has nothing to decide about it - and the exemption is by NAME, so
     * it cannot be used to smuggle a core type past the rule.
     */
    public function testARegisteredApplicationTypeIsExempt(): void
    {
        $payload = self::valid();
        $payload['children'][0]['children'][0] = ['type' => 'my_app_widget', 'whatever' => 1];

        $this->assertNotNull(
            AstSchema::firstViolation($payload),
            'without the exemption the schema refuses an unlisted type',
        );
        $this->assertNull(
            AstSchema::firstViolation($payload, ['my_app_widget']),
            'a registered type and its subtree are not the schema\'s business',
        );

        $core = self::valid();
        $core['children'][0]['children'][0]['value'] = 7;
        $this->assertNotNull(
            AstSchema::firstViolation($core, ['my_app_widget']),
            'the exemption must not reach a core type',
        );
    }
}
