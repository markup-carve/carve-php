<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function basename;
use function explode;
use function file_get_contents;
use function glob;
use function preg_replace;
use function str_repeat;
use function strlen;
use function trim;

#[Group('corpus')]
class CarveFormatterTest extends TestCase
{
    /**
     * @throws \RuntimeException
     *
     * @return array<string, array{slug: string, crv: string}>
     */
    public static function corpusProvider(): array
    {
        $dir = dirname(__DIR__, 2) . '/spec/tests/corpus';
        $crvFiles = glob($dir . '/*.crv') ?: [];
        if ($crvFiles === []) {
            throw new RuntimeException('Carve spec corpus not found at ' . $dir);
        }

        $cases = [];
        foreach ($crvFiles as $crvPath) {
            $slug = basename($crvPath, '.crv');
            $cases[$slug] = [
                'slug' => $slug,
                'crv' => (string)file_get_contents($crvPath),
            ];
        }

        return $cases;
    }

    #[DataProvider('corpusProvider')]
    public function testCorpusFormatsSemanticallyAndIdempotently(string $slug, string $crv): void
    {
        $formatted = CarveConverter::toCarve($crv);
        $reformatted = CarveConverter::toCarve($formatted);

        $converter = new CarveConverter();
        $this->assertSame(
            $this->normalizeHtml($converter->convert($crv)),
            $this->normalizeHtml($converter->convert($formatted)),
            'Formatted output changed rendered HTML for ' . $slug,
        );
        $this->assertSame($formatted, $reformatted, 'Formatter is not idempotent for ' . $slug);

        $converter->parse($formatted);
        $this->addToAssertionCount(1);
    }

