<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class CorpusPopulationTest extends TestCase
{
    public function testMultiplePairsAndLiteralFences(): void
    {
        $source = "````text\n::: compare\n```carve\nfake\n```\n```html\nfake\n```\n:::\n````\n::: compare no-render\n````carve\n::: compare\n```html\nliteral\n```\n:::\n````\n```html\n<p>first</p>\n```\n```carve\nsecond\n```\n```html\n<p>second</p>\n```\n:::\n";
        $this->assertSame(2, CorpusPopulation::countPairs($source));
        $this->assertSame(2, CorpusPopulation::countPairs(str_replace("\n", "\r\n", $source)));
    }

    public function testInvalidSourcesAreRefused(): void
    {
        foreach (["::: compare\n:::", "::: compare\n```carve\nx\n```\n:::", "::: compare\n```html\nx\n```\n:::", '::: compare'] as $source) {
            $error = null;
            try {
                CorpusPopulation::countPairs($source);
            } catch (RuntimeException $caught) {
                $error = $caught;
            }
            $this->assertNotNull($error, 'Invalid source must fail');
            $this->assertMatchesRegularExpression('/unpaired|unclosed/', $error->getMessage());
        }
    }
}
