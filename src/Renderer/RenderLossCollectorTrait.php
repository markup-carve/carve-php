<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use InvalidArgumentException;
use MarkupCarve\Carve\Node\Node;

trait RenderLossCollectorTrait
{
    /**
     * The sink a blanked destination came from.
     *
     * `target` already names the renderer and `nodeType` is `inline` for both,
     * so the message is the only place the sink kind survives
     * (markup-carve/carve#2686).
     *
     * @var string
     */
    protected const DESTINATION_SINK_LINK = 'link';

    /**
     * @var string
     */
    protected const DESTINATION_SINK_IMAGE = 'image';

    /**
     * @var array<string, string>
     */
    private const DESTINATION_DENIED_MESSAGES = [
        self::DESTINATION_SINK_LINK => 'Blanked a denied destination scheme',
        self::DESTINATION_SINK_IMAGE => 'Blanked a denied image source',
    ];

    private ?string $renderLossTarget = null;

    private int $renderLossMaximum = 100;

    private int $renderLossTotal = 0;

    /**
     * @var array<string, int>
     */
    private array $renderLossCounts = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $renderLosses = [];

    /**
     * @var array<int, true>
     */
    private array $seenRubyLosses = [];

    public function beginRenderLossCollection(string $target, int $maximum): void
    {
        if ($maximum < 0) {
            throw new InvalidArgumentException('Maximum render losses must be non-negative.');
        }
        $this->renderLossTarget = $target;
        $this->renderLossMaximum = $maximum;
        $this->renderLossTotal = 0;
        $this->renderLossCounts = [];
        $this->renderLosses = [];
        $this->seenRubyLosses = [];
    }

    public function finishRenderLossCollection(): array
    {
        $result = [
            'losses' => $this->renderLosses,
            'totalLosses' => $this->renderLossTotal,
            'lossCounts' => $this->renderLossCounts,
            'truncated' => $this->renderLossTotal > count($this->renderLosses),
        ];
        $this->renderLossTarget = null;

        return $result;
    }

    protected function recordRawFormatDropped(Node $node, string $format, string $nodeType): void
    {
        if ($this->renderLossTarget === null) {
            return;
        }
        $this->renderLossTotal++;
        $this->renderLossCounts['raw-format-dropped'] = ($this->renderLossCounts['raw-format-dropped'] ?? 0) + 1;
        if (count($this->renderLosses) >= $this->renderLossMaximum) {
            return;
        }
        $loss = [
            'code' => 'raw-format-dropped',
            'format' => $format,
            'target' => $this->renderLossTarget,
            'nodeType' => $nodeType,
            'message' => sprintf('Dropped %s raw format "%s" while rendering %s', $nodeType, $format, $this->renderLossTarget),
        ];
        if ($node->getPos() !== null) {
            $loss['pos'] = $node->getPos()->toArray();
        }
        $this->renderLosses[] = $loss;
    }

    /**
     * Blank a destination whose scheme the PART 9 section 25 sink denylist
     * denies, and owe the render-loss report one row for it.
     *
     * THE ONE PLACE A BLANKED DESTINATION IS REPORTED. Seven sink expressions
     * across the HTML, Markdown and ANSI targets blank a destination, and a
     * row emitted per site is how three copies of one rule drift apart
     * (markup-carve/carve#2679). Each target routes its own wrapper here
     * instead. The emitted value is unchanged: a denied scheme still blanks,
     * in both safe modes, because the flag gates neither the hardening nor the
     * reporting.
     */
    protected function blankDeniedDestination(
        string $url,
        string $sink = self::DESTINATION_SINK_LINK,
        ?Node $node = null,
        ?int $at = null,
    ): string {
        $blanked = HtmlRenderer::blankDangerousScheme($url);
        if ($blanked === $url) {
            return $url;
        }
        $this->recordDestinationDenied($sink, $node, $at);

        return $blanked;
    }

    /**
     * How many rows the bounded report holds, which is where the NEXT row lands.
     *
     * A target that has to render a link's label before it can probe the link's
     * own destination reads this first and passes it back as `$at`, so the row
     * keeps its document position. The label is INSIDE the link, and
     * `CARVE-P2-024` orders losses by position.
     */
    protected function renderLossIndex(): int
    {
        return count($this->renderLosses);
    }

    /**
     * @param string $sink One of the `DESTINATION_SINK_*` constants
     * @param \MarkupCarve\Carve\Node\Node|null $node
     * @param int|null $at Document position in the bounded array; null appends
     */
    protected function recordDestinationDenied(string $sink, ?Node $node, ?int $at = null): void
    {
        if ($this->renderLossTarget === null) {
            return;
        }
        $this->renderLossTotal++;
        $this->renderLossCounts['destination-denied'] = ($this->renderLossCounts['destination-denied'] ?? 0) + 1;
        $position = $at ?? count($this->renderLosses);
        if ($position >= $this->renderLossMaximum) {
            return;
        }
        $loss = [
            'code' => 'destination-denied',
            'target' => $this->renderLossTarget,
            'nodeType' => 'inline',
            'message' => self::DESTINATION_DENIED_MESSAGES[$sink],
        ];
        if ($node !== null && $node->getPos() !== null) {
            $loss['pos'] = $node->getPos()->toArray();
        }
        if ($position >= count($this->renderLosses)) {
            // The ordinary case, and an append rather than a splice at the end:
            // `array_splice()` reindexes the array, so splicing every row makes
            // collection quadratic in a large report. Raised by codex review.
            $this->renderLosses[] = $loss;

            return;
        }
        array_splice($this->renderLosses, $position, 0, [$loss]);
        // The bound holds the FIRST rows in document order, so an insert past it
        // drops the latest rather than refusing the one that belongs here.
        if (count($this->renderLosses) > $this->renderLossMaximum) {
            array_pop($this->renderLosses);
        }
    }

    protected function recordRubyFlattened(Node $node): void
    {
        if ($this->renderLossTarget === null || isset($this->seenRubyLosses[spl_object_id($node)])) {
            return;
        }
        $this->seenRubyLosses[spl_object_id($node)] = true;
        $this->renderLossTotal++;
        $this->renderLossCounts['ruby-flattened'] = ($this->renderLossCounts['ruby-flattened'] ?? 0) + 1;
        if (count($this->renderLosses) >= $this->renderLossMaximum) {
            return;
        }
        $loss = [
            'code' => 'ruby-flattened',
            'target' => $this->renderLossTarget,
            'nodeType' => 'inline',
            'message' => 'Flattened ruby annotations while rendering ' . $this->renderLossTarget,
        ];
        if ($node->getPos() !== null) {
            $loss['pos'] = $node->getPos()->toArray();
        }
        $this->renderLosses[] = $loss;
    }
}
