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
    #[DataProvider('caseInsensitiveProvider')]
    public function testANameMatchesCaseInsensitively(string $child, string $name, string $expected): void
    {
        [$carve, $rules] = $this->expand("{{ child.crv #{$name} }}", ['child.crv' => $child]);

        $this->assertSame($expected, $carve);
        $this->assertSame([], $rules);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function caseInsensitiveProvider(): iterable
    {
        yield 'lowercase name, mixed-case explicit id' => [
            "{#Plan}\n# Plan\n\nplan text\n",
            'plan',
            "{#Plan}\n# Plan\n\nplan text\n",
        ];

        yield 'uppercase name, explicit id' => [
            "{#plan}\n# Plan\n\nplan text\n",
            'PLAN',
            "{#plan}\n# Plan\n\nplan text\n",
        ];

        yield 'lowercase name, case-preserved auto slug' => [
            "# Intro\n\nskip\n\n# Getting Started\n\nkeep\n",
            'getting-started',
            "# Getting Started\n\nkeep\n",
        ];

        yield 'exact spelling still matches' => [
            "{#Plan}\n# Plan\n\nplan text\n",
            'Plan',
            "{#Plan}\n# Plan\n\nplan text\n",
        ];

        yield 'first case-insensitive match in document order wins' => [
            "{#plan}\n# First\n\none\n\n{#Plan}\n# Second\n\ntwo\n",
            'Plan',
            "{#plan}\n# First\n\none\n",
        ];

        yield 'a deduplicated slug selects the second heading' => [
            "# Intro\n\none\n\n# Intro\n\ntwo\n",
            'INTRO-2',
            "# Intro\n\ntwo\n",
        ];
    }

    public function testANameThatMatchesNoSpellingStillWarns(): void
    {
        [$carve, $rules] = $this->expand('{{ child.crv #plans }}', ['child.crv' => "{#Plan}\n# Plan\n"]);

        $this->assertSame("{{ child.crv #plans }}\n", $carve);
        $this->assertSame([IncludeExpander::RULE_SECTION], $rules);
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
