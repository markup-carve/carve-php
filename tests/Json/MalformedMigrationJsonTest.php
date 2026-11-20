<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeError;
use PHPUnit\Framework\TestCase;

final class MalformedMigrationJsonTest extends TestCase
{
    public function testMalformedJsonUsesTreeError(): void
    {
        foreach (['{bad', '{', '[', '"unterminated'] as $input) {
            try {
                Carve::migrateTree($input);
                self::fail('Expected a tree error for ' . $input);
            } catch (TreeError $error) {
                self::assertSame('schema', $error->reason);
                self::assertSame('', $error->pointer);
            }
        }
    }
}
