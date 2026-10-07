<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeDirectiveSyntax;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * How the two slots after the path are SPELLED (carve#2773, carve#2934).
 *
 * The whitespace before `#section` and before each `@key:value` is optional in
 * every position, and a tab separates as a space does. The expected slots are
 * the arbiter's - markup-carve/carve scripts/spec/include-directive.mjs - not a
 * reading of the grammar.
 */
class AnIncludeDirectiveSpellsItsSlotsWithoutWhitespaceTest extends TestCase
{
    /**
     * @param string $source
     * @param array{path: string, section: string|null, shift: int|string} $expected
     */
    #[DataProvider('spellingProvider')]
    public function testEachSpellingReadsTheSameSlots(string $source, array $expected): void
    {
        $parsed = IncludeDirectiveSyntax::parse($source);

        $this->assertIsArray($parsed, $source);
        $this->assertSame($expected['path'], $parsed['path'], $source);
        $this->assertSame($expected['section'], $parsed['section'], $source);
        $this->assertSame($expected['shift'], $parsed['shift'], $source);
        $this->assertNull($parsed['error'], $source);
    }

    /**
     * @return iterable<string, array{string, array{path: string, section: string|null, shift: int|string}}>
     */
    public static function spellingProvider(): iterable
    {
        yield 'a section name needs no space in front of it' => [
            '{{ c.crv#Alpha }}',
            ['path' => 'c.crv', 'section' => 'Alpha', 'shift' => 0],
        ];

        yield 'a quoted path takes an adjacent name too' => [
            '{{ "c.crv"#Alpha }}',
            ['path' => 'c.crv', 'section' => 'Alpha', 'shift' => 0],
        ];

        yield 'an option needs no space after a path' => [
            '{{ c.crv@shift:1 }}',
            ['path' => 'c.crv', 'section' => null, 'shift' => 1],
        ];

        yield 'an option needs no space after a section name' => [
            '{{ c.crv #Alpha@shift:1 }}',
            ['path' => 'c.crv', 'section' => 'Alpha', 'shift' => 1],
        ];

        yield 'adjacent in both slots at once' => [
            '{{ c.crv#Alpha@shift:2 }}',
            ['path' => 'c.crv', 'section' => 'Alpha', 'shift' => 2],
        ];

        yield 'a tab separates the path from the name' => [
            "{{ c.crv\t#Alpha }}",
            ['path' => 'c.crv', 'section' => 'Alpha', 'shift' => 0],
        ];

        yield 'a tab separates an option too' => [
            "{{ c.crv\t#Alpha\t@shift:1 }}",
            ['path' => 'c.crv', 'section' => 'Alpha', 'shift' => 1],
        ];

        yield 'the spaced control still reads the same slots' => [
            '{{ c.crv #Alpha @shift:1 }}',
            ['path' => 'c.crv', 'section' => 'Alpha', 'shift' => 1],
        ];
    }

    /**
     * The grammar holds AT MOST ONE `include_section`, so a second name is not
     * a directive the author can mean. Taking the last one silently returned
     * the wrong fragment - the one outcome section 19 forbids, because nothing
     * on the page says a selector was dropped.
     */
    public function testASecondSectionNameIsRefusedRatherThanOverridingTheFirst(): void
    {
        $parsed = IncludeDirectiveSyntax::parse('{{ c.crv #Alpha #Beta }}');

        $this->assertIsArray($parsed);
        $this->assertSame('Alpha', $parsed['section']);
        $this->assertSame(IncludeDirectiveSyntax::ERROR_DUPLICATE_SECTION, $parsed['error']);
        $this->assertSame('#Beta', $parsed['errorPart']);
    }

    public function testASecondSectionNameWarnsAndLeavesTheDirectiveLiteral(): void
    {
        [$carve, $rules] = $this->expand(
            '{{ c.crv #Alpha #Beta }}',
            ['c.crv' => "{#Alpha}\nalpha\n\n{#Beta}\nbeta\n"],
        );

        $this->assertStringContainsString('{{ c.crv #Alpha #Beta }}', $carve);
        $this->assertStringNotContainsString('beta', $carve);
        $this->assertSame([IncludeExpander::RULE_SELECTION_CONFLICT], $rules);
    }

    /**
     * The whole point of the spelling rules: an adjacent spelling selects what
     * its spaced spelling selects, and expands.
     */
    #[DataProvider('adjacentExpansionProvider')]
    public function testAnAdjacentSpellingExpandsTheSameFragment(string $source, string $expected): void
    {
        [$carve, $rules] = $this->expand($source, ['c.crv' => "{#Alpha}\n# Alpha\n\nalpha text\n"]);

        $this->assertSame($expected, $carve);
        $this->assertSame([], $rules);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function adjacentExpansionProvider(): iterable
    {
        yield 'spaced control' => ['{{ c.crv #Alpha }}', "{#Alpha}\n# Alpha\n\nalpha text\n"];
        yield 'adjacent name' => ['{{ c.crv#Alpha }}', "{#Alpha}\n# Alpha\n\nalpha text\n"];
        yield 'quoted path, adjacent name' => ['{{ "c.crv"#Alpha }}', "{#Alpha}\n# Alpha\n\nalpha text\n"];
        yield 'tab before the name' => ["{{ c.crv\t#Alpha }}", "{#Alpha}\n# Alpha\n\nalpha text\n"];
        yield 'adjacent name and option' => ['{{ c.crv#Alpha@shift:1 }}', "{#Alpha}\n## Alpha\n\nalpha text\n"];
        yield 'adjacent option after a spaced name' => ['{{ c.crv #Alpha@shift:1 }}', "{#Alpha}\n## Alpha\n\nalpha text\n"];
    }

    /**
     * The padding around the whole directive stays required on both sides.
     */
    #[DataProvider('unpaddedProvider')]
    public function testThePaddingAroundTheDirectiveIsStillRequired(string $source): void
    {
        $this->assertNull(IncludeDirectiveSyntax::parse($source), $source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unpaddedProvider(): iterable
    {
        yield 'no opening padding' => ['{{c.crv@shift:1 }}'];
        yield 'no closing padding' => ['{{ c.crv@shift:1}}'];
        yield 'no padding at all' => ['{{c.crv#Alpha}}'];
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     *
     * @return array{string, list<string|null>}
     */
    protected function expand(string $source, array $files): array
    {
        $converter = CarveConverter::carve();
        $expander = new IncludeExpander($this->resolver($files));
        $carve = $converter->render($converter->transform($converter->parse($source), $expander));
        $rules = array_map(static fn ($warning): ?string => $warning->getRule(), $expander->getWarnings());

        return [$carve, $rules];
    }

    /**
     * @param array<string, string> $files
     *
     * @throws \RuntimeException
     */
    protected function resolver(array $files): IncludeResolverInterface
    {
        return new class ($files) implements IncludeResolverInterface {
            /**
             * @param array<string, string> $files
             */
            public function __construct(private readonly array $files)
            {
            }

            public function resolve(string $path, IncludeContext $context): string
            {
                if (!array_key_exists($path, $this->files)) {
                    throw new RuntimeException("Missing include: {$path}");
                }

                return $this->files[$path];
            }
        };
    }
}
