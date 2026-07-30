<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
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
        // one-way format: encodable, not readable.
        foreach (array_keys(AstCodec::schema()) as $type) {
            $decoded = (new AstCodec())->decode(['type' => 'document', 'children' => [['type' => $type]]]);

            $this->assertSame($type, $decoded->getChildren()[0]->getType());
        }
    }
}
