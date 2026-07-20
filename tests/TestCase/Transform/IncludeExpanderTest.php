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

    /**
     * The resolved flag records ONLY whether the source was read (I11), and a
     * missing section means the file WAS read. A host must keep watching it:
     * editing the child to add the section is exactly what should invalidate
     * the preview, and a dropped watch would never notice.
     */
    public function testMissingSectionStillReportsTheDependencyAsResolved(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('{{ child.crv #nope }}');
        $expander = new IncludeExpander($this->resolver(['child.crv' => "# Real\n"]));

        $converter->transform($document, $expander);

        $dependencies = $expander->getDependencies();
        $this->assertCount(1, $dependencies);
        $this->assertSame('child.crv', $dependencies[0]->getTarget());
        $this->assertTrue($dependencies[0]->isResolved());
        $this->assertStringContainsString("no section '#nope'", $expander->getWarnings()[0]->getMessage());
    }

    /**
     * The dividing line is strictly "did a read happen". A depth refusal is
     * decided BEFORE the resolver is called, so nothing was read and the target
     * is correctly unresolved - unlike the missing-section case above.
     */
    public function testDepthExceededStaysUnresolvedBecauseNothingWasRead(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('{{ outer.crv }}');
        $expander = new IncludeExpander($this->resolver([
            'outer.crv' => "{{ inner.crv }}\n",
            'inner.crv' => "Deep.\n",
        ]), null, 1);

        $converter->transform($document, $expander);

        $this->assertSame(
            [
                ['target' => 'outer.crv', 'resolved' => true],
                ['target' => 'inner.crv', 'resolved' => false],
            ],
            $this->dependencyRows($expander->getDependencies()),
        );
        $this->assertStringContainsString('depth limit', $expander->getWarnings()[0]->getMessage());
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
                // a.crv was READ before its self-cycle was refused, so it stays
                // resolved: the refusal travels in the Warning, not this flag.
                ['target' => 'a.crv', 'resolved' => true],
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
            $warning = $expander->getWarnings()[0];
            // The containment denial is reported with the processor's own
            // wording; the resolver's message, which names the root's absolute
            // path, stays on the detail channel.
            $this->assertSame('Include could not be resolved: ../secret.crv', $warning->getMessage());
            $this->assertStringNotContainsString($root, $warning->getMessage());
            $this->assertStringContainsString('escapes configured root', (string)$warning->getDetail());
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
     * The set is a cross-implementation contract, so the sequence has to be the
     * one a host can reason about: the order each target's directive is first
     * encountered reading the expanded document top to bottom. Target names here
     * are deliberately anti-alphabetical, so a sorted implementation cannot pass.
     */
    public function testDependenciesAreReportedInFirstEncounterOrder(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{{ zebra.crv }}\n\n{{ alpha.crv }}\n");
        $expander = new IncludeExpander($this->resolver([
            'zebra.crv' => "Zebra.\n\n{{ yak.crv }}\n",
            'yak.crv' => "Yak.\n",
            'alpha.crv' => "Alpha.\n",
        ]));

        $converter->transform($document, $expander);

        $this->assertSame(
            ['zebra.crv', 'yak.crv', 'alpha.crv'],
            array_map(
                static fn (IncludeDependency $dependency): string => $dependency->getTarget(),
                $expander->getDependencies(),
            ),
        );
    }

    public function testATargetReadThenRefusedForACycleStaysResolvedAndWarns(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{{ a.crv }}\n");
        $expander = new IncludeExpander($this->resolver(['a.crv' => "A.\n\n{{ a.crv }}\n"]));

        $converter->transform($document, $expander);

        $this->assertSame(
            [['target' => 'a.crv', 'resolved' => true]],
            $this->dependencyRows($expander->getDependencies()),
        );
        $warnings = $expander->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('cycle', $warnings[0]->getMessage());
    }

    public function testATargetReadThenRefusedForBudgetStaysResolved(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{{ big.crv }}\n");
        $expander = new IncludeExpander($this->resolver(['big.crv' => str_repeat('x', 200)]), null, 16, 10);

        $converter->transform($document, $expander);

        $this->assertSame(
            [['target' => 'big.crv', 'resolved' => true]],
            $this->dependencyRows($expander->getDependencies()),
        );
        $this->assertStringContainsString('size budget', $expander->getWarnings()[0]->getMessage());
    }

    public function testANeverReadTargetStaysUnresolved(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("{{ gone.crv }}\n");
        $expander = new IncludeExpander($this->resolver([]));

        $converter->transform($document, $expander);

        $this->assertSame(
            [['target' => 'gone.crv', 'resolved' => false]],
            $this->dependencyRows($expander->getDependencies()),
        );
    }

    /**
     * A target first seen unresolved and later read successfully is upgraded:
     * the flag answers "was this ever read", so a host watches the file either
     * way and learns when a previously missing target starts resolving.
     *
     * @throws \RuntimeException
     */
    public function testAnUnresolvedTargetIsUpgradedWhenALaterReadSucceeds(): void
    {
        $resolver = new class implements IncludeResolverInterface {
            public int $calls = 0;

            /**
             * @throws \RuntimeException
             */
            public function resolve(string $path, IncludeContext $context): string
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new RuntimeException('Missing include: ' . $path);
                }

                return "Now present.\n";
            }
        };

        $converter = new CarveConverter();
        $document = $converter->parse("{{ later.crv }}\n\n{{ later.crv }}\n");
        $expander = new IncludeExpander($resolver);

        $converter->transform($document, $expander);

        $this->assertSame(
            [['target' => 'later.crv', 'resolved' => true]],
            $this->dependencyRows($expander->getDependencies()),
        );
    }

    /**
     * A resolver's own error text is host-controlled and commonly embeds
     * absolute paths, so it must not reach the rendered warning message.
     *
     * @throws \RuntimeException
     */
    public function testResolverErrorTextDoesNotLeakIntoTheWarningMessage(): void
    {
        $resolver = new class implements IncludeResolverInterface {
            /**
             * @throws \RuntimeException
             */
            public function resolve(string $path, IncludeContext $context): string
            {
                throw new RuntimeException('failed reading /home/secret-user/private/vault/' . $path);
            }
        };

        $converter = new CarveConverter();
        $document = $converter->parse("{{ child.crv }}\n");
        $expander = new IncludeExpander($resolver);

        $converter->transform($document, $expander);

        $warnings = $expander->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('Include could not be resolved: child.crv', $warnings[0]->getMessage());
        $this->assertStringNotContainsString('/home/secret-user', $warnings[0]->getMessage());
        // Still available to a host that controls its own resolver.
        $this->assertStringContainsString('/home/secret-user', (string)$warnings[0]->getDetail());
    }

    /**
     * A rejected directive must have NO observable side effects: the document
     * has to come out byte-identical to the same document with that directive
     * left as literal text.
     *
     * The failure this guards against is an identifier reservation that
     * outlives the rejection - a rejected include whose child heading ids were
     * already claimed, so a later legitimate heading gets suffixed for no
     * reason. This implementation is immune by construction, because the
     * collision pass runs once over the FINAL assembled document and a rejected
     * child is never spliced into it. That immunity is currently an accident of
     * ordering, though, so every rejection reason is pinned here: a refactor
     * toward reserving ids at merge time would reintroduce the bug silently.
     *
     * @param string $source
     * @param array<string, string> $files
     * @param string|null $currentPath
     * @param int $depthLimit
     * @param int|null $byteBudget
     */
    #[DataProvider('rejectionProvider')]
    public function testARejectedDirectiveHasNoSideEffects(
        string $source,
        array $files,
        ?string $currentPath,
        int $depthLimit,
        ?int $byteBudget,
    ): void {
        $expanded = new CarveConverter();
        $expander = new IncludeExpander($this->resolver($files), $currentPath, $depthLimit, $byteBudget);
        $withResolver = $expanded->render($expanded->transform($expanded->parse($source), $expander));

        // No resolver at all: every directive stays literal by definition, which
        // is exactly the document a rejected directive must produce.
        $literal = new CarveConverter();
        $asLiteralText = $literal->render($literal->parse($source));

        $this->assertSame($asLiteralText, $withResolver);
        $this->assertNotSame([], $expander->getWarnings());
    }

    /**
     * @return iterable<string, array{string, array<string, string>, string|null, int, int|null}>
     */
    public static function rejectionProvider(): iterable
    {
        $parentHeading = "\n\n{#dup}\n# Parent\n";

        yield 'unresolvable' => ['{{ gone.crv }}' . $parentHeading, [], null, 16, null];

        yield 'both selections present' => [
            '{{ a.crv #a @lines:1-2 }}' . $parentHeading,
            ['a.crv' => "{#dup}\n# A\n"],
            null,
            16,
            null,
        ];

        yield 'cycle' => [
            '{{ a.crv }}' . $parentHeading,
            ['a.crv' => "{#dup}\n# A\n"],
            'a.crv',
            16,
            null,
        ];

        yield 'depth exceeded' => [
            '{{ a.crv }}' . $parentHeading,
            ['a.crv' => "{#dup}\n# A\n"],
            null,
            0,
            null,
        ];

        yield 'size exceeded' => [
            '{{ a.crv }}' . $parentHeading,
            ['a.crv' => "{#dup}\n# A\n"],
            null,
            16,
            4,
        ];

        yield 'binary' => [
            '{{ a.crv }}' . $parentHeading,
            ['a.crv' => "{#dup}\n# A\n\0binary"],
            null,
            16,
            null,
        ];

        yield 'missing section' => [
            '{{ a.crv #nope }}' . $parentHeading,
            ['a.crv' => "{#dup}\n# A\n"],
            null,
            16,
            null,
        ];

        // The found case: a block-structured child cannot merge at an inline
        // position, and the parent heading after it must keep its own id.
        yield 'inline include of block content' => [
            'Say {{ a.crv }} now.' . $parentHeading,
            ['a.crv' => "{#dup}\n# A\n\nTwo blocks.\n"],
            null,
            16,
            null,
        ];

        // Footnote labels are the other document-visible namespace.
        yield 'inline include of block content, footnote label' => [
            "Say {{ a.crv }} now.\n\nParent[^x]\n\n[^x]: Parent note.\n",
            ['a.crv' => "One[^x]\n\nTwo blocks.\n\n[^x]: Child note.\n"],
            null,
            16,
            null,
        ];
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
