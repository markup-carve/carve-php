<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `kind` is the OPENER WORD, and it is not also a class.
 *
 * This engine keeps an admonition's kind as the first class, so taking the whole
 * `class` attribute happened to be right until an attribute line appended to it:
 * `{.x}` above a `::: note` published `kind: "note x"`, a string that is not a
 * kind and that no consumer can match against the Tier-1 list. It also repeated
 * the kind inside `attrs.classes`, where carve-js and carve-rs both keep only
 * what the attribute line contributed (carve-php#552).
 */
class AdmonitionKindShapeTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>|null}>
     */
    public static function shapeProvider(): array
    {
        return [
            // source, expected kind, expected attrs.classes
            'attribute line adds a class' => ["{.x}\n::: note\nbody\n:::\n", 'note', ['x']],
            'plain Tier-1 opener' => ["::: note\nbody\n:::\n", 'note', null],
            'plain Tier-2 opener' => ["::: sidebar\nbody\n:::\n", 'sidebar', null],
            'two added classes' => ["{.x .y}\n::: warning\nbody\n:::\n", 'warning', ['x', 'y']],
        ];
    }

    /**
     * @param list<string>|null $expectedClasses
     */
    #[DataProvider('shapeProvider')]
    public function testKindIsTheOpenerWordAndNotAlsoAClass(
        string $source,
        string $expectedKind,
        ?array $expectedClasses,
    ): void {
        $encoded = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $div = $encoded['children'][0];

        $this->assertSame($expectedKind, $div['kind'] ?? null, 'published kind');
        $this->assertSame($expectedClasses, $div['attrs']['classes'] ?? null, 'published classes');
    }

    public function testTheAttributeOrderSurvivesTheRoundTrip(): void
    {
        // The opener class is stored FIRST and the renderer emits attributes in
        // storage order, so re-adding it after the attribute map was built put
        // `title` ahead of `class` and changed the rendered HTML.
        $source = "{title=\"attr title\"}\n::: note \"opener title\"\nBody.\n:::\n";
        $codec = new AstCodec();

        $document = (new CarveConverter())->parse($source);
        $expected = (new CarveConverter())->render($document);
        $actual = (new CarveConverter())->render($codec->decode($codec->encode($document)));

        $this->assertSame($expected, $actual);
    }
}
