<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 sections 9a and 11a, rendered against the spec's shared fixture.
 */
class MarkdownWriterTargetsFixtureTest extends TestCase
{
    /**
     * @return array<string, array{array{name: string, carve?: string, ast?: array<string, mixed>, markdown: string}}>
     */
    public static function cases(): array
    {
        /** @var array<array{name: string, carve?: string, ast?: array<string, mixed>, markdown: string}> $cases */
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../spec/tests/fixtures/markdown-writer-targets.json'), true, flags: JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($cases as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    public function testTheFixtureIsNotEmpty(): void
    {
        $this->assertGreaterThanOrEqual(3, count(static::cases()));
    }

    /**
     * @param array{name: string, carve?: string, ast?: array<string, mixed>, markdown: string} $case
     */
    #[DataProvider('cases')]
    public function testMarkdownMatchesTheFixture(array $case): void
    {
        $doc = isset($case['carve'])
            ? (new CarveConverter())->parse($case['carve'])
            : (new AstCodec())->decode($case['ast']);

        $this->assertSame($case['markdown'], (new MarkdownRenderer())->render($doc));
    }
}
