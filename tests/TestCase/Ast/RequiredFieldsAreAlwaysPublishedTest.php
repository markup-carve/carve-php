<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A field the spec schema REQUIRES is published even when it holds the default.
 *
 * The encoder omits a field that carries the node's default, which is right for
 * an optional field and is how the payload stays small. It is wrong for a
 * required one: the result is not a smaller equivalent tree, it is a tree the
 * format rejects.
 *
 * `AstCodec::ALWAYS_PUBLISHED` is the list that carves out the required fields,
 * and it was a hand-maintained copy of the schema's `required` with nothing
 * comparing the two. It had drifted: an emptied `definition_description`
 * published without `children`, invalid per the schema, and nothing here
 * noticed because the codec's own golden schema is self-consistent
 * (carve-php#915).
 *
 * The shape stayed invisible until corpus 227 arrived, because before it no
 * definition entry could be empty - a description with no content is not
 * something the parser builds from source, only something COLLECTION leaves
 * behind.
 */
class RequiredFieldsAreAlwaysPublishedTest extends TestCase
{
    /**
     * @return array<string, array<string>> wire type => required field names
     */
    protected function requiredBySpec(): array
    {
        $path = dirname(__DIR__, 3) . '/tests/spec/resources/ast-schema.json';
        if (!is_file($path)) {
            $this->markTestSkipped('spec submodule missing; run `git submodule update --init`');
        }
        $schema = json_decode((string)file_get_contents($path), true);
        $required = [];
        foreach ($schema['$defs'] ?? [] as $definition) {
            $type = $definition['properties']['type']['const'] ?? null;
            if (!is_string($type)) {
                continue;
            }
            // `type` itself is on every node by construction; the list exists
            // for the fields an omit-the-default rule could drop.
            $required[$type] = array_values(array_filter(
                $definition['required'] ?? [],
                static fn (string $field): bool => $field !== 'type',
            ));
        }

        return $required;
    }

    public function testEveryFieldTheSchemaRequiresIsAlwaysPublished(): void
    {
        $reflection = new ReflectionClass(AstCodec::class);
        /** @var array<string> $published */
        $published = $reflection->getConstant('ALWAYS_PUBLISHED');
        $missing = [];
        foreach ($this->requiredBySpec() as $type => $fields) {
            foreach ($fields as $field) {
                if (!in_array($type . '.' . $field, $published, true)) {
                    $missing[] = $type . '.' . $field;
                }
            }
        }

        $this->assertSame([], $missing, 'the schema requires these, and the encoder may omit them');
    }

    public function testTheSweepSawSomethingToCheck(): void
    {
        // The control on the assertion above, which passes for an empty schema.
        $this->assertGreaterThan(50, count($this->requiredBySpec()));
    }

    public function testAnEmptiedDefinitionDescriptionStillPublishesChildren(): void
    {
        // The reported case, end to end. Collecting the definition out of the
        // description empties the `dd`, and the published node has to keep the
        // field the schema requires.
        $document = (new BlockParser(false, false, false, true))->parse(":: term\n:  [^f]: x\n\nsee[^f]\n");
        $tree = (new AstCodec())->encode($document);
        $entries = $tree['children'][0]['items'];

        $this->assertSame('definition_description', $entries[1]['type']);
        $this->assertArrayHasKey('children', $entries[1]);
        $this->assertSame([], $entries[1]['children']);
    }

    public function testAnOptionalFieldIsStillOmittedWhenItHoldsTheDefault(): void
    {
        // The boundary. Publishing everything would satisfy the assertions
        // above and undo the rule that keeps small documents small.
        $document = (new BlockParser())->parse("text\n");
        $tree = (new AstCodec())->encode($document);

        $this->assertArrayNotHasKey('attrs', $tree['children'][0]);
    }
}
