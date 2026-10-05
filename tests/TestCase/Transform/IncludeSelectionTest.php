<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What `{{ path #name }}` selects (spec PART 9 section 19, I1a).
 */
class IncludeSelectionTest extends TestCase
{
    /**
     * @param string $child
     * @param string $name
     * @param string $expected
     */
    #[DataProvider('exactNameProvider')]
    public function testANameMatchesExactly(string $child, string $name, string $expected): void
    {
        [$carve, $rules] = $this->expand("{{ child.crv #{$name} }}", ['child.crv' => $child]);

        $this->assertSame($expected, $carve);
        $this->assertSame([], $rules);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function exactNameProvider(): iterable
    {
        yield 'exact spelling of a mixed-case explicit id' => [
            "{#Plan}\n# Plan\n\nplan text\n",
            'Plan',
            "{#Plan}\n# Plan\n\nplan text\n",
        ];

        yield 'exact spelling of a case-preserved auto slug' => [
            "# Intro\n\nskip\n\n# Getting Started\n\nkeep\n",
            'Getting-Started',
            "# Getting Started\n\nkeep\n",
        ];

        yield 'ids differing only in case are distinct' => [
            "{#plan}\n# First\n\none\n\n{#Plan}\n# Second\n\ntwo\n",
            'Plan',
            "{#Plan}\n# Second\n\ntwo\n",
        ];

        yield 'a deduplicated slug selects the second heading' => [
            "# Intro\n\none\n\n# Intro\n\ntwo\n",
            'Intro-2',
            "# Intro\n\ntwo\n",
        ];
    }

    /**
     * @param string $child
     * @param string $name
     */
    #[DataProvider('otherCaseProvider')]
    public function testANameInAnotherCaseSelectsNothing(string $child, string $name): void
    {
        [$carve, $rules] = $this->expand("{{ child.crv #{$name} }}", ['child.crv' => $child]);

        $this->assertSame("{{ child.crv #{$name} }}\n", $carve);
        $this->assertSame([IncludeExpander::RULE_SECTION], $rules);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function otherCaseProvider(): iterable
    {
        yield 'lowercase name, mixed-case explicit id' => ["{#Plan}\n# Plan\n\nplan text\n", 'plan'];
        yield 'uppercase name, lowercase explicit id' => ["{#plan}\n# Plan\n\nplan text\n", 'PLAN'];
        yield 'lowercase name, case-preserved auto slug' => ["# Getting Started\n\nkeep\n", 'getting-started'];
        yield 'uppercase name, deduplicated slug' => ["# Intro\n\none\n\n# Intro\n\ntwo\n", 'INTRO-2'];
        yield 'lowercase name, mixed-case block id' => ["{#Dough}\nknead\n", 'dough'];
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     * @param string $expected
     */
    #[DataProvider('selectionProvider')]
    public function testANameSelectsAHeadingSectionOrABlock(string $source, array $files, string $expected): void
    {
        [$carve, $rules] = $this->expand($source, $files);

        $this->assertSame($expected, $carve);
        $this->assertSame([], $rules);
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function selectionProvider(): iterable
    {
        yield 'a block id selects that block alone, id included' => [
            '{{ r.crv #dough }}',
            ['r.crv' => "# Pizza\n\n{#dough}\n```text\n500 g flour\n```\n\nafter\n"],
            "{#dough}\n```text\n500 g flour\n```\n",
        ];

        yield 'a heading keeps selecting its section' => [
            '{{ r.crv #pick }}',
            ['r.crv' => "# A\n\nskip\n\n{#pick}\n# B\n\nyes\n\n## C\n\nmore\n\n# D\n"],
            "{#pick}\n# B\n\nyes\n\n## C\n\nmore\n",
        ];

        yield 'a heading wins over an earlier block with the same id' => [
            '{{ r.crv #x }}',
            ['r.crv' => "{#x}\nfirst\n\n{#x}\n# H\n\nbody\n"],
            "{#x}\n# H\n\nbody\n",
        ];

        yield 'an auto slug is a heading id, so it beats a block' => [
            '{{ r.crv #Hello }}',
            ['r.crv' => "{#Hello}\npara\n\n# Hello\n\nbody\n"],
            "# Hello\n\nbody\n",
        ];

        yield 'a container precedes its contents' => [
            '{{ r.crv #x }}',
            ['r.crv' => "{#x}\n> {#x}\n> inner\n\n{#x}\nlater\n"],
            "{#x}\n> {#x}\n> inner\n",
        ];

        yield 'the first block in document order wins' => [
            '{{ r.crv #x }}',
            ['r.crv' => "{#x}\nonce\n\n{#x}\ntwice\n"],
            "{#x}\nonce\n",
        ];

        yield 'a block at depth inside a list item' => [
            '{{ r.crv #z }}',
            ['r.crv' => "- a\n\n  {#z}\n  ```\n  code\n  ```\n"],
            "{#z}\n```\ncode\n```\n",
        ];

        yield 'a block inside a captioned quote' => [
            '{{ r.crv #x }}',
            ['r.crv' => "> {#x}\n> inner\n\n^ Figure: caption\n"],
            "{#x}\ninner\n",
        ];

        yield 'a block inside a definition' => [
            '{{ r.crv #dd }}',
            ['r.crv' => ":: term\n: {#dd}\n  def para\n"],
            "{#dd}\ndef para\n",
        ];

        yield 'a heading inside a quote ends with the quote' => [
            '{{ r.crv #q }}',
            ['r.crv' => "> {#q}\n> ## Q\n>\n> in\n\nafter\n"],
            "{#q}\n## Q\n\nin\n",
        ];

        yield 'a heading inside a div ends at its next sibling heading or the div' => [
            '{{ r.crv #Q }}',
            ['r.crv' => "::: note\n## Q\n\nin\n\n### Sub\n\nmore\n\n## R\n\nno\n:::\n\nafter\n"],
            "## Q\n\nin\n\n### Sub\n\nmore\n",
        ];

        yield 'a block image is a block' => [
            '{{ r.crv #im }}',
            ['r.crv' => "intro\n\n![a](a.png){#im}\n"],
            "![a](a.png){#im}\n",
        ];

        yield 'a digit-leading name' => [
            '{{ p.crv #2024-plan }}',
            ['p.crv' => "{#2024-plan}\n```text\nship it\n```\n\nafter\n"],
            "{#2024-plan}\n```text\nship it\n```\n",
        ];

        yield 'a block id matches exactly' => [
            '{{ r.crv #Dough }}',
            ['r.crv' => "{#Dough}\nknead\n"],
            "{#Dough}\nknead\n",
        ];

        yield 'an inline include of a selected paragraph splices its inlines only' => [
            'See {{ r.crv #p }} now.',
            ['r.crv' => "{#p}\nhello *x*\n\nother\n"],
            "See hello *x* now.\n",
        ];

        yield 'the shift option reaches a heading inside a selected block' => [
            '{{ r.crv #d @shift:1 }}',
            ['r.crv' => "{#d}\n:::\n# A\n\ntext\n:::\n"],
            "{#d}\n:::\n## A\n\ntext\n:::\n",
        ];

        yield 'an automatic shift is measured over the selected block' => [
            "## P\n\n{{ r.crv #d @shift:auto }}",
            ['r.crv' => "# Outside\n\n{#d}\n:::\n### A\n:::\n"],
            "## P\n\n{#d}\n:::\n### A\n:::\n",
        ];

        yield 'an automatic shift on a block without headings is a no-op' => [
            "## P\n\n{{ r.crv #d @shift:auto }}",
            ['r.crv' => "# Outside\n\n{#d}\nplain\n"],
            "## P\n\n{#d}\nplain\n",
        ];
    }

    /**
     * @param string $child
     * @param string $name
     */
    #[DataProvider('selectsNothingProvider')]
    public function testAnIdOutsideABlockSequenceSelectsNothing(string $child, string $name): void
    {
        $directive = "{{ r.crv #{$name} }}";
        [$carve, $rules] = $this->expand($directive, ['r.crv' => $child]);

        $this->assertStringContainsString('r.crv', $carve);
        $this->assertSame([IncludeExpander::RULE_SECTION], $rules);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function selectsNothingProvider(): iterable
    {
        yield 'list item' => ["-{#li} item\n- two\n", 'li'];
        yield 'table row' => ["| a |{#row}\n", 'row'];
        yield 'inline span' => ["x [span]{#sp} y\n", 'sp'];
        yield 'inline image' => ["![a](a){#im}![b](b)\n", 'im'];
        yield 'block inside a footnote' => ["See[^n].\n\n[^n]: {#w}\n    note\n", 'w'];
        yield 'no such id' => ["{#p}\npara\n", 'nope'];
    }

    public function testANameTheIdClassCannotSpellLeavesTheDirectiveLiteral(): void
    {
        [$carve, $rules] = $this->expand('{{ p.crv #-x }}', ['p.crv' => "{#x}\nhi\n"]);

        $this->assertSame("{{ p.crv #-x }}\n", $carve);
        $this->assertSame([], $rules);
    }

    public function testAnInlineIncludeOfASelectedCodeBlockIsBlockInInline(): void
    {
        [, $rules] = $this->expand('See {{ r.crv #c }} now.', ['r.crv' => "{#c}\n```\ncode\n```\n"]);

        $this->assertSame([IncludeExpander::RULE_BLOCK_IN_INLINE], $rules);
    }

    public function testASelectedBlockThatIncludesItsOwnFileIsACycle(): void
    {
        [$carve, $rules] = $this->expand('{{ a.crv #x }}', ['a.crv' => "{#x}\n:::\n{{ a.crv }}\n:::\n\nafter\n"]);

        $this->assertSame("{#x}\n:::\n{{ a.crv }}\n:::\n", $carve);
        $this->assertSame([IncludeExpander::RULE_CYCLE], $rules);
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
