<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\RenderContext;
use MarkupCarve\Carve\Renderer\RendererInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The expansion budget caps the bytes a cross-reference label may write. It did
 * not cap the work spent reaching them: every reference derived and rendered the
 * target's whole label, measured it, and only then asked the budget, so once the
 * budget was spent each further reference paid for a render it discarded
 * (carve-php#2647).
 *
 * Counting the derivations rather than the seconds: the machine these run on is
 * shared, so a stopwatch would measure its neighbours.
 */
class ACrossReferenceLabelPastTheBudgetIsNotRenderedTest extends TestCase
{
    /**
     * A heading long enough that the per-render budget runs out partway through
     * the references, with a slug (`A`) far shorter than the label it resolves
     * to - which is the amplification the budget exists to bound.
     */
    protected function source(int $headingLength, int $references): string
    {
        return '# A' . str_repeat('!', $headingLength - 1) . "\n\n"
            . str_repeat('</#A> ', $references) . "\n";
    }

    protected function countingTracker(): HeadingIdTracker
    {
        return new class extends HeadingIdTracker {
            public int $derivations = 0;

            public function getLabelNodesForId(string $id, bool $insideLink = true): ?array
            {
                $this->derivations++;

                return parent::getLabelNodesForId($id, $insideLink);
            }
        };
    }

    /**
     * @return array{0: string, 1: int} The rendered output and the number of
     *   label derivations the render performed.
     */
    protected function renderCountingDerivations(string $target, string $source): array
    {
        $tracker = $this->countingTracker();
        $renderer = $this->rendererUsing($target, $tracker);
        $output = CarveConverter::create(renderer: $renderer)->convert($source);

        /** @var int $derivations */
        $derivations = $tracker->derivations;

        return [$output, $derivations];
    }

    protected function rendererUsing(string $target, HeadingIdTracker $tracker): RendererInterface
    {
        if ($target === 'html') {
            return new class ($tracker) extends HtmlRenderer {
                public function __construct(HeadingIdTracker $tracker)
                {
                    parent::__construct();
                    $this->sharedRenderContext = new RenderContext($tracker);
                }
            };
        }

        if ($target === 'markdown') {
            return new class ($tracker) extends MarkdownRenderer {
                public function __construct(HeadingIdTracker $tracker)
                {
                    parent::__construct();
                    $this->headingIdTracker = $tracker;
                }
            };
        }

        if ($target === 'plain') {
            return new class ($tracker) extends PlainTextRenderer {
                public function __construct(HeadingIdTracker $tracker)
                {
                    parent::__construct();
                    $this->headingIdTracker = $tracker;
                }
            };
        }

        return new class ($tracker) extends AnsiRenderer {
            public function __construct(HeadingIdTracker $tracker)
            {
                parent::__construct();
                $this->headingIdTracker = $tracker;
            }
        };
    }

    /**
     * The skip must not reach a reference the budget can still pay for: a
     * document under budget renders every label in full, nodes and all.
     */
    public function testALabelWithinBudgetIsStillRenderedInFull(): void
    {
        $html = (new CarveConverter())->convert("# A `b` _c_\n\n</#A-b-c> </#A-b-c>\n");

        $this->assertSame(
            '<section id="A-b-c">' . "\n"
            . '  <h1>A <code>b</code> <u>c</u></h1>' . "\n"
            . '  <p><a href="#A-b-c">A <code>b</code> <u>c</u></a>'
            . ' <a href="#A-b-c">A <code>b</code> <u>c</u></a></p>' . "\n"
            . '</section>' . "\n",
            $html,
        );
    }

    /**
     * The invariant the fix establishes: a label is derived only when it is
     * emitted. Every reference past the budget degrades to the authored slug
     * and costs no derivation.
     */
    public function testOnlyTheReferencesThatFitTheBudgetDeriveTheirLabel(): void
    {
        $headingLength = 60000;
        $references = 40;
        [$html, $derivations] = $this->renderCountingDerivations(
            'html',
            $this->source($headingLength, $references),
        );

        $label = 'A' . str_repeat('!', $headingLength - 1);
        $emitted = substr_count($html, '<a href="#A">' . $label . '</a>');
        $degraded = substr_count($html, '<a href="#A">A</a>');

        $this->assertGreaterThan(0, $emitted, 'no reference stayed within budget');
        $this->assertGreaterThan(0, $degraded, 'the budget was never exhausted');
        $this->assertSame($references, $emitted + $degraded);
        $this->assertSame($emitted, $derivations, 'a label was derived and then discarded');
    }

    /**
     * Ten times the references must not cost ten times the work. The budget is
     * sized from the source, and a reference costs six bytes of it against a
     * label of tens of thousands, so the same number of labels fits either way.
     *
     * @param string $target
     */
    #[DataProvider('targets')]
    public function testTheWorkDoesNotGrowWithTheReferenceCount(string $target): void
    {
        $headingLength = 60000;
        [, $few] = $this->renderCountingDerivations($target, $this->source($headingLength, 40));
        [, $many] = $this->renderCountingDerivations($target, $this->source($headingLength, 400));

        $this->assertGreaterThan(0, $few);
        $this->assertLessThan(40, $few, 'the budget was never exhausted');
        $this->assertSame($few, $many, 'work still grows with the reference count');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function targets(): array
    {
        return [
            'html' => ['html'],
            'markdown' => ['markdown'],
            'plain' => ['plain'],
            'ansi' => ['ansi'],
        ];
    }

    /**
     * The per-target costs are render state, so a renderer reused for a second
     * document must not answer from the first one's budget.
     */
    public function testASecondRenderStartsFromAFreshBudget(): void
    {
        $source = $this->source(60000, 40);
        $converter = new CarveConverter();

        $this->assertSame($converter->convert($source), $converter->convert($source));
    }
}
