<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Transform\FilesystemIncludeResolver;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeDependency;
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

        yield 'shift auto' => [
            "## Parent\n\n{{ child.crv @shift:auto }}",
            ['child.crv' => "# One\n\n## Two\n"],
            "## Parent\n\n### One\n\n#### Two\n",
        ];

        yield 'shift auto with section' => [
            "### Parent\n\n{{ child.crv #pick @shift:auto }}",
            ['child.crv' => "# Intro\n\nSkip.\n\n{#pick}\n## Pick\n\n### Detail\n\n## Next\n"],
            "### Parent\n\n{#pick}\n#### Pick\n\n##### Detail\n",
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

    public function testShiftAutoWithoutHeadingsIsNoOpAndDoesNotWarn(): void
    {
        $converter = CarveConverter::carve();
        $document = $converter->parse("## Parent\n\n{{ child.crv @shift:auto }}");
        $expander = new IncludeExpander($this->resolver(['child.crv' => 'Body.']));

        $carve = $converter->render($converter->transform($document, $expander));

        $this->assertSame("## Parent\n\nBody\\.\n", $carve);
        $this->assertSame([], $expander->getWarnings());
    }

    public function testShiftAutoClampWarnsAndKeepsHeading(): void
    {
        $converter = CarveConverter::carve();
        $document = $converter->parse("###### Deep\n\n{{ child.crv @shift:auto }}");
        $expander = new IncludeExpander($this->resolver(['child.crv' => '# Title']));

        $carve = $converter->render($converter->transform($document, $expander));

        $this->assertSame("###### Deep\n\n###### Title\n", $carve);
        $this->assertStringContainsString('clamped', $expander->getWarnings()[0]->getMessage());
    }

    public function testShiftAutoOnInlineIncludeIsNoOp(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('Before {{ child.crv @shift:auto }} after.');
        $expander = new IncludeExpander($this->resolver(['child.crv' => 'inline text']));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertSame("<p>Before inline text after.</p>\n", $html);
        $this->assertSame([], $expander->getWarnings());
    }

    public function testNestedShiftAutoUsesAssembledContext(): void
    {
        $converter = CarveConverter::carve();
        $document = $converter->parse("## Parent\n\n{{ outer.crv @shift:auto }}");
        $expander = new IncludeExpander($this->resolver([
            'outer.crv' => "# Outer\n\n{{ inner.crv @shift:auto }}\n",
            'inner.crv' => "# Inner\n",
        ]));

        $carve = $converter->render($converter->transform($document, $expander));

        $this->assertSame("## Parent\n\n### Outer\n\n#### Inner\n", $carve);
        $this->assertSame([], $expander->getWarnings());
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

    public function testNoResolverReturnsDocumentUnchanged(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('{{ child.crv }}');
        $expander = new IncludeExpander();

        $this->assertSame($document, $expander->transform($document));
        $this->assertSame([], $expander->getWarnings());
    }

    public function testSourceWithoutDirectiveMarkerSkipsTheWalk(): void
    {
        $converter = new CarveConverter();
        $source = "Plain paragraph.\n";
        $document = $converter->parse($source);
        $expander = new IncludeExpander($this->resolver([]), source: $source);

        $this->assertSame($document, $expander->transform($document));
        $this->assertSame([], $expander->getWarnings());
    }

    public function testResolverReturningNullWarnsAndStaysLiteral(): void
    {
        $resolver = new class implements IncludeResolverInterface {
            public function resolve(string $path, IncludeContext $context): ResolvedInclude|string|null
            {
                return null;
            }
        };

        $converter = new CarveConverter();
        $document = $converter->parse('{{ child.crv }}');
        $expander = new IncludeExpander($resolver);

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertSame("<p>{{ child.crv }}</p>\n", $html);
        $this->assertStringContainsString('could not be resolved', $expander->getWarnings()[0]->getMessage());
    }

    public function testBinaryTargetWarnsAndStaysLiteral(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('{{ blob.bin }}');
        $expander = new IncludeExpander($this->resolver(['blob.bin' => "PNG\x00\x01binary"]));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertSame("<p>{{ blob.bin }}</p>\n", $html);
        $this->assertStringContainsString('binary or non-text', $expander->getWarnings()[0]->getMessage());
    }

    public function testEmptyChildInInlinePositionExpandsToNothing(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('Before {{ empty.crv }} after.');
        $expander = new IncludeExpander($this->resolver(['empty.crv' => '']));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertSame("<p>Before  after.</p>\n", $html);
        $this->assertSame([], $expander->getWarnings());
    }

    public function testMentionInsideAnExpandedRunIsPreserved(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('Ping @someone about {{ child.crv }} today.');
        $expander = new IncludeExpander($this->resolver(['child.crv' => 'the topic']));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertStringContainsString('the topic', $html);
        $this->assertStringContainsString('someone', $html);
        $this->assertSame([], $expander->getWarnings());
    }

    public function testMalformedOptionsAndLineRangesStayLiteral(): void
    {
        $converter = new CarveConverter();
        $expander = new IncludeExpander($this->resolver(['child.crv' => 'Body.']));
        $document = $converter->parse('{{ child.crv @lines:notanumber }}');

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertStringNotContainsString('Body.', $html);
    }

    public function testFilesystemResolverRejectsMissingRootAbsolutePathsAndSchemes(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carve-fs-' . uniqid();
        mkdir($root, 0777, true);

        try {
            $this->expectNotToPerformAssertions();
            $resolver = new FilesystemIncludeResolver($root);
            $context = new IncludeContext(null, null, [], 0);

            foreach (['/etc/passwd', 'https://example.com/x.crv', 'missing.crv'] as $path) {
                try {
                    $resolver->resolve($path, $context);
                    $this->fail("Expected rejection for {$path}");
                } catch (RuntimeException) {
                    // Expected: each path is denied by policy or not found.
                }
            }
        } finally {
            @rmdir($root);
        }
    }

    public function testFilesystemResolverRejectsAMissingRoot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Include root does not exist');

        new FilesystemIncludeResolver(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carve-absent-' . uniqid());
    }

    public function testIncludeContextExposesItsFields(): void
    {
        $context = new IncludeContext('parent.crv', 'current.crv', ['a.crv'], 2);

        $this->assertSame('parent.crv', $context->getIncludingPath());
        $this->assertSame('current.crv', $context->getCurrentPath());
        $this->assertSame(['a.crv'], $context->getStack());
        $this->assertSame(2, $context->getDepth());
    }

    public function testMissingSectionMarksTheDependencyAttemptedNotResolved(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('{{ child.crv #nope }}');
        $expander = new IncludeExpander($this->resolver(['child.crv' => "# Real\n"]));

        $converter->transform($document, $expander);

        $dependencies = $expander->getDependencies();
        $this->assertCount(1, $dependencies);
        $this->assertSame('child.crv', $dependencies[0]->getTarget());
        $this->assertFalse($dependencies[0]->isResolved());
    }

    public function testLiteralShiftAlsoCoversHeadingsFromNestedIncludes(): void
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

    public function testReportsDependenciesForResolvedMissingAndCycleTargets(): void
    {
        $files = [
            'a.crv' => "{{ b.crv }}\n\n{{ missing.crv }}\n\n{{ ./a.crv }}",
            'b.crv' => 'Done.',
        ];
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
        $document = $converter->parse("{{ a.crv }}\n\n{{ b.crv }}");
        $expander = new IncludeExpander($resolver);
        $converter->render($converter->transform($document, $expander));

        $this->assertSame(
            [
                ['target' => 'a.crv', 'resolved' => false],
                ['target' => 'b.crv', 'resolved' => true],
                ['target' => 'missing.crv', 'resolved' => false],
            ],
            $this->dependencyRows($expander->getDependencies()),
        );
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

    public function testRawBlockKeepsDirectiveLiteral(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("```=html\n{{ child.crv }}\n```\n");
        $resolver = $this->countingResolver(['child.crv' => 'EXPANDED']);
        $expander = new IncludeExpander($resolver);

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertStringContainsString('{{ child.crv }}', $html);
        $this->assertStringNotContainsString('EXPANDED', $html);
        // Verbatim protection is only real if the resolver is never consulted:
        // a directive that resolves and is then discarded has already charged
        // the budget and hit the filesystem.
        $this->assertSame([], $resolver->calls);
        $this->assertSame([], $expander->getWarnings());
    }

    public function testFenceWithInfoStringKeepsDirectiveLiteralAndNeverCallsTheResolver(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("```js\n{{ child.crv }}\n```\n");
        $resolver = $this->countingResolver(['child.crv' => 'EXPANDED']);
        $expander = new IncludeExpander($resolver);

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertStringContainsString('{{ child.crv }}', $html);
        $this->assertSame([], $resolver->calls);
    }

    public function testNoResolverNeverConsultsTheResolverForACodeSpan(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("Literal `{{ child.crv }}` here.\n");
        $resolver = $this->countingResolver(['child.crv' => 'EXPANDED']);
        $expander = new IncludeExpander($resolver);

        $converter->render($converter->transform($document, $expander));

        $this->assertSame([], $resolver->calls);
    }

    /**
     * Spec I5: a reference-definition label is FILE-LOCAL. Each file's
     * references resolve to its own definition, which is not a collision - it
     * must neither warn nor rename.
     */
    public function testReferenceDefinitionLabelsAreScopedNotRenamed(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("Parent [a][].\n\n{{ child.crv }}\n\n[a]: /PARENT\n");
        $expander = new IncludeExpander($this->resolver([
            'child.crv' => "Child [a][].\n\n[a]: /CHILD\n",
        ]));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertStringContainsString('<a href="/PARENT">a</a>', $html);
        $this->assertStringContainsString('<a href="/CHILD">a</a>', $html);
        $this->assertSame([], $expander->getWarnings());
    }

    public function testShiftAutoWithNoPrecedingHeadingUsesContextLevelZero(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{{ child.crv @shift:auto }}\n");
        $expander = new IncludeExpander($this->resolver(['child.crv' => "# One\n\n## Two\n"]));

        $html = $converter->render($converter->transform($document, $expander));

        // C = 0, T = 1, so N = 0: the child keeps its own levels.
        $this->assertStringContainsString('<h1>One</h1>', $html);
        $this->assertStringContainsString('<h2>Two</h2>', $html);
        $this->assertSame([], $expander->getWarnings());
    }

    public function testShiftAutoIgnoresAHeadingInAClosedSiblingContainer(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("::: note\n## Inside\n:::\n\n{{ child.crv @shift:auto }}\n");
        $expander = new IncludeExpander($this->resolver(['child.crv' => "# Child\n"]));

        $html = $converter->render($converter->transform($document, $expander));

        // The h2 sits in a container that has already closed, so it does not
        // set the context: C = 0, T = 1, N = 0.
        $this->assertStringContainsString('<h1>Child</h1>', $html);
    }

    public function testShiftAutoUsesAHeadingFromAnEnclosingContainer(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("## Outer\n\n::: note\n{{ child.crv @shift:auto }}\n:::\n");
        $expander = new IncludeExpander($this->resolver(['child.crv' => "# Child\n"]));

        $html = $converter->render($converter->transform($document, $expander));

        // C = 2 from the enclosing context, T = 1, so N = 2.
        $this->assertStringContainsString('<h3', $html);
        $this->assertStringNotContainsString('<h1>Child</h1>', $html);
    }

    /**
     * The auto shift is measured AFTER the child's own includes expand, so a
     * child that only passes through to a grandchild is levelled by the
     * headings that grandchild contributed rather than no-opping on an
     * apparently heading-free child.
     */
    public function testShiftAutoMeasuresHeadingsContributedByANestedInclude(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("## Chapters\n\n{{ mid.crv @shift:auto }}\n");
        $expander = new IncludeExpander($this->resolver([
            'mid.crv' => "{{ leaf.crv }}\n",
            'leaf.crv' => "# Leaf\n\n## Sub\n",
        ]));

        $html = $converter->render($converter->transform($document, $expander));

        // C = 2, T = 1 (from the grandchild), so N = 2.
        $this->assertStringContainsString('<h3>Leaf</h3>', $html);
        $this->assertStringContainsString('<h4>Sub</h4>', $html);
    }

    /**
     * A nested auto under an explicitly shifted parent must not be shifted
     * twice: the parent's stated shift lands after the child is expanded, so
     * the nested auto measures pre-shift coordinates and the two compose.
     */
    public function testNestedShiftAutoUnderAStatedShiftIsNotShiftedTwice(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("# Top\n\n{{ mid.crv @shift:2 }}\n");
        $expander = new IncludeExpander($this->resolver([
            'mid.crv' => "# Mid\n\n{{ leaf.crv @shift:auto }}\n",
            'leaf.crv' => "# Leaf\n",
        ]));

        $html = $converter->render($converter->transform($document, $expander));

        $this->assertStringContainsString('<h3>Mid</h3>', $html);
        $this->assertStringContainsString('<h4>Leaf</h4>', $html);
    }

    public function testWarningFromTheTopLevelDocumentNamesThatDocument(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{{ missing.crv }}\n");
        $expander = new IncludeExpander($this->resolver([]), 'main.crv');

        $converter->transform($document, $expander);

        $warnings = $expander->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('main.crv', $warnings[0]->getFile());
    }

    public function testWarningRaisedWhileExpandingAChildNamesThatChild(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{{ a.crv }}\n");
        $expander = new IncludeExpander($this->resolver(['a.crv' => "{{ gone.crv }}\n"]), 'main.crv');

        $converter->transform($document, $expander);

        $warnings = $expander->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('a.crv', $warnings[0]->getFile());
    }

    public function testWarningRaisedWhileExpandingAGrandchildNamesThatGrandchild(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{{ a.crv }}\n");
        $expander = new IncludeExpander($this->resolver([
            'a.crv' => "{{ b.crv }}\n",
            'b.crv' => "{{ gone.crv }}\n",
        ]), 'main.crv');

        $converter->transform($document, $expander);

        $warnings = $expander->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('b.crv', $warnings[0]->getFile());
    }

    public function testHeadingClampWarningNamesTheIncludedFile(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{{ a.crv @shift:4 }}\n");
        $expander = new IncludeExpander($this->resolver(['a.crv' => "### Deep\n"]), 'main.crv');

        $converter->transform($document, $expander);

        $warnings = $expander->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('a.crv', $warnings[0]->getFile());
    }

    public function testRenameWarningNamesTheFileTheRenamedIdCameFrom(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{#dup}\n# Top\n\n{{ a.crv }}\n");
        $expander = new IncludeExpander($this->resolver(['a.crv' => "{#dup}\n# Child\n"]), 'main.crv');

        $converter->transform($document, $expander);

        $warnings = $expander->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('a.crv', $warnings[0]->getFile());
    }

    public function testWarningHasNoFileWhenTheTopLevelDocumentHasNoPath(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{{ missing.crv }}\n");
        $expander = new IncludeExpander($this->resolver([]));

        $converter->transform($document, $expander);

        $warnings = $expander->getWarnings();
        $this->assertCount(1, $warnings);
        // No path context, so there is no identity to report and none is
        // invented.
        $this->assertNull($warnings[0]->getFile());
    }

    /**
     * @param array<string, string> $files
     *
     * @throws \RuntimeException
     */
    private function countingResolver(array $files): IncludeResolverInterface
    {
        return new class ($files) implements IncludeResolverInterface {
            /**
             * @var list<string>
             */
            public array $calls = [];

            /**
             * @param array<string, string> $files
             */
            public function __construct(private readonly array $files)
            {
            }

            public function resolve(string $path, IncludeContext $context): string
            {
                $this->calls[] = $path;
                if (!array_key_exists($path, $this->files)) {
                    throw new RuntimeException("Missing include: {$path}");
                }

                return $this->files[$path];
            }
        };
    }

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

    /**
     * @param list<\MarkupCarve\Carve\Transform\IncludeDependency> $dependencies
     *
     * @return list<array{target: string, resolved: bool}>
     */
    private function dependencyRows(array $dependencies): array
    {
        return array_map(
            static fn (IncludeDependency $dependency): array => [
                'target' => $dependency->getTarget(),
                'resolved' => $dependency->isResolved(),
            ],
            $dependencies,
        );
    }
}
