<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Migration\Carve01To02Migrator;

/** Runs pre-0.2 regression fixtures through the supported source migration. */
class LegacyCarveConverter extends CarveConverter
{
    public function convert(string $djot): string
    {
        return parent::convert(Carve01To02Migrator::migrate($djot));
    }
}
