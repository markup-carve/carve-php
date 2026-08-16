<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\EscapesCarveConstructs;

/**
 * Reaches the trait the converters use.
 *
 * `escapePlainCarveInlineSyntax` and the profile constants are protected: they
 * are one internal rule, not public API, and exposing them for a test would
 * widen the surface the org already has four spellings of. A class that USES
 * the trait runs exactly the code the converters run, and takes its profiles
 * from the trait's own constants rather than restating them - a restatement
 * would let a converter drift and still pass every case here.
 */
class EscaperHarness
{
    use EscapesCarveConstructs;

    /**
     * @return array<string, array<string, string>>
     */
    public static function profiles(): array
    {
        return [
            'plain' => self::HANDLED_PLAIN,
            'markdown' => self::HANDLED_MARKDOWN,
            'djot' => self::HANDLED_DJOT,
        ];
    }

    /**
     * @param string $line
     * @param array<string, string> $handled
     */
    public static function escape(string $line, array $handled): string
    {
        return (new self())->escapePlainCarveInlineSyntax($line, $handled);
    }
}
