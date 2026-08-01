<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
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
        foreach (AstCodec::schema() as $type => $entry) {
            $payload = ['type' => $type];
            foreach ($entry['required'] as $field) {
                // A value of the property's own type; the point is presence, not
                // meaning, so arrays get [] and everything else an empty string.
                // Presence is the point, not meaning: arrays need an array, and
                // the array-typed required fields are known from the schema.
                $payload[$field] = in_array($field, ['items'], true) ? [] : '';
            }

            $decoded = (new AstCodec())->decode(['type' => 'document', 'children' => [$payload]]);

            $this->assertSame($type, $decoded->getChildren()[0]->getType());
        }
    }

    public function testOmittingARequiredFieldIsRejected(): void
    {
        // The alternative was inventing a scalar zero, which rendered a heading
        // without a level as <h0> instead of failing.
        $required = array_filter(AstCodec::schema(), static fn (array $entry): bool => $entry['required'] !== []);
        $this->assertNotSame([], $required, 'expected at least one type with a required field');

        $type = (string)array_key_first($required);

        $this->expectExceptionMessage(sprintf('Node "%s" is missing the required field', $type));

        (new AstCodec())->decode(['type' => 'document', 'children' => [['type' => $type]]]);
    }

    /**
     * A formatter-internal node is not in the published schema either.
     *
     * The class map is built by reflection, so an internal node class is
     * advertised by default - which is how `raw_text` stayed in this schema
     * after the encoder stopped emitting it: a consumer validating against
     * `AstCodec::schema()` was still told about a type the encoder cannot
     * produce and the spec's own schema rejects (PART 12 §5).
     */
    public function testAFormatterInternalTypeIsNotAdvertised(): void
    {
        $schema = AstCodec::schema();

        foreach (AstCodec::NOT_ON_THE_WIRE as $type) {
            $this->assertArrayNotHasKey($type, $schema);
        }
        // The list is not empty, or this test would pass by describing nothing.
        $this->assertNotSame([], AstCodec::NOT_ON_THE_WIRE);
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
