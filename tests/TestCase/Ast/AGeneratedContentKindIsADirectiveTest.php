<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P12-057. `:::` produces one of three types: a named container whose kind
 * names generated content is a `directive`, every other named one an
 * `admonition`, and an anonymous or attribute-only one a `div`.
 *
 * Bearbeitet markup-carve/carve#2243, which covers three engines.
 */
class AGeneratedContentKindIsADirectiveTest extends TestCase
{
    /**
     * @return array<string, array<string>>
     */
    public static function generatedContentKinds(): array
    {
        $cases = [];
        foreach (Div::GENERATED_CONTENT_KINDS as $kind) {
            $cases['::: ' . $kind] = [$kind];
        }

        return $cases;
    }

    /**
     * The closed half of the clause: "every other named container is an
     * `admonition`". Two of these look generated and are not.
     *
     * @return array<string, array<string>>
     */
    public static function namedButNotGenerated(): array
    {
        return [
            'endnotes' => ['endnotes'],
            'contents' => ['contents'],
            'bibliographies' => ['bibliographies'],
            'toc-placement' => ['toc-placement'],
            'note' => ['note'],
            'sidebar' => ['sidebar'],
        ];
    }

    #[DataProvider('generatedContentKinds')]
    public function testItIsADirectiveCarryingThatKind(string $kind): void
    {
        $node = self::wire("::: {$kind}\n:::\n");

        $this->assertSame('directive', $node['type']);
        $this->assertSame($kind, $node['kind']);
    }

    #[DataProvider('generatedContentKinds')]
    public function testAProfileClassifiesItAsADirectiveUnderTheDivSupertype(string $kind): void
    {
        $div = (new CarveConverter())->parse("::: {$kind}\n:::\n")->getChildren()[0];

        $this->assertSame('directive', Profile::canonicalTypeOf($div));
        // The supertype, read through a profile that denies `div` alone: a
        // directive is still stripped, so splitting the type widened nothing.
        $this->assertFalse(Profile::full()->denyBlock(['div'])->isNodeAllowed($div));
        $this->assertFalse(Profile::full()->denyBlock(['directive'])->isNodeAllowed($div));
        $this->assertTrue(Profile::full()->isNodeAllowed($div));
    }

    #[DataProvider('generatedContentKinds')]
    public function testItSurvivesTheAstJsonRoundTrip(string $kind): void
    {
        $source = "::: {$kind}\nbody\n:::\n";
        $codec = new AstCodec();
        $back = $codec->decode($codec->encode((new CarveConverter())->parse($source)));

        $this->assertSame('directive', self::wire($source)['type']);
        $this->assertSame($source, CarveConverter::carve()->render($back));
    }

    #[DataProvider('generatedContentKinds')]
    public function testAnHtmlImportReadsItAsADirectiveToo(string $kind): void
    {
        $tree = (new HtmlToCarve())->convertToAst("<div class=\"{$kind}\"><p>x</p></div>");

        $this->assertSame('directive', $tree['children'][0]['type']);
        $this->assertSame($kind, $tree['children'][0]['kind']);
    }

    #[DataProvider('namedButNotGenerated')]
    public function testEveryOtherNamedKindStaysAnAdmonition(string $kind): void
    {
        $node = self::wire("::: {$kind}\n:::\n");

        $this->assertSame('admonition', $node['type']);
        $this->assertSame($kind, $node['kind']);
    }

    /**
     * THE CONTROL. An anonymous container and an attribute-only one are a `div`,
     * whichever way the named branch decides, so this half holds on both sides of
     * a mutation to it. The attribute-only case is the one the clause spells out:
     * `{.toc}` above a bare `:::` names no kind on the opener.
     *
     * @return array<string, array<string>>
     */
    public static function unnamedContainers(): array
    {
        return [
            'anonymous' => [":::\ntext\n:::\n"],
            'attribute-only' => ["{.x}\n:::\ntext\n:::\n"],
            'attribute-only with a generated-content class' => ["{.toc}\n:::\ntext\n:::\n"],
        ];
    }

    #[DataProvider('unnamedContainers')]
    public function testAnUnnamedContainerIsADiv(string $source): void
    {
        $node = self::wire($source);

        $this->assertSame('div', $node['type']);
        $this->assertArrayNotHasKey('kind', $node);
    }

    /**
     * What a reader sees differently, and the only thing that moved: the schema
     * closes `directive` WITHOUT a `title`, so a quoted opener on one of the six
     * is not carried. markup-carve/carve#2247 asks where it should live; until it
     * is answered, the loss is pinned here rather than papered over.
     *
     * The direct HTML render is unaffected - it reads the parse tree, where the
     * header is still on the Div - so the loss appears only through the wire.
     */
    public function testAQuotedOpenerIsNotCarriedOnADirective(): void
    {
        $source = "::: toc \"Contents\"\n:::\n";
        $document = (new CarveConverter())->parse($source);
        $codec = new AstCodec();

        $this->assertArrayNotHasKey('title', $codec->encode($document)['children'][0]);
        $this->assertStringContainsString(
            '<p class="admonition-title">Contents</p>',
            (new HtmlRenderer())->render($document),
        );
        $this->assertStringNotContainsString(
            'admonition-title',
            (new HtmlRenderer())->render($codec->decode($codec->encode((new CarveConverter())->parse($source)))),
        );
    }

    /**
     * The same container on the other three writers, which read the parse tree
     * and so cannot move. Named because "no golden moved" is a claim, and a
     * corpus with one `::: footnotes` case cannot carry it alone.
     */
    #[DataProvider('generatedContentKinds')]
    public function testTheWrittenOutputDoesNotMove(string $kind): void
    {
        $source = "::: {$kind}\nbody\n:::\n";
        $document = (new CarveConverter())->parse($source);

        $this->assertStringContainsString('class="' . $kind . '"', (new HtmlRenderer())->render($document));
        $this->assertSame($source, CarveConverter::carve()->render((new CarveConverter())->parse($source)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function wire(string $source): array
    {
        /** @var array<string, mixed> $node */
        $node = (new AstCodec())->encode((new CarveConverter())->parse($source))['children'][0];

        return $node;
    }
}