    public function testTargetedFormattingRules(): void
    {
        $nbsp = "\u{00A0}";
        $cases = [
            "a\n\n\nb\n" => "a\n\nb\n",
            "+ item\n" => "+ item\n",
            "```\na ``` b\n```\n" => "````\na ``` b\n````\n",
            "{k=v .cls #id}\n# H\n" => "{k=v .cls #id}\n# H\n",
            "a  \n{$nbsp}\t \n" => "a\n{$nbsp}\n",
            "{.line-block}\n:::\na\nb\n:::\n" => "{.line-block}\n:::\na\nb\n:::\n",
            // `^sup^` / `,sub,` are literal text (no bare sup/sub delimiter),
            // so neither needs an escape: PART 11 §2 escapes IF AND ONLY IF
            // omitting it would change the re-parsed AST, and a lone caret
            // opens nothing now that sup is braced-only. Braced forms
            // round-trip unchanged.
            "a /em/ *strong* _u_ ~s~ {^sup^} {,sub,} =mark= `code`\n" =>
                "a /em/ *strong* _u_ ~s~ {^sup^} {,sub,} =mark= `code`\n",
            "a ^sup^ ,sub, stays literal\n" =>
                "a ^sup^ ,sub, stays literal\n",
            // An unresolved reference image round-trips VERBATIM (the leading
            // `!` is not escaped), matching an unresolved reference link and
            // carve-js / carve-rs - not the escaped `\![a][nope]` form.
            "![a][nope]\n" => "![a][nope]\n",
            "foo![a][nope]\n" => "foo![a][nope]\n",
            "![a][nope] and [t][nope]\n" => "![a][nope] and [t][nope]\n",
            // Bare `!` / bracket punctuation stays literal when re-parsing it
            // changes nothing.
            "![a]\n" => "![a]\n",
            "!important\n" => "!important\n",
            "{title=\"attr title\"}\n::: note \"opener title\"\nBody.\n:::\n" =>
                "{title=\"attr title\"}\n::: note \"opener title\"\nBody.\n:::\n",
            // Extra attributes on a typed opener survive as a preceding
            // attribute line (byte-identical to carve-js).
            "{#x data-k=\"v\"}\n::: tip\nBody.\n:::\n" =>
                "{#x data-k=v}\n::: tip\nBody.\n:::\n",
            // A non-identifier id falls back to the quoted key=value form.
            "{id=\"a b\"}\n::: tip\nBody.\n:::\n" =>
                "{id=\"a b\"}\n::: tip\nBody.\n:::\n",
            // Multi-class admonitions leave the typed-div fast path but keep
            // header, label, and the sibling class on the attribute line.
            "{.callout}\n::: note \"T\" [L]\nBody.\n:::\n" =>
                "{.callout}\n::: note \"T\" [L]\nBody.\n:::\n",
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, CarveConverter::toCarve($input));
        }
    }

    /**
     * A tight list item with more than one child block must stay tight through
     * fmt: the formatter joins its blocks with a single newline, never a blank
     * line (a blank would loosen the item on re-parse). Corpus category 162.
     * A nested-list child (category 142) keeps the existing indent handling and
     * its blank separator, so the outer item stays byte-stable and idempotent.
     */
    public function testTightMultiChildItemStaysTight(): void
    {
        $converter = new CarveConverter();

        // 162: item / fenced code / trailing text - all joined tight, no blank.
        $src162 = "- item\n  ```\n  c\n  ```\n  tail\n";
        $formatted162 = CarveConverter::toCarve($src162);
        $this->assertSame(
            "- item\n  ```\n  c\n  ```\n  tail\n",
            $formatted162,
            'Tight item with a fence and trailing text must format tight (no loosening blank).',
        );
        $this->assertSame(
            $converter->convert($src162),
            $converter->convert($formatted162),
            'Formatted 162 must render the same HTML as the source.',
        );
        $this->assertSame(
            $formatted162,
            CarveConverter::toCarve($formatted162),
            'Formatter must be idempotent on 162.',
        );

        // 162 with an admonition block between the para and the trailing text.
        $src162b = "- item\n  :::note\n  body\n  :::\n  tail\n";
        $formatted162b = CarveConverter::toCarve($src162b);
        $this->assertSame(
            $converter->convert($src162b),
            $converter->convert($formatted162b),
            'Formatted 162 (admonition) must render the same HTML as the source.',
        );
        $this->assertSame(
            $formatted162b,
            CarveConverter::toCarve($formatted162b),
            'Formatter must be idempotent on 162 (admonition).',
        );

        // 142: a nested-list child keeps its 2-space nesting indent and stays
        // idempotent - the tight-join must not touch nested-list handling.
        $src142 = "- a\n  - b\n\n  - c\n";
        $formatted142 = CarveConverter::toCarve($src142);
        $this->assertSame(
            $formatted142,
            CarveConverter::toCarve($formatted142),
            'Formatter must be idempotent on 142 (nested list).',
        );
        $this->assertSame(
            $converter->convert($src142),
            $converter->convert($formatted142),
            'Formatted 142 must render the same HTML as the source.',
        );
    }

    /**
     * The list marker is semantic (section 11): a sibling with a different
     * bullet char or ordered delimiter starts a NEW list, so fmt preserves
     * the authored marker (carve issue 286) - normalizing would merge
     * adjacent sibling lists on re-parse.
     */
    public function testPreservesAuthoredListMarkers(): void
    {
        $cases = [
            "1) a\n2) b\n",
            "1. a\n2. b\n",
            "* a\n* b\n",
            "- a\n- b\n",
            "* [x] done\n* [ ] todo\n",
        ];
        foreach ($cases as $src) {
            $this->assertSame($src, CarveConverter::toCarve($src));
        }
    }

    /**
     * The invariant cases: adjacent sibling lists separated only by their
     * marker must stay separate through fmt (carve issue 286).
     */
    public function testAdjacentListsSeparatedByMarkerStaySeparate(): void
    {
        $converter = new CarveConverter();
        $cases = [
            "1. a\n1) b",
            "1. a\n\n1) b",
            "- a\n* b",
            "- a\n\n* b",
        ];
        foreach ($cases as $src) {
            $f1 = CarveConverter::toCarve($src);
            $this->assertSame($f1, CarveConverter::toCarve($f1));
            $this->assertSame(
                $converter->convert($src),
                $converter->convert($f1),
            );
        }
    }

    /**
     * Verbatim content survives document normalization (carve-js issue 340):
     * trailing whitespace and blank-line runs inside code blocks, raw blocks,
     * frontmatter, and block comments are byte-exact after fmt.
     */
    public function testVerbatimContentSurvivesNormalization(): void
    {
        $cases = [
            "```\ntrailing   \nalso\t\t\n```\n",
            "```\na\n\n\n\nb\n```\n",
            "```=html\n<pre>x   \n\n\n\ny</pre>\n```\n",
            "---\ntitle: X\n\n\n\nnote: kept\n---\n\n%%%\nc   \n\n\n\nd\n%%%\n\nbody\n",
        ];
        $converter = new CarveConverter();
        foreach ($cases as $src) {
            $formatted = CarveConverter::toCarve($src);
            $this->assertSame($src, $formatted);
            $this->assertSame(
                $converter->convert($src),
                $converter->convert($formatted),
            );
        }
    }

    /**
     * Same content nested in a blockquote and a list item: fmt stays
     * idempotent and semantics-preserving.
     */
    public function testVerbatimContentStableInsideContainers(): void
    {
        $cases = [
            "> ```\n> a   \n>\n>\n>\n> b\n> ```\n",
            "- item\n\n  ```\n  a   \n\n\n\n  b\n  ```\n",
        ];
        $converter = new CarveConverter();
        foreach ($cases as $src) {
            $f1 = CarveConverter::toCarve($src);
            $f2 = CarveConverter::toCarve($f1);
            $this->assertSame($f1, $f2);
            $this->assertSame(
                $converter->convert($src),
                $converter->convert($f1),
            );
        }
    }

    public function testNestedColonFencesUseWholeSubtreeWidth(): void
    {
        $source = "::::: a\n\n:::: b\n\n::: c\nX\n:::\n\n::::\n\n:::::\n";
        $formatted = CarveConverter::toCarve($source);
        $converter = new CarveConverter();

        $this->assertSame(
            $this->normalizeHtml($converter->convert($source)),
            $this->normalizeHtml($converter->convert($formatted)),
        );
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testMixedContainerKindsUseWholeSubtreeWidth(): void
    {
        // The div's class rides a PRECEDING attribute line: an opener carrying
        // inline `{...}` is a paragraph, which would leave two real levels.
        $source = ":::::: note\n\n{.wrap}\n:::::\n\n:::: |\na\nb\n::::\n\n:::::\n\n::::::\n";
        $formatted = CarveConverter::toCarve($source);
        $converter = new CarveConverter();

        $this->assertSame(
            $this->normalizeHtml($converter->convert($source)),
            $this->normalizeHtml($converter->convert($formatted)),
        );
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    /**
     * A container inside a blockquote, a list item or a definition body writes
     * its fence lines with that host's prefix or indent, so they cannot close
     * an ancestor fence. Widening for them would only make the source noisier.
     */
    #[DataProvider('prefixedContainerHostProvider')]
    public function testFenceIsNotWidenedForAContainerBehindAPrefix(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);
        $converter = new CarveConverter();

        $this->assertSame(
            $this->normalizeHtml($converter->convert($source)),
            $this->normalizeHtml($converter->convert($formatted)),
        );
        $this->assertStringStartsWith('::: outer', $formatted);
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function prefixedContainerHostProvider(): array
    {
        return [
            'list item' => ["::: outer\n\n- item\n\n  ::: inner\n  x\n  :::\n\n:::\n"],
            'blockquote' => ["::: outer\n\n> ::: inner\n> x\n> :::\n\n:::\n"],
            'definition body' => ["::: outer\n\n:: term\n:  ::: inner\n   x\n   :::\n\n:::\n"],
            'table sibling' => ["::: outer\n\n| =a | =b |\n| 1 | 2 |\n\n:::\n"],
        ];
    }

    /**
     * An AST built through the API can nest far past the depth the parser
     * allows. renderBlock emits nothing past MAX_RENDER_DEPTH, so a fence sized
     * from those levels would be sized for output that never appears.
     */
    public function testFenceIgnoresContainersPastTheRenderCap(): void
    {
        $node = new Paragraph();
        for ($level = 0; $level < 1000; $level++) {
            $div = new Div();
            $div->appendChild($node);
            $node = $div;
        }
        $document = new Document();
        $document->appendChild($node);

        $formatted = (new CarveRenderer())->render($document);

        $widest = 0;
        foreach (explode("\n", $formatted) as $line) {
            if ($line !== '' && trim($line, ':') === '') {
                $widest = max($widest, strlen($line));
            }
        }
        // Derived, not pinned: the outermost fence is `:::` and each level
        // inward adds a colon, so the widest a bounded writer can emit is fixed
        // by the cap itself. Writing the number out made this test track the
        // old cap rather than the rule (issue 517).
        $this->assertLessThanOrEqual(3 + CarveRenderer::MAX_RENDER_DEPTH - 1, $widest);
    }

    /**
     * A document nested at exactly the parser's cap parses fine, and the writer
     * used to lose its innermost block: its bound was the parser's own number,
     * so the guard fired on a tree the parser had just accepted, deleting
     * content with no error and breaking PART 11's semantic invariant at the
     * boundary (issue 517).
     */
    public function testWriterKeepsTheInnermostContentAtTheParserCap(): void
    {
        $source = str_repeat("::: note\n", BlockParser::MAX_NESTING_DEPTH) . "body\n";
        $converter = new CarveConverter();
        // At this indent the HTML pretty-printer wraps the close tag onto its
        // own line, so the paragraph is matched by its opening rather than as a
        // whole element.
        $this->assertStringContainsString('<p>body', $converter->convert($source));

        $written = CarveConverter::toCarve($source);
        $this->assertStringContainsString('body', $written);
        $this->assertSame($converter->convert($source), $converter->convert($written));
    }

    /**
     * Raising a bound must not retire it. An AST that did not come from the
     * parser can nest without limit, so the guard still has to truncate, and
     * truncate at the same point however much deeper the input goes.
     */
    public function testRenderCapStillBoundsAHandBuiltAst(): void
    {
        $build = function (int $depth): Document {
            $paragraph = new Paragraph();
            $paragraph->appendChild(new Text('body'));
            $node = $paragraph;
            for ($level = 0; $level < $depth; $level++) {
                $div = new Div();
                $div->appendChild($node);
                $node = $div;
            }
            $document = new Document();
            $document->appendChild($node);

            return $document;
        };

        $renderer = new CarveRenderer();
        $under = $renderer->render($build(CarveRenderer::MAX_RENDER_DEPTH - 2));
        $this->assertStringContainsString('body', $under);

        $over = $renderer->render($build(CarveRenderer::MAX_RENDER_DEPTH + 1));
        $farOver = $renderer->render($build(1000));
        $this->assertStringNotContainsString('body', $over);
        $this->assertSame(strlen($over), strlen($farOver));
    }

    public function testDeepContainerLadderKeepsDepthAfterFormatting(): void
    {
        $source = '';
        for ($width = 42; $width >= 3; $width--) {
            $source .= str_repeat(':', $width) . ' level-' . $width . "\n\n";
        }
        $source .= "leaf\n";
        for ($width = 3; $width <= 42; $width++) {
            $source .= "\n" . str_repeat(':', $width) . "\n";
        }

        $converter = new CarveConverter();
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame(40, $this->containerDepth($converter->parse($source)->getChildren()));
        $this->assertSame(40, $this->containerDepth($converter->parse($formatted)->getChildren()));
        $this->assertSame(
            $this->normalizeHtml($converter->convert($source)),
            $this->normalizeHtml($converter->convert($formatted)),
        );
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     */
    protected function containerDepth(array $nodes): int
    {
        $depth = 0;
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }
            $childDepth = $this->containerDepth($node->getChildren());
            $depth = max($depth, ($node instanceof Div || $node instanceof LineBlock ? 1 : 0) + $childDepth);
        }

        return $depth;
    }

    protected function normalizeHtml(string $html): string
    {
        $html = (string)preg_replace('/[ \t]+$/m', '', $html);

        return rtrim($html, "\n");
    }
}
