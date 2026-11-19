<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Bundle;
use MarkupCarve\Carve\Extension\Citations;
use MarkupCarve\Carve\Extension\Glossary;
use MarkupCarve\Carve\Extension\IndexTerms;
use PHPUnit\Framework\TestCase;

/**
 * The carve-js reference cases of markup-carve/carve-js#5071 (test/extensions-reference-apparatus.test.ts).
 */
final class ReferenceApparatusTest extends TestCase
{
    /**
     * @var string
     */
    private const GLOSS = "::: glossary\n:: HTTP\n: HyperText Transfer Protocol.\n\n:: HTML\n: HyperText Markup Language.\n:::\n";

    /**
     * @var string
     */
    private const REFS = "::: references \"References\"\n:: doe\n: Doe, J. (2020). /A book/.\n\n:: roe\n: Roe, R. (2019). Paper.\n:::\n";

    /**
     * @var string
     */
    private const INDEX = "A :index[Widget]widget, :index[gadget] and :index[widget].\n\n::: index \"Index\"\n:::\n";

    /**
     * @param string $source
     * @param \MarkupCarve\Carve\Extension\Bundle $bundle
     * @param array<string, mixed> $options
     */
    private function html(string $source, Bundle $bundle, array $options = []): string
    {
        $result = Carve::renderWithDocumentTransforms($source, $bundle->transforms(), ['extensions' => [$bundle]] + $options);
        $failures = array_filter($result->codes(), static fn (string $code): bool => str_starts_with($code, 'extension-'));
        $this->assertSame([], array_values($failures));

        return $result->html;
    }

