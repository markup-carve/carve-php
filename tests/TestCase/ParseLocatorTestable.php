<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\Extension\CitationsExtension;

/**
 * Test subclass that exposes the protected parseLocator() method for unit testing.
 */
class ParseLocatorTestable extends CitationsExtension
{
    /**
     * @return array{label?: string, value?: string, suffixText?: string}
     */
    public function parseLocatorPublic(string $loc): array
    {
        return $this->parseLocator($loc);
    }
}
