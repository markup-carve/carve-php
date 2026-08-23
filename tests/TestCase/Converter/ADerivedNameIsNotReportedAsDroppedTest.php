<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An attribute the RENDERER writes back is not a loss the report announces.
 *
 * The importer drops a derived accessible name on purpose: baked into source it
 * is indistinguishable from an authored one, and PART 9 §12 writes a name only
 * where the author wrote NONE - so the imported copy wins on every later render
 * and the document can no longer be localized (markup-carve/carve#1500). The
 * drop is free, because the renderer regenerates the same string.
 *
 * The report did not know that. It asks the EMITTED document whether an
 * attribute came back, and re-renders it with a bare converter - so every value
 * a renderer this importer was never handed derives is absent from that
 * document by construction, and the absence was read as a loss. Three
 * `attribute-dropped` rows stood on the spec's shared `derived-accessible-name`
 * fixture, which states none and which carve-js answers with none
 * (markup-carve/carve#1502).
 *
 * NOT ONE NAME, THE CATEGORY. The accessible-names family is thirteen keys wide
 * (markup-carve/carve#1511) and the fixture exercises one of them, so a branch
 * keyed on the fence word would be a check that cannot fail for the other
 * twelve. The report asks the same predicates the WRITERS drop by, so the cases
 * below are the shapes the importer already recognizes rather than a second
 * roster: the fence, the tab set, the code group, their panels, the endnotes
 * section, the admonition, the index back-link.
 *
 * WHY A FALSE POSITIVE IS THE BUG. A consumer who finds one row describing an
 * attribute that is plainly still in the rendered output discounts every OTHER
 * row, including the true ones - which is why the controls at the bottom assert
 * that a real loss is still reported and a real name is still kept.
 */
class ADerivedNameIsNotReportedAsDroppedTest extends TestCase
{
    /**
     * Every diagnostic as `[code, message]`, off the typed property.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function diagnostics(string $html): array
    {
        $rows = [];
        foreach ((new HtmlToCarve())->convertWithReport($html)->diagnostics as $diagnostic) {
            $rows[] = [$diagnostic->code, $diagnostic->message];
        }

        return $rows;
    }

    private function carve(string $html): string
    {
        return trim((new HtmlToCarve())->convertWithReport($html)->value);
    }

    /**
     * The shared fixture, spelled out.
     *
     * `tests/spec/tests/html-import/derived-accessible-name` states an empty
     * report for exactly this input. It reaches this engine only with the
     * corpus pin the bump PR carries, so the two lines are written here as
     * well - a fixture that arrives later cannot pin behavior that has to be
     * right now, and the literal spelling survives a later renumbering of the
     * corpus.
     */
    public function testTheSharedFixtureInputReportsNothing(): void
    {
        $html = '<pre class="mermaid" role="img" aria-label="mermaid">graph TD; A--&gt;B;</pre>' . "\n"
            . '<pre class="mermaid" role="img" aria-label="Architecture overview">graph TD; A--&gt;B;</pre>';

        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * Both halves at once, on the fence the fixture's second line pins.
     *
     * `FencedRenderExtension::namingDefaults()` decides the role and the name
     * INDEPENDENTLY - a fence carrying the author's own `aria-label` has been
     * named, and still takes the role - so the author's words survive into the
     * source while the role goes unreported. Asserting only the report would be
     * satisfied by the wrong fix, which is to stop keeping the name.
     */
    public function testAnAuthoredNameSurvivesWhileTheRoleBesideItIsNotReported(): void
    {
        $html = '<pre class="mermaid" role="img" aria-label="Architecture overview">x</pre>';

        $this->assertSame("{.mermaid aria-label=\"Architecture overview\"}\n```\nx\n```", $this->carve($html));
        $this->assertSame([], $this->diagnostics($html));
    }

    /**
     * The category, one shape per row.
     *
     * Each input is what this engine's own renderers emit for that shape, so a
     * renderer that changes what it derives fails here rather than shipping a
     * report that disagrees with it.
     *
     * @return array<string, array{0: string}>
     */
    public static function derivedNamingShapes(): array
    {
        return [
            'claimed fence' => ['<pre class="mermaid" role="img" aria-label="mermaid">x</pre>'],
            'tab set, css mode' => ['<div class="tabs" role="group" aria-label="Tabs"><p>a</p></div>'],
            'tab set, aria mode' => ['<div class="tabs" role="tablist" aria-label="Tabs"><p>a</p></div>'],
            'code group, css mode' => ['<div class="code-group" role="group" aria-label="Code examples"><p>a</p></div>'],
            'code group, aria mode' => ['<div class="code-group" role="tablist" aria-label="Code examples"><p>a</p></div>'],
            'panel, css mode' => [
                '<div class="tabs"><label class="tabs-label">One</label>'
                . '<div class="tabs-panel" role="group" aria-label="One"><p>a</p></div></div>',
            ],
            'panel, aria mode' => ['<div class="code-group-panel" role="tabpanel"><p>a</p></div>'],
            'endnotes section' => [
                '<section role="doc-endnotes" aria-label="Footnotes"><ol><li id="fn1"><p>n</p></li></ol></section>',
            ],
            'untitled admonition' => ['<aside class="admonition note" aria-label="Note"><p>b</p></aside>'],
            'titled admonition' => [
                '<aside class="admonition note" aria-labelledby="adm-1">'
                . '<p class="admonition-title" id="adm-1">Careful</p><p>b</p></aside>',
            ],
            'index back-link' => [
                '<ul class="index"><li>Term '
                . '<a href="#idx-term-1" class="index-backref" aria-label="Back to Term">&#8617;</a></li></ul>',
            ],
        ];
    }

    #[DataProvider('derivedNamingShapes')]
    public function testADerivedNamingAttributeIsNotReported(string $html): void
    {
        $reported = [];
        foreach ($this->diagnostics($html) as [$code, $message]) {
            if ($code === 'attribute-dropped' && preg_match('/\b(role|aria-label|aria-labelledby)\b/', $message) === 1) {
                $reported[] = $message;
            }
        }

        $this->assertSame([], $reported);
    }

    /**
     * The control that keeps the fix from becoming "stop reporting a role".
     *
     * A `role` the renderer does not derive for this element is the author's,
     * and it is still stripped - `$skipAttributes` has held it since before any
     * of this - so the diagnostic about it is true and stays.
     */
    public function testARoleTheRendererDoesNotDeriveIsStillReported(): void
    {
        $this->assertSame(
            [['attribute-dropped', 'Dropped unsupported attribute role on <p>']],
            $this->diagnostics('<p role="note">x</p>'),
        );

        // Same element as the fixture's, with a role no fence is written with.
        $this->assertSame(
            [['attribute-dropped', 'Dropped unsupported attribute role on <pre>']],
            $this->diagnostics('<pre class="mermaid" role="note">x</pre>'),
        );
    }

    /**
     * The control on the other side: a name that DIFFERS from the derived one
     * is the author's, is kept, and was never a candidate for silence.
     *
     * `<div class="tabs">` derives `Tabs`, so `Registerkarten` is not it. The
     * value comes back in the source and the report stays empty because nothing
     * was lost - not because the row was suppressed.
     */
    public function testAnAuthoredNameOnADerivingShapeIsKept(): void
    {
        $html = '<div class="tabs" aria-label="Registerkarten"><p>a</p></div>';

        $this->assertSame("{aria-label=Registerkarten}\n::: tabs\na\n:::", $this->carve($html));
        $this->assertSame([], $this->diagnostics($html));
    }
}
