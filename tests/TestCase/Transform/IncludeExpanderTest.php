<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\FilesystemIncludeResolver;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use MarkupCarve\Carve\Transform\ResolvedInclude;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class IncludeExpanderTest extends TestCase
{
    public function testNoResolverLeavesDirectiveLiteral(): void
    {
        $converter = new CarveConverter();

        $this->assertSame("<p>See {{ chapter.crv }} here.</p>\n", $converter->convert('See {{ chapter.crv }} here.'));
    }

    public function testVerbatimShieldingKeepsCodeBlockAndInlineCodeLiteral(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("```\n{{ child.crv }}\n```\n\n`{{ child.crv }}`\n");
        $expander = new IncludeExpander($this->resolver(['child.crv' => 'expanded']));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertStringContainsString('{{ child.crv }}', $html);
        $this->assertStringNotContainsString('expanded', $html);
        $this->assertSame([], $expander->getWarnings());
    }

    public function testFragmentContainmentKeepsUnclosedFenceInsideChild(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("Before.\n\n{{ child.crv }}\n\nAfter.\n");
        $expander = new IncludeExpander($this->resolver([
            'child.crv' => "Child.\n\n```js\nlet x = 1;\n",
        ]));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertStringContainsString('<code class="language-js">let x = 1;', $html);
        $this->assertStringContainsString('<p>After.</p>', $html);
        $this->assertSame([], $expander->getWarnings());
    }

    public function testInlineIncludeOfMultiBlockChildWarnsAndStaysLiteral(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('/{{ child.crv }}/');
        $expander = new IncludeExpander($this->resolver([
            'child.crv' => "One.\n\nTwo.\n",
        ]));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertSame("<p><em>{{ child.crv }}</em></p>\n", $html);
        $this->assertSame('include', $expander->getWarnings()[0]->getCategory());
        $this->assertStringContainsString('Inline include resolved to block content', $expander->getWarnings()[0]->getMessage());
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     * @param string $expected
     */
    #[DataProvider('optionProvider')]
    public function testOptionsExpandOnHappyPaths(string $source, array $files, string $expected): void
    {
        $converter = CarveConverter::carve();
        $document = $converter->parse($source);
        $expander = new IncludeExpander($this->resolver($files));

        $carve = $converter->render($converter->transform($document, $expander));

        $this->assertSame($expected, $carve);
        $this->assertSame([], $expander->getWarnings());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function optionProvider(): iterable
    {
        yield 'section' => [
            '{{ child.crv #target }}',
            ['child.crv' => "# Intro\n\nSkip.\n\n{#target}\n## Target\n\nKeep.\n\n## Next\n\nSkip.\n"],
            "{#target}\n## Target\n\nKeep\\.\n",
        ];

        yield 'lines' => [
            '{{ child.crv @lines:2-3 }}',
            ['child.crv' => "One\nTwo\nThree\nFour\n"],
            "Two\nThree\n",
        ];

        yield 'shift' => [
            '{{ child.crv @shift:2 }}',
            ['child.crv' => "# One\n\n## Two\n"],
            "### One\n\n#### Two\n",
        ];
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     * @param string $message
     * @param int $byteBudget
     * @param int $depthLimit
     */
    #[DataProvider('errorProvider')]
    public function testErrorsWarnAndLeaveDirectiveLiteral(
        string $source,
        array $files,
        int $depthLimit,
        int $byteBudget,
        string $message,
    ): void {
        $converter = CarveConverter::carve();
        $document = $converter->parse($source);
        $expander = new IncludeExpander($this->resolver($files), depthLimit: $depthLimit, byteBudget: $byteBudget);

        $carve = $converter->render($converter->transform($document, $expander));

        $literalSource = $message === 'depth limit' ? '{{ b.crv }}' : $source;
        $this->assertSame(trim($converter->render($converter->parse($literalSource))), trim($carve));
        $this->assertStringContainsString($message, $expander->getWarnings()[0]->getMessage());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, int, int, string}>
     */
    public static function errorProvider(): iterable
    {
        yield 'both selections' => [
            '{{ child.crv #part @lines:1-2 }}',
            ['child.crv' => '# Part'],
            16,
            1048576,
            'cannot combine',
        ];

        yield 'unknown option' => [
            '{{ child.crv @unknown:x }}',
            ['child.crv' => 'Text.'],
            16,
            1048576,
            'Unknown include option',
        ];

        yield 'cycle' => [
            '{{ a.crv }}',
            ['a.crv' => '{{ a.crv }}'],
            16,
            1048576,
            'cycle',
        ];

        yield 'depth' => [
            '{{ a.crv }}',
            ['a.crv' => '{{ b.crv }}', 'b.crv' => 'Done.'],
            1,
            1048576,
            'depth limit',
        ];

        yield 'budget' => [
            '{{ a.crv }}',
            ['a.crv' => '123456'],
            16,
            4,
            'size budget',
        ];
    }

    public function testShiftClampWarnsAndKeepsHeading(): void
    {
        $converter = CarveConverter::carve();
        $document = $converter->parse('{{ child.crv @shift:9 }}');
        $expander = new IncludeExpander($this->resolver(['child.crv' => '# Title']));

        $carve = $converter->render($converter->transform($document, $expander));

        $this->assertSame("###### Title\n", $carve);
        $this->assertStringContainsString('clamped', $expander->getWarnings()[0]->getMessage());
    }

    public function testFootnoteCollisionRenamesSecondChildAndItsReference(): void
    {
        $converter = CarveConverter::carve();
        $document = $converter->parse("{{ one.crv }}\n\n{{ two.crv }}");
        $expander = new IncludeExpander($this->resolver([
            'one.crv' => "One[^a].\n\n[^a]: first\n",
            'two.crv' => "Two[^a].\n\n[^a]: second\n",
        ]));

        $carve = $converter->render($converter->transform($document, $expander));

        $this->assertStringContainsString('One[^a]\.', $carve);
        $this->assertStringContainsString('Two[^a-2]\.', $carve);
        $this->assertStringContainsString('[^a]: first', $carve);
        $this->assertStringContainsString('[^a-2]: second', $carve);
        $this->assertStringContainsString('Duplicate footnote label', $expander->getWarnings()[0]->getMessage());
    }

    public function testExplicitHeadingIdCollisionRenamesSecondChildAndItsReference(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{{ one.crv }}\n\n{{ two.crv }}");
        $expander = new IncludeExpander($this->resolver([
            'one.crv' => "{#same}\n# First\n\nSee </#same>.\n",
            'two.crv' => "{#same}\n# Second\n\nSee </#same>.\n",
        ]));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertStringContainsString('<section id="same">', $html);
        $this->assertStringContainsString('<section id="same-2">', $html);
        $this->assertStringContainsString('<a href="#same-2">Second</a>', $html);
        $this->assertStringContainsString('Duplicate heading id', $expander->getWarnings()[0]->getMessage());
    }

    public function testExpandsDirectiveMidSentenceAndKeepsSurroundingText(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('Intro: {{ child.crv }} tail.');
        $expander = new IncludeExpander($this->resolver(['child.crv' => 'a /short/ fragment']));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertSame("<p>Intro: a <em>short</em> fragment tail.</p>\n", $html);
        $this->assertSame([], $expander->getWarnings());
    }

    public function testRecognizesOptionsSplitIntoTagAndMentionNodes(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('{{ child.crv #pick @shift:1 }}');
        $expander = new IncludeExpander($this->resolver([
            'child.crv' => "{#pick}\n# Picked\n\nyes\n",
        ]));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertStringContainsString('<h2>Picked</h2>', $html);
        $this->assertSame([], $expander->getWarnings());
    }

    public function testResolvesQuotedPathAfterSmartQuoteRewrite(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('{{ "my chapter.crv" }}');
        $expander = new IncludeExpander($this->resolver(['my chapter.crv' => 'spaced path body']));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertSame("<p>spaced path body</p>\n", $html);
        $this->assertSame([], $expander->getWarnings());
    }

    public function testMissingSectionWarnsAndStaysLiteral(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('{{ child.crv #nope }}');
        $expander = new IncludeExpander($this->resolver(['child.crv' => "# Real\n\nText.\n"]));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertStringNotContainsString('Real', $html);
        $this->assertStringContainsString("no section '#nope'", $expander->getWarnings()[0]->getMessage());
    }

    public function testShiftAppliesToHeadingsFromNestedIncludes(): void
    {
        $converter = CarveConverter::carve();
        $document = $converter->parse('{{ outer.crv @shift:1 }}');
        $expander = new IncludeExpander($this->resolver([
            'outer.crv' => "# Outer\n\n{{ inner.crv }}\n",
            'inner.crv' => "## Inner\n",
        ]));

        $carve = $converter->render($converter->transform($document, $expander));

        $this->assertStringContainsString('## Outer', $carve);
        $this->assertStringContainsString('### Inner', $carve);
    }

    public function testParentExplicitIdWinsCollisionEvenAfterIncludeSite(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{{ child.crv }}\n\n{#dup}\n# Parent\n");
        $expander = new IncludeExpander($this->resolver([
            'child.crv' => "{#dup}\n# Child\n\nSee </#dup>.\n",
        ]));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertStringContainsString('<section id="dup-2">', $html);
        $this->assertStringContainsString('<a href="#dup-2">Child</a>', $html);
    }

    public function testCycleDetectedThroughDifferingPathSpellingsWithResolverIds(): void
    {
        $files = ['a.crv' => '{{ ./b.crv }}', 'b.crv' => '{{ a.crv }}'];
        $resolver = new class ($files) implements IncludeResolverInterface {
            /**
             * @param array<string, string> $files
             */
            public function __construct(private readonly array $files)
            {
            }

            public function resolve(string $path, IncludeContext $context): ResolvedInclude
            {
                $id = preg_replace('#^\./#', '', $path) ?? $path;
                if (!array_key_exists($id, $this->files)) {
                    throw new RuntimeException("Missing include: {$path}");
                }

                return new ResolvedInclude($this->files[$id], $id);
            }
        };

        $converter = new CarveConverter();
        $document = $converter->parse('{{ a.crv }}');
        $expander = new IncludeExpander($resolver);
        $converter->render($converter->transform($document, $expander));

        $messages = array_map(
            static fn ($warning) => $warning->getMessage(),
            $expander->getWarnings(),
        );
        $this->assertNotEmpty(array_filter($messages, static fn (string $m) => str_contains($m, 'cycle')));
    }

    public function testFilesystemResolverResolvesNestedRelativeIncludesAndRejectsEscapes(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carve-includes-' . uniqid();
        $root = $base . DIRECTORY_SEPARATOR . 'root';
        mkdir($root . '/parts/chapters', 0777, true);
        file_put_contents($base . DIRECTORY_SEPARATOR . 'secret.crv', 'TOP SECRET');
        file_put_contents($root . '/main.crv', "{{ parts/part.crv }}\n\n{{ ../secret.crv }}\n");
        file_put_contents($root . '/parts/part.crv', "{{ chapters/leaf.crv }}\n");
        file_put_contents($root . '/parts/chapters/leaf.crv', "Deep leaf.\n");

        try {
            $converter = new CarveConverter();
            $source = (string)file_get_contents($root . '/main.crv');
            $document = $converter->parse($source);
            $expander = new IncludeExpander(new FilesystemIncludeResolver($root), $root . '/main.crv');

            $html = $converter->render($converter->transform($document, $expander));

            $this->assertStringContainsString('<p>Deep leaf.</p>', $html);
            $this->assertStringNotContainsString('TOP SECRET', $html);
            $this->assertStringContainsString('escapes configured root', $expander->getWarnings()[0]->getMessage());
        } finally {
            foreach (
                [
                    $root . '/parts/chapters/leaf.crv',
                    $root . '/parts/part.crv',
                    $root . '/main.crv',
                    $base . DIRECTORY_SEPARATOR . 'secret.crv',
                ] as $file
            ) {
                @unlink($file);
            }
            @rmdir($root . '/parts/chapters');
            @rmdir($root . '/parts');
            @rmdir($root);
            @rmdir($base);
        }
    }

    /**
     * @param array<string, string> $files
     *
     * @throws \RuntimeException
     */
    private function resolver(array $files): IncludeResolverInterface
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
