<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Migration;

use MarkupCarve\Carve\Migration\Carve01To02Migrator;
use PHPUnit\Framework\TestCase;

final class Carve01To02MigratorTest extends TestCase
{
    public function testItMakesAnImplicitBoundaryExplicitAndIsIdempotent(): void
    {
        $migrated = Carve01To02Migrator::migrate("intro\n# Heading\n");

        self::assertSame("intro\n\n# Heading\n", $migrated);
        self::assertSame($migrated, Carve01To02Migrator::migrate($migrated));
    }

    public function testItDoesNotOpenAnUnterminatedCodeFence(): void
    {
        self::assertSame("intro\n```\n", Carve01To02Migrator::migrate("intro\n```\n"));
    }
}
