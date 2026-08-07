<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown target neutralizes embedded HTML everywhere it writes author
 * content.
 *
 * The writer states that invariant next to escapeText(): carve's "HTML is text"
 * guarantee holds for this target too, so Markdown re-rendered to HTML cannot
 * execute. Four slots skipped it (carve-php#1063).
 */
class MarkdownEscapesEmbeddedHtmlTest extends TestCase
{
    /**
     * @var string
     */
    protected const PAYLOAD = '<script>alert(1)</script>';

    /**
     * @var string
     */
    protected const ESCAPED = '&lt;script&gt;alert(1)&lt;/script&gt;';

    protected function markdown(string $source): string
    {
        return CarveConverter::markdown()->convert($source);
    }

    public function testMathContentIsEscaped(): void
    {
        $output = $this->markdown("a \$`<script>alert(1)</script>` b\n");

        $this->assertStringNotContainsString(self::PAYLOAD, $output);
        $this->assertStringContainsString(self::ESCAPED, $output);
    }

    public function testDisplayMathContentIsEscaped(): void
    {
        $this->assertStringNotContainsString(
            self::PAYLOAD,
            $this->markdown("\$\$`<script>alert(1)</script>`\n"),
        );
    }

    /**
     * The occurrence's `<abbr title=...>` was already escaped and the definition
     * line one method away was not - one output disagreeing with itself.
     */
    public function testTheAbbreviationDefinitionLineIsEscaped(): void
    {
        $output = $this->markdown("*[AB]: <script>alert(1)</script>\n\nAB\n");

        $this->assertStringNotContainsString(self::PAYLOAD, $output);
        $this->assertSame(2, substr_count($output, self::ESCAPED));
    }

    /**
     * The abbreviation KEY escapes too. The parser will not accept a `<` in a
     * term, so this slot is only reachable through AST ingest - a caller handing
     * over a tree from a database row or a bridge, which is exactly the input
     * with no parser in front of it.
     */
    public function testAnIngestedAbbreviationKeyIsEscaped(): void
    {
        $codec = new AstCodec();
        $json = $codec->encodeJson(CarveConverter::create()->parse("*[AB]: exp\n\nAB\n"));
        $document = $codec->decodeJson(str_replace('"AB"', '"<script>"', $json));

        $output = CarveConverter::markdown()->render($document);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('*[&lt;script&gt;]: ', $output);
        // The OCCURRENCE double-escapes an ingested key (`&amp;lt;script&amp;gt;`)
        // on this engine, where carve-js and carve-rs escape it once. That is a
        // pre-existing fidelity divergence on the ingest path, not this change:
        // it is inert either way, and it reads identically before and after.
        // Asserted here so the next reader does not take it for a new defect.
    }

    /**
     * Both positions escape, so the reference still matches its definition in
     * the emitted Markdown.
     */
    public function testAFootnoteLabelIsEscapedInBothPositions(): void
    {
        $output = $this->markdown(
            "x[^<script>alert(1)</script>]\n\n[^<script>alert(1)</script>]: body\n",
        );

        $this->assertStringNotContainsString(self::PAYLOAD, $output);
        $this->assertStringContainsString('[^' . self::ESCAPED . ']', $output);
        $this->assertStringContainsString('[^' . self::ESCAPED . ']: ', $output);
    }

    /**
     * An unresolved crossref keeps its authored marker (escaping `</#nope>`
     * whole would turn something a reader can act on into noise), but the TARGET
     * inside it is author content and can hold a `<`: `</#a<script>` is a
     * complete opening tag once the Markdown is rendered.
     */
    public function testAnUnresolvedCrossrefTargetIsEscaped(): void
    {
        $output = $this->markdown("</#a<script>alert(1)</script>b>\n");

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('</#a&lt;script>', $output);
    }

    public function testAnOrdinaryUnresolvedCrossrefIsUntouched(): void
    {
        $this->assertStringContainsString('</#nope>', $this->markdown("text </#nope> more\n"));
    }

    /**
     * The escape is transparent, not lossy: a consumer decodes the entity back
     * to the character before its math renderer sees the content, which is
     * exactly what the HTML target has always relied on.
     */
    public function testEscapingMathPreservesAnOrdinaryComparison(): void
    {
        $this->assertStringContainsString('a &lt; b', $this->markdown("\$`a < b`\n"));
    }

    /**
     * CONTROL: content with no HTML in it is untouched on every one of those
     * paths.
     */
    public function testOrdinaryContentIsUnchanged(): void
    {
        $output = $this->markdown("*[HT]: Hypertext\n\nHT and \$`x^2`\$ and y[^n]\n\n[^n]: note\n");

        $this->assertStringContainsString('*[HT]: Hypertext', $output);
        $this->assertStringContainsString('<abbr title="Hypertext">HT</abbr>', $output);
        $this->assertStringContainsString('[^n]', $output);
        $this->assertStringContainsString('[^n]: note', $output);
        $this->assertStringNotContainsString('&amp;', $output);
    }
}
