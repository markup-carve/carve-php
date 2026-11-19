<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\Resolver;

final class AbbreviationTemplateTest extends RenderTestCase
{
    public function testC2H178RunsAndPlacement(): void
    {
        $src = "*[A]: Alpha\n%% stop\n*[B]: Beta\n\n# H\n\n*[C]: Gamma";
        $want = "<template data-carve-abbreviations>*[A]: Alpha</template>\n"
            . "<template data-carve-abbreviations>*[B]: Beta</template>\n"
            . "<section data-carve-generated-id id=\"H\">\n  <h1>H</h1>\n"
            . "  <template data-carve-abbreviations>*[C]: Gamma</template>\n</section>";
        self::assertSame($want, Carve::toHtml($src, ['roundtrip' => true]));
        self::assertStringNotContainsString('<template', Carve::toHtml($src));
    }

    public function testC2H179CanonicalTextAndEscaping(): void
    {
        $src = "{#x .c data-carve-ref=x}\n*[RnD]: R&D <research> \"and\" development\n"
            . "*[U]: Unused\n*[U]: Second\n\nRnD.";
        $want = "<template data-carve-abbreviations>{#x .c data-carve-ref=x}\n"
            . "*[RnD]: R&amp;D &lt;research&gt; \"and\" development\n\n"
            . "*[U]: Unused\n\n*[U]: Second</template>\n"
            . '<p><abbr title="R&amp;D &lt;research&gt; &quot;and&quot; development">RnD</abbr>.</p>';
        self::assertSame($want, Carve::toHtml($src, ['roundtrip' => true]));
    }

    public function testC2H172BidiRemainsInTemplateText(): void
    {
        $src = "*[A]: x\u{202E}y\n\nA.";
        self::assertStringContainsString("*[A]: x\u{202E}y</template>", Carve::toHtml($src, ['roundtrip' => true]));
    }

    public function testC2H181UnspellableExpansionReportsLoss(): void
    {
        $doc = self::node(self::doc([

            'type' => 'abbreviation_def',
            'key' => 'A',
            'namespace' => 'abbr',
            'expansion' => "one\ntwo",
        ]));
        $opts = Options::from(['roundtrip' => true]);
        Resolver::resolve($doc, null, $opts);
        [$html, $log] = HtmlRenderer::renderWithLog($doc, $opts);
        self::assertSame('<template data-carve-abbreviations>*[A]: one two</template>', $html);
        self::assertContains('field-unspellable', array_column($log->entries, 'code'));
    }

    public function testC2W055NearestExpansionSpelling(): void
    {
        foreach (["A\nB" => 'A B', "A\r\nB" => 'A B', "A\rB" => 'A B', 'A ' => 'A'] as $value => $written) {
            $doc = self::node(self::doc([
                'type' => 'abbreviation_def',
                'key' => 'A',
                'namespace' => 'abbr',
                'expansion' => $value,
            ]));
            $opts = Options::from(['roundtrip' => true]);
            Resolver::resolve($doc, null, $opts);
            [$html, $log] = HtmlRenderer::renderWithLog($doc, $opts);
            self::assertSame('<template data-carve-abbreviations>*[A]: ' . $written . '</template>', $html);
            self::assertContains('field-unspellable', array_column($log->entries, 'code'));
        }
    }

    public function testC2W117UnspellableDefinitionAttributesReportLoss(): void
    {
        $doc = self::node(self::doc([
            'type' => 'abbreviation_def',
            'key' => 'A',
            'namespace' => 'abbr',
            'expansion' => 'one',
            'lang' => 'bad lang',
            'attrs' => ['classes' => ['a b'], 'keyValues' => [['bad key', 'x'], ['good', "x\ny"]]],
        ]));
        $opts = Options::from(['roundtrip' => true]);
        Resolver::resolve($doc, null, $opts);
        [, $log] = HtmlRenderer::renderWithLog($doc, $opts);
        $losses = array_filter($log->entries, static fn (array $entry): bool => $entry['code'] === 'field-unspellable');
        self::assertGreaterThanOrEqual(4, count($losses));
    }
}
