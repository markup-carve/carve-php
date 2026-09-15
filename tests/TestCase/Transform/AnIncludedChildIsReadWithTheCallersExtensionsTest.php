<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Extension\ExtensionInterface;
use MarkupCarve\Carve\Extension\GlossaryExtension;
use MarkupCarve\Carve\Extension\WikilinksExtension;
use MarkupCarve\Carve\Node\Block\CitationDefinition;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use MarkupCarve\Carve\Transform\ResolvedInclude;
use PHPUnit\Framework\TestCase;

/**
 * An include is textual composition of one document, so the same text has to
 * mean the same thing whichever file it sits in (markup-carve/carve-js#1693).
 */
class AnIncludedChildIsReadWithTheCallersExtensionsTest extends TestCase
{
    public function testAnInlineMatcherTheCallerPassedReachesTheChild(): void
    {
        [$converter, $document] = $this->expand(
            "Root [[P]].\n\n{{ c.crv }}\n",
            ['c.crv' => "Child [[P]].\n"],
            [new WikilinksExtension()],
        );

        $this->assertStringContainsString(
            '<p>Child <a href="p" class="wikilink" data-wikilink="P">P</a>.</p>',
            $converter->render($document),
        );
    }

    public function testTheExpandedDocumentRendersWhatTheSameLinesRenderAsOneFile(): void
    {
        [$converter, $document] = $this->expand(
            "Root [[P]].\n\n{{ c.crv }}\n",
            ['c.crv' => "Child [[P]].\n"],
            [new WikilinksExtension()],
        );
        $oneFile = new CarveConverter();
        $oneFile->addExtension(new WikilinksExtension());

        $this->assertSame(
            $oneFile->convert("Root [[P]].\n\nChild [[P]].\n"),
            $converter->render($document),
        );
    }

    public function testTheExtensionsReachAGrandchild(): void
    {
        [$converter, $document] = $this->expand(
            "Root\n\n{{ a.crv }}\n",
            ['a.crv' => "{{ b.crv }}\n", 'b.crv' => "Deep [[P]].\n"],
            [new WikilinksExtension()],
        );

        $this->assertStringContainsString('data-wikilink="P"', $converter->render($document));
    }

    public function testACitationDefinitionTheExtensionMadeVisibleIsPromoted(): void
    {
        [, $document] = $this->expand(
            "Root cites [@k].\n\n{{ c.crv }}\n",
            ['c.crv' => "[@k]: Knuth, D. TAOCP.\n"],
            [new CitationsExtension()],
        );

        $definitions = array_filter(
            $document->getChildren(),
            static fn ($block): bool => $block instanceof CitationDefinition,
        );
        $this->assertCount(1, $definitions);
    }

    public function testTheChildIsLeftWithoutExtensionsWhenTheCallerPassesNone(): void
    {
        [$converter, $document] = $this->expand(
            "Root [[P]].\n\n{{ c.crv }}\n",
            ['c.crv' => "Child [[P]].\n"],
            [new WikilinksExtension()],
            forward: false,
        );

        $this->assertStringContainsString('<p>Child [[P]].</p>', $converter->render($document));
    }

    /**
     * An extension resets its own state in `afterParse()`, so the child must not
     * parse through the parent's instance.
     */
    public function testReadingTheChildLeavesTheParentsExtensionStateIntact(): void
    {
        [$converter, $document] = $this->expand(
            "Use :term[HTTP].\n\n::: glossary\n:: HTTP\n:  HyperText Transfer Protocol.\n:::\n\n{{ c.crv }}\n",
            ['c.crv' => "Child prose.\n"],
            [new GlossaryExtension()],
        );

        $this->assertStringContainsString(
            '<a href="#gloss-http" class="term">HTTP</a>',
            $converter->render($document),
        );
    }

    public function testAReferenceImageWithACaptionIsAFigureInAChild(): void
    {
        [$converter, $document] = $this->expand(
            "Root\n\n{{ c.crv }}\n",
            ['c.crv' => "![alt][ref]\n^ Cap\n\n[ref]: a.png\n"],
            [],
        );

        $this->assertStringContainsString('<figcaption>Cap</figcaption>', $converter->render($document));
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     * @param array<\MarkupCarve\Carve\Extension\ExtensionInterface> $extensions
     * @param bool $forward
     *
     * @return array{0: \MarkupCarve\Carve\CarveConverter, 1: \MarkupCarve\Carve\Node\Document}
     */
    protected function expand(string $source, array $files, array $extensions, bool $forward = true): array
    {
        $converter = new CarveConverter();
        array_map(static fn (ExtensionInterface $extension) => $converter->addExtension($extension), $extensions);
        $resolver = new class ($files) implements IncludeResolverInterface {
            /**
             * @param array<string, string> $files
             */
            public function __construct(protected array $files)
            {
            }

            public function resolve(string $path, IncludeContext $context): ?ResolvedInclude
            {
                return isset($this->files[$path]) ? new ResolvedInclude($this->files[$path], $path) : null;
            }
        };
        $expander = new IncludeExpander(
            resolver: $resolver,
            extensions: $forward ? $converter->getExtensions() : [],
        );

        return [$converter, $expander->transform($converter->parse($source))];
    }
}