    /**
     * Raises the memory limit to `$bytes`; never lowers it, so the rest of the suite keeps the limit it runs with.
     */
    private static function raiseMemoryLimit(int $bytes): void
    {
        $limit = trim((string)ini_get('memory_limit'));
        if ($limit === '-1') {
            return;
        }
        $units = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3];
        $current = (int)$limit * ($units[strtolower(substr($limit, -1))] ?? 1);
        if ($current < $bytes) {
            ini_set('memory_limit', (string)$bytes);
        }
    }

    /**
     * @param array<string> $lines
     */
    private static function lines(array $lines): string
    {
        return implode("\n", $lines);
    }

    public function testExpectedOfTheIssue(): void
    {
        $source = "See :cite[doe]{locator=\"p. 4\"} and :term[HTTP]:index[HTTP].\n\n"
            . "::: glossary\n:: HTTP\n: HyperText Transfer Protocol.\n:::\n\n"
            . "::: references\n:: doe\n: Doe, J. (2020). /A book/.\n:::\n\n::: index\n:::\n";
        $this->assertSame(self::lines([
            '<p>See [<a href="#ref-doe" data-cite-key="doe">1</a>, p. 4] and <a href="#gloss-HTTP" class="term">HTTP</a>'
                . '<span id="idx-http-1" class="index-term"></span>.</p>',
            '<div class="glossary">',
            '  <dl class="glossary">',
            '    <dt id="gloss-HTTP">HTTP</dt>',
            '    <dd>HyperText Transfer Protocol.</dd>',
            '  </dl>',
            '</div>',
            '<div class="references">',
            '  <ol class="references">',
            '    <li id="ref-doe">Doe, J. (2020). <em>A book</em>.</li>',
            '  </ol>',
            '</div>',
            '<div class="index">',
            '  <ul class="index">',
            '    <li>HTTP <a href="#idx-http-1" class="index-backref" aria-label="Back to HTTP">↩</a></li>',
            '  </ul>',
            '</div>',
        ]), $this->html($source, Bundle::merge(Glossary::bundle(), IndexTerms::bundle(), Citations::bundle())));
    }

    public function testGlossaryEntriesAndUses(): void
    {
        $this->assertSame(self::lines([
            '<div class="glossary">',
            '  <dl class="glossary">',
            '    <dt id="gloss-HTTP">HTTP</dt>',
            '    <dd>HyperText Transfer Protocol.</dd>',
            '    <dt id="gloss-HTML">HTML</dt>',
            '    <dd>HyperText Markup Language.</dd>',
            '  </dl>',
            '</div>',
        ]), $this->html(self::GLOSS, Glossary::bundle()));
        $out = $this->html("Use :term[HTTP] and :term[FTP].\n\n" . self::GLOSS, Glossary::bundle());
        $this->assertStringContainsString('<a href="#gloss-HTTP" class="term">HTTP</a>', $out);
        $this->assertStringContainsString('<span class="term">FTP</span>', $out);
        $out = $this->html(
            "Use :term[http], :term[Hyper  Text] and :term[Café].\n\n"
            . "::: glossary\n:: HTTP\n: Up.\n\n:: Hyper Text\n: One.\n\n:: Café\n: Two.\n:::\n",
            Glossary::bundle(),
        );
        $this->assertStringContainsString('<span class="term">http</span>', $out);
        $this->assertStringContainsString('<a href="#gloss-Hyper-Text" class="term">Hyper  Text</a>', $out);
        $this->assertStringContainsString('<a href="#gloss-Café" class="term">Café</a>', $out);
        $out = $this->html("::: glossary\n:: HTTP\n: One.\n\n:: HTTP\n: Two.\n:::\n", Glossary::bundle());
        $this->assertSame(1, substr_count($out, 'id="gloss-HTTP"'));
        $this->assertStringContainsString('<dt>HTTP</dt>', $out);
        $out = $this->html("Use :term[HTTP]{.abbr #use HREF=\"#other\"}.\n\n" . self::GLOSS, Glossary::bundle());
        $this->assertStringContainsString('<a href="#gloss-HTTP" id="use" class="term abbr">HTTP</a>', $out);
    }

    public function testGlossaryPlacementIdsAndChannels(): void
    {
        $out = $this->html("{#terms .wide}\n::: glossary \"Terms\"\nIntro.\n\n:: HTTP\n: One.\n\nSee the RFCs.\n:::\n", Glossary::bundle());
        $this->assertStringStartsWith(
            "<div id=\"terms\" class=\"glossary wide\">\n  <p class=\"admonition-title\">Terms</p>\n  <p>Intro.</p>",
            $out,
        );
        $this->assertLessThan(strpos($out, 'See the RFCs.'), strpos($out, 'gloss-HTTP'));
        $quoted = "Use :term[HTTP].\n\n> ::: glossary\n> :: HTTP\n> : Uses :term[HTML].\n>\n> :: HTML\n> : Markup.\n> :::\n";
        $out = $this->html($quoted, Glossary::bundle());
        $this->assertStringContainsString('<dt id="gloss-HTTP">HTTP</dt>', $out);
        $this->assertStringContainsString('<a href="#gloss-HTML" class="term">HTML</a>', $out);
        $out = $this->html("[x]{#gloss-HTTP} :term[HTTP]\n\n" . self::GLOSS, Glossary::bundle());
        $this->assertStringContainsString('<dt id="gloss-HTTP-2">HTTP</dt>', $out);
        $this->assertStringContainsString('<a href="#gloss-HTTP-2" class="term">HTTP</a>', $out);
        $source = "Use :term[HTTP].\n\n" . self::GLOSS;
        $this->assertSame($this->html($source, Glossary::bundle()), $this->html($source, Glossary::bundle(), ['mode' => 'static']));
        $this->assertSame(self::lines([
            '<p>Use <span class="ext-term">HTTP</span>.</p>',
            '<div class="glossary">',
            '  <dl>',
            '    <dt>HTTP</dt>',
            '    <dd>HyperText Transfer Protocol.</dd>',
            '    <dt>HTML</dt>',
            '    <dd>HyperText Markup Language.</dd>',
            '  </dl>',
            '</div>',
        ]), Carve::render($source));
        $this->assertSame(
            '<p>Use <span class="abbr">HTTP</span>.</p>',
            Carve::render('Use :term[HTTP]{.abbr}.', ['extensions' => [Glossary::bundle()->withoutRenderers()]]),
        );
        $bundle = Glossary::bundle();
        $tree = Carve::parse($source, ['extensions' => [$bundle]]);
        $before = Carve::encodeTree($tree);
        Carve::renderWithDocumentTransforms($tree, $bundle->transforms(), ['extensions' => [$bundle]]);
        $this->assertSame($before, Carve::encodeTree($tree));
    }

    public function testIndexListAndMarkers(): void
    {
        $this->assertSame(self::lines([
            '<p>A <span id="idx-widget-1" class="index-term"></span>widget, <span id="idx-gadget-1" class="index-term"></span> and '
                . '<span id="idx-widget-2" class="index-term"></span>.</p>',
            '<div class="index">',
            '  <p class="admonition-title">Index</p>',
            '  <ul class="index">',
            '    <li>gadget <a href="#idx-gadget-1" class="index-backref" aria-label="Back to gadget">↩</a></li>',
            '    <li>Widget <a href="#idx-widget-1" class="index-backref" aria-label="Back to Widget 1">↩<sup>1</sup></a> '
                . '<a href="#idx-widget-2" class="index-backref" aria-label="Back to Widget 2">↩<sup>2</sup></a></li>',
            '  </ul>',
            '</div>',
        ]), $this->html(self::INDEX, IndexTerms::bundle()));
        $sorted = $this->html(":index[b] :index[B] :index[ä] :index[a]\n\n::: index\n:::\n", IndexTerms::bundle());
        preg_match_all('/<li>(\S+) /u', $sorted, $m);
        $this->assertSame(['a', 'b', 'ä'], $m[1]);
        $this->assertStringContainsString(
            'aria-label="Zurück zu x"',
            $this->html(":index[x]\n\n::: index\n:::\n", IndexTerms::bundle('Zurück zu')),
        );
        $out = $this->html("A :index[x].[^n]\n\n[^n]: Note :index[x].\n\n::: index\n:::\n", IndexTerms::bundle());
        $this->assertStringContainsString('<p>Note <span class="index-term"></span>.', $out);
        $this->assertStringNotContainsString('idx-x-2', $out);
        $this->assertMatchesRegularExpression(
            '~<div id="ix" class="index wide">\n {2}<p>See also.</p>\n {2}<ul class="index">~u',
            $this->html(":index[x]\n\n{#ix .wide}\n::: index\nSee also.\n:::\n", IndexTerms::bundle()),
        );
        $this->assertSame(Carve::render("::: index\n:::\n"), $this->html("::: index\n:::\n", IndexTerms::bundle()));
        $this->assertSame(
            $this->html(self::INDEX, IndexTerms::bundle()),
            $this->html(self::INDEX, IndexTerms::bundle(), ['mode' => 'static']),
        );
        $this->assertSame('<p>A <span class="ext-index">x</span>.</p>', Carve::render('A :index[x].'));
        $this->assertSame('<p>A .</p>', Carve::render('A :index[x].', ['extensions' => [IndexTerms::bundle()->withoutRenderers()]]));
    }

    public function testIndexOutputIsCapped(): void
    {
        // Each document transform validates its view as a tree, which holds thousands of back-links here.
        self::raiseMemoryLimit(512 * 1024 * 1024);
        $markers = implode(' ', array_fill(0, 3000, ':index[x]'));
        $out = $this->html($markers . "\n\n::: index\n:::\n", IndexTerms::bundle(str_repeat('L', 500)));
        $this->assertLessThan(1500000, strlen($out));
        $markers = implode(' ', array_map(static fn (int $at): string => ':index[term' . $at . ']', range(0, 1999)));
        $out = $this->html($markers . "\n\n" . str_repeat("::: index\n:::\n\n", 20), IndexTerms::bundle());
        $this->assertLessThan(1500000, strlen($out));
    }

    public function testCitationsNumbersAndList(): void
    {
        $this->assertSame(self::lines([
            '<p>See [<a href="#ref-roe" data-cite-key="roe">1</a>] and [<a href="#ref-doe" data-cite-key="doe">2</a>, '
                . '<a href="#ref-roe" data-cite-key="roe">1</a>, p. 4].</p>',
            '<div class="references">',
            '  <p class="admonition-title">References</p>',
            '  <ol class="references">',
            '    <li id="ref-roe">Roe, R. (2019). Paper.</li>',
            '    <li id="ref-doe">Doe, J. (2020). <em>A book</em>.</li>',
            '  </ol>',
            '</div>',
        ]), $this->html("See :cite[roe] and :cite[doe; roe]{locator=\"p. 4\"}.\n\n" . self::REFS, Citations::bundle()));
        $this->assertStringContainsString(
            '<p>nope doe; nope [<a href="#ref-doe" data-cite-key="doe">1</a>]</p>',
            $this->html(":cite[nope] :cite[doe; nope] :cite[doe]\n\n" . self::REFS, Citations::bundle()),
        );
    }

    public function testCitationsModesAndAttributes(): void
    {
        $source = "See :cite[doe] and :cite[doe]{mode=\"suppress-author\"}.\n\n::: bibliography\n"
            . ":: [doe]{author=\"Doe\" year=\"2020\"}\n: Doe, J. A book.\n:::\n";
        $out = $this->html($source, Citations::bundle('author-date'));
        $this->assertStringContainsString(
            '<p>See (<a href="#ref-doe" data-cite-key="doe">Doe 2020</a>) and (<a href="#ref-doe" data-cite-key="doe">2020</a>).</p>',
            $out,
        );
        $this->assertStringContainsString('<ul class="references">', $out);
        $out = $this->html(":cite[doe]{mode=\"author-in-text\"} :cite[doe]{#c .x prefix=\"see\"}\n\n" . self::REFS, Citations::bundle());
        $this->assertStringContainsString(
            '<span class="citation" data-cite-mode="integral">[<a href="#ref-doe" data-cite-key="doe">1</a>]</span>',
            $out,
        );
        $this->assertStringContainsString(
            '<span id="c" class="citation x">[see <a href="#ref-doe" data-cite-key="doe">1</a>]</span>',
            $out,
        );
    }

    public function testCitationsInNotesNumberAfterTheBody(): void
    {
        $this->assertStringContainsString(
            'B [<a href="#ref-doe" data-cite-key="doe">2</a>]',
            $this->html("A :cite[roe].[^n] B :cite[doe].\n\n[^n]: :cite[roe] again.\n\n" . self::REFS, Citations::bundle()),
        );
        $this->assertStringContainsString(
            'B [<a href="#ref-doe" data-cite-key="doe">1</a>]',
            $this->html("A^[see :cite[roe]] B :cite[doe].\n\n" . self::REFS, Citations::bundle()),
        );
    }

    public function testCitationsFromACslPool(): void
    {
        $bundle = Citations::bundle(bibliography: [
            ['id' => 'doe', 'title' => 'Ignored'],
            [
                'id' => 'smith',
                'author' => [['family' => 'Smith', 'given' => 'A.'], ['literal' => 'ACME']],
                'issued' => ['date-parts' => [[2001]]],
                'title' => 'T',
            ],
        ]);
        $out = $this->html(":cite[doe] :cite[smith] :cite[doe]\n\n" . self::REFS, $bundle);
        $this->assertStringContainsString('<a id="cite-doe-1" href="#ref-doe" data-cite-key="doe">1</a>', $out);
        $this->assertStringContainsString(
            '<li id="ref-doe">Doe, J. (2020). <em>A book</em>. <a href="#cite-doe-1" class="ref-backref">↩</a> '
            . '<a href="#cite-doe-2" class="ref-backref">↩</a></li>',
            $out,
        );
        $this->assertStringContainsString(
            '<li id="ref-smith">Smith, A.; ACME (2001). T. <a href="#cite-smith-1" class="ref-backref">↩</a></li>',
            $out,
        );
        $this->assertSame(self::lines([
            '<p>A [<a id="cite-x-1" href="#ref-x" data-cite-key="x">1</a>].</p>',
            '<ol class="references">',
            '  <li id="ref-x">&lt;b&gt;. <a href="#cite-x-1" class="ref-backref">↩</a></li>',
            '</ol>',
        ]), $this->html('A :cite[x].', Citations::bundle(bibliography: [['id' => 'x', 'author' => 'nobody', 'title' => '<b>']])));
    }

    public function testCitationsChannelsAndCoreReading(): void
    {
        $source = "See :cite[doe].\n\n" . self::REFS;
        $this->assertSame($this->html($source, Citations::bundle()), $this->html($source, Citations::bundle(), ['mode' => 'static']));
        $this->assertSame('<p><span class="ext-cite">doe</span></p>', Carve::render(":cite[doe]\n"));
        $this->assertSame('<p>doe</p>', Carve::render(":cite[doe]\n", ['extensions' => [Citations::citeDefinition()]]));
        $this->assertSame('<p>doe</p>', Carve::render(":cite[doe]\n", ['extensions' => ['carve.cite']]));
    }

    public function testTheBundlesTogether(): void
    {
        $all = Bundle::merge(Glossary::bundle(), IndexTerms::bundle(), Citations::bundle());
        $html = $this->html(":term[HTTP] :index[x] :cite[doe]\n\n" . self::GLOSS . "\n::: index\n:::\n\n" . self::REFS, $all);
        $this->assertStringContainsString('href="#gloss-HTTP"', $html);
        $this->assertStringContainsString('id="idx-x-1"', $html);
        $this->assertStringContainsString('href="#ref-doe"', $html);
    }
}
