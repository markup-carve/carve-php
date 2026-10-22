<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Converter\MigrationDiagnostic;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Util\CarrierMarkers;

final class CarrierStage
{
    public function __construct(private readonly CarrierContext $context)
    {
    }

    /**
     * The host prefix a marker line carries before the marker itself: a block
     * quote's `>` runs, a list item's indentation and the item marker that
     * opens it. The placeholder keeps it, so the marker stays in its host when
     * the lifted source is read.
     */
    protected function carrierHostPrefix(string $line): string
    {
        $at = strpos($line, CarrierMarkers::HEAD);
        if ($at === false) {
            return '';
        }

        return substr($line, 0, $at);
    }

    /**
     * Join a marker whose payload spans lines into one entry (CARVE-P11-063).
     * The entry keeps the raw lines, so an unlifted marker comes back
     * verbatim; `spanned` holds the comment with each later line's host
     * prefix removed. The scan for the closing `-->` only moves forward.
     *
     * @param array<int, string> $raw
     * @param array<int, true> $htmlLines
     *
     * @return array{0: list<string>, 1: list<int>, 2: array<int, true>, 3: array<int, string>}
     */
    protected function joinSpannedCarrierMarkers(array $raw, array $htmlLines): array
    {
        $lines = [];
        $lineNumbers = [];
        $markerLines = [];
        $spanned = [];
        $paragraphLines = [];
        $close = 0;
        $count = count($raw);
        for ($at = 0; $at < $count; $at++) {
            $line = $raw[$at];
            if (isset($this->context->carrierParagraphLines[$at])) {
                $paragraphLines[count($lines)] = true;
            }
            if (isset($htmlLines[$at])) {
                $host = $this->carrierHostPrefix($line);
                $head = substr($line, strlen($host));
                if (str_starts_with($head, CarrierMarkers::HEAD) && !str_contains($head, '-->')) {
                    $close = max($close, $at + 1);
                    while ($close < $count && !str_contains($raw[$close], '-->')) {
                        $close++;
                    }
                    if ($close < $count) {
                        $continuation = preg_replace('/[^>\t]/', ' ', preg_replace('/\[[ xX]\][ \t]+$/D', '', $host) ?? $host) ?? $host;
                        $comment = $head;
                        for ($next = $at + 1; $next <= $close; $next++) {
                            $comment .= "\n" . $this->withoutCarrierHost($raw[$next], $continuation);
                        }
                        if (CarrierMarkers::marker($comment) !== null) {
                            $markerLines[count($lines)] = true;
                            $spanned[count($lines)] = $comment;
                            $lines[] = implode("\n", array_slice($raw, $at, $close - $at + 1));
                            $lineNumbers[] = $at + 1;
                            $at = $close;

                            continue;
                        }
                    }
                }
                $markerLines[count($lines)] = true;
            }
            $lines[] = $line;
            $lineNumbers[] = $at + 1;
        }
        $this->context->carrierParagraphLines = $paragraphLines;

        return [$lines, $lineNumbers, $markerLines, $spanned];
    }

    /**
     * A bare div's opener and every closer are the same bytes, `:::`
     * (CARVE-P11-063), so which one a payload is follows from the open
     * containers: a bare fence of at most the innermost opener's width closes
     * it, any other opens. An opener gets the trailing space an older export
     * wrote, which the rest of the import still reads as an opener.
     *
     * @param array<int, string> $payloads
     *
     * @return array<int, string>
     */
    protected function classifyBareFencePayloads(array $payloads): array
    {
        $open = [];
        foreach ($payloads as $at => $payload) {
            $width = CarrierMarkers::fenceWidth($payload);
            if ($width === 0) {
                continue;
            }
            if (!CarrierMarkers::isCloser($payload)) {
                $open[] = $width;

                continue;
            }
            if ($open !== [] && $width <= $open[count($open) - 1]) {
                array_pop($open);

                continue;
            }
            $payloads[$at] = $payload . ' ';
            $open[] = $width;
        }

        return $payloads;
    }

    /**
     * A line with the recorded host prefix taken off, for the fallback lines
     * travelling under an opener. A block quote writes its blank lines as a
     * bare `>`, which the opener's own prefix does not cover.
     */
    protected function withoutCarrierHost(string $line, string $host): string
    {
        if ($host === '') {
            return $line;
        }
        if (str_starts_with($line, $host)) {
            return substr($line, strlen($host));
        }

        return ltrim($line, " \t>");
    }

    /**
     * Lift every carrier marker line out of the source, leaving a placeholder.
     *
     * A set that does not balance is NEVER reconstructed: the source comes back
     * untouched, the markers import as the raw HTML they are, and the caller
     * reports one `carrier-markers-damaged` row.
     *
     * @param string $markdown
     * @param array<int, true> $htmlLines Source lines read as HTML block content.
     */
    public function prepareCarrierMarkers(string $markdown, array $htmlLines = []): string
    {
        $this->context->carrierSlots = [];
        $this->context->carrierLiteralText = [];
        $this->context->carrierDamaged = false;
        $this->context->carrierDiagnostics = [];
        $this->context->carrierToken = '';
        if ($this->context->carrierFallbackMode || !str_contains($markdown, CarrierMarkers::HEAD)) {
            return $markdown;
        }

        [$lines, $lineNumbers, $markerLines, $spanned] = $this->joinSpannedCarrierMarkers(
            explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown)),
            $htmlLines,
        );
        $payloads = [];
        $depths = [];
        $hosts = [];
        $seen = false;
        // A MARKER IS A LINE THE BLOCK STRUCTURE READ AS HTML. A code
        // construct's payload is verbatim content, so a marker-shaped line in
        // one records no container and must be left where it is
        // (markup-carve/carve-php#3038); the structure map answers that at
        // every indent and behind every host prefix, which a fence scan over
        // the raw lines could not (markup-carve/carve#2850).
        foreach ($lines as $at => $line) {
            if (!isset($markerLines[$at])) {
                continue;
            }
            $host = $this->carrierHostPrefix($line);
            $marker = CarrierMarkers::marker($spanned[$at] ?? substr($line, strlen($host)));
            $payload = $marker['payload'] ?? null;
            $depth = $marker['depth'] ?? 1;
            if ($payload !== null && ($depth > 1 || ($payload !== '[' && !str_starts_with($payload, ']{') && !str_starts_with($payload, '</#')))) {
                $payloads[$at] = $payload;
                $depths[$at] = $depth;
                $hosts[$at] = $host;
                $seen = true;
            }
        }
        if (!$seen) {
            return $markdown;
        }
        $payloads = $this->classifyBareFencePayloads($payloads);
        $pairs = [];
        $stack = [];
        $balanced = true;
        foreach ($payloads as $at => $payload) {
            $width = CarrierMarkers::fenceWidth($payload);
            if ($width === 0) {
                continue;
            }
            if (CarrierMarkers::isCloser($payload)) {
                $opener = array_pop($stack);
                if ($opener === null || CarrierMarkers::fenceWidth($payloads[$opener]) !== $width) {
                    $balanced = false;
                } else {
                    $pairs[$opener] = $at;
                }
            } else {
                if ($stack !== [] && $width <= CarrierMarkers::fenceWidth($payloads[end($stack)])) {
                    $balanced = false;
                }
                $stack[] = $at;
            }
        }
        $balanced = $balanced && $stack === [];
        if ($balanced) {
            $droppedCaptions = [];
            foreach ($payloads as $at => $payload) {
                if (isset($droppedCaptions[$at])) {
                    continue;
                }
                $width = CarrierMarkers::fenceWidth($payload);
                $opener = $width > 0 && !CarrierMarkers::isCloser($payload);
                $closer = $pairs[$at] ?? null;
                if ($opener && $closer !== null) {
                    $children = (new CarveConverter())->parse($payload . "\n" . $payloads[$closer] . "\n")->getChildren();
                    $valid = count($children) === 1 && !$children[0] instanceof Paragraph;
                } elseif (str_starts_with($payload, '{') && str_ends_with($payload, '}')) {
                    $valid = AttributeParser::isValidPayload(substr($payload, 1, -1));
                } elseif (CarrierMarkers::isCodeFence($payload)) {
                    $valid = $this->codeFenceCarrierInfo($payload) !== null;
                } elseif ($this->carrierListStyle($payload) !== null) {
                    $valid = preg_match('/(?:^|[ \t>])(?:[-+*]|[0-9]+[.)])[ \t]+(?:\[[ xX]\][ \t]+)?$/D', $hosts[$at]) !== 1;
                } else {
                    $valid = $width > 0 || CarrierMarkers::isCaption($payload)
                        || preg_match('/^::[ \t]/', $payload) === 1 || $payload === ':' || $payload === '';
                }
                if ($depths[$at] > 1 && preg_match('/^::[ \t]/', $payload) !== 1 && $payload !== ':' && $payload !== '') {
                    $valid = false;
                }
                if (!$valid) {
                    unset($payloads[$at], $depths[$at]);
                    if ($closer !== null) {
                        unset($payloads[$closer]);
                        // A composite figure's caption marker belongs to the pair it follows.
                        $caption = $closer + 1;
                        while (isset($lines[$caption]) && trim($this->withoutCarrierHost($lines[$caption], $hosts[$closer])) === '') {
                            $caption++;
                        }
                        if (isset($payloads[$caption]) && CarrierMarkers::isCaption($payloads[$caption]) && $hosts[$caption] === $hosts[$closer]) {
                            unset($payloads[$caption], $depths[$caption]);
                            $droppedCaptions[$caption] = true;
                        }
                    }
                    $this->context->carrierDiagnostics[] = new MigrationDiagnostic(
                        'carrier-marker-dropped',
                        ($opener ? 'Dropped carrier marker pair with an invalid opener: ' : 'Dropped carrier marker with an invalid payload: ')
                            . substr($lines[$at], strlen($hosts[$at])),
                        'warning',
                        'degraded',
                        'fallback',
                        'line:' . $lineNumbers[$at],
                    );
                }
            }
        }
        if (
            !$balanced || !$this->carrierDepthsPair($payloads, $depths)
            || !$this->carrierSetBalances($payloads, $lines, $hosts, $depths)
        ) {
            $this->context->carrierDamaged = true;
            $this->context->carrierDiagnostics = [];

            return $markdown;
        }

        $token = 'CARVECARRIER';
        while (str_contains($markdown, $token)) {
            $token .= 'X';
        }
        $this->context->carrierToken = $token;

        $out = [];
        $drop = 0;
        $replaceParagraph = false;
        $skipBlank = false;
        $host = '';
        $last = count($lines) - 1;
        $open = [];
        foreach ($lines as $at => $line) {
            if (!isset($payloads[$at])) {
                // Keep entity-spelled paragraph markers as text until their Carve host is restored.
                if ($open !== [] && isset($this->context->carrierParagraphLines[$at])) {
                    $line = preg_replace_callback(
                        '/^([ \t>]*)(?:&#(43|45);)(?=[ \t])/',
                        function (array $match) use ($token): string {
                            $literal = $token . 'LITERAL' . count($this->context->carrierLiteralText) . 'Z';
                            $this->context->carrierLiteralText[$literal] = chr((int)$match[2]);

                            return $match[1] . $literal;
                        },
                        $line,
                    ) ?? $line;
                }
                $body = $this->withoutCarrierHost($line, $host);
                if ($drop > 0 && ($replaceParagraph || preg_match('/^\*\*.+\*\*$/D', $body) === 1) && $body !== '') {
                    if (!$replaceParagraph) {
                        $drop--;
                    }
                    $skipBlank = true;

                    continue;
                }
                // The LAST element is the source's trailing newline, not a
                // separator the dropped fallback brought with it: consuming it
                // would leave the Carve output without its own final newline.
                if ($skipBlank && $at !== $last && $body === '') {
                    $skipBlank = false;
                    $drop = 0;
                    $replaceParagraph = false;

                    continue;
                }
                $skipBlank = false;
                if ($body === '') {
                    $drop = 0;
                    $replaceParagraph = false;
                }
                $out[] = $line;

                continue;
            }
            $payload = $payloads[$at];
            $host = $hosts[$at];
            $width = CarrierMarkers::fenceWidth($payload);
            // A bare fence opens a plain div unless it matches the active fence.
            $closer = $payload === '';
            if ($width > 0) {
                $closer = CarrierMarkers::isCloser($payload) && $open !== [] && $open[count($open) - 1] === $width;
                if ($closer) {
                    array_pop($open);
                } else {
                    $open[] = $width;
                }
            }
            $styleMarker = $this->carrierListStyle($payload) !== null;
            $blankHost = preg_replace('/[^>\t]/', ' ', $host) ?? $host;
            // A style marker after the item's own paragraph still belongs to
            // that item: step back over the blank line and the paragraph's
            // continuation lines to the item's marker line.
            $opener = $at - 1;
            if ($styleMarker) {
                while ($opener > 0 && trim($lines[$opener], " \t>") === '') {
                    $opener--;
                }
                while (
                    $opener > 0 && str_starts_with($lines[$opener], $host) && trim($lines[$opener - 1], " \t>") !== ''
                    && preg_match('/^[ \t>]*(?:[-+*]|[0-9]+[.)])[ \t]+(?=\S)/', $lines[$opener]) !== 1
                ) {
                    $opener--;
                }
            }
            $insideItem = $opener >= 0 && preg_match('/^[ \t>]*(?:[-+*]|[0-9]+[.)])[ \t]+(?=\S)/', $lines[$opener], $listStart) === 1
                && strlen($host) >= strlen($listStart[0]);
            $nextNumber = $styleMarker && preg_match('/^([0-9]+)[.)][ \t]+/', $this->withoutCarrierHost($lines[$at + 1] ?? '', $host), $number) === 1
                ? (int)$number[1] : 1;
            if (
                $styleMarker && $at > 0 && trim($this->withoutCarrierHost($lines[$at - 1], $host)) !== ''
                && !str_starts_with($payloads[$at - 1] ?? '', '{') && !$insideItem
            ) {
                $out[] = $blankHost;
            }
            $out[] = $host . $token . count($this->context->carrierSlots) . 'Z';
            if ($styleMarker && (!$insideItem || $nextNumber !== 1)) {
                $out[] = $blankHost;
            }
            $equation = null;
            if (CarrierMarkers::isCaption($payload)) {
                $before = $at - 1;
                while ($before >= 0 && trim($lines[$before], " \t>") === '') {
                    $before--;
                }
                $previous = $this->withoutCarrierHost($lines[$before] ?? '', $host);
                if (preg_match('/^(?:(?:[-+*]|\d+[.)])[ \t]+)?(\$\$[^$]+\$\$)[ \t]*$/D', $previous, $math) === 1) {
                    $equation = rtrim((new MarkdownToCarve(convertMath: true))->convert($math[1]), "\n");
                }
            }
            $this->context->carrierSlots[] = [
                'payload' => $payload,
                'depth' => $depths[$at],
                'closer' => $closer,
                'caption' => CarrierMarkers::isCaption($payload),
                'fence' => CarrierMarkers::isCodeFence($payload),
                'fenceTightAfter' => CarrierMarkers::isCodeFence($payload) && $this->carrierFenceTightAfter($lines, $at, $host),
                'style' => $styleMarker,
                'styleTightAfter' => $styleMarker && $insideItem && $this->carrierStyleTightAfter($lines, $at, $host),
                'styleBlankBeforeOuter' => $styleMarker && $this->carrierStyleBlankBeforeOuter($lines, $at, $host),
                'equation' => $equation,
            ];
            // The writer emitted the opener's title and label, and a group
            // caption, as bold lines of their own; the payload carries them
            // now, so the fallback would be the same text twice. A CAPTION
            // MARKER REPLACES ITS RENDERED PARAGRAPH rather than adding a
            // second copy of it (PART 11 §10s).
            $replaceParagraph = CarrierMarkers::isCaption($payload) || str_starts_with($payload, ':: ');
            $drop = $replaceParagraph ? 1 : ($closer ? 0 : $this->carrierFallbackLines($payload));
            if ($replaceParagraph || str_starts_with($payload, '{')) {
                $out[] = preg_replace('/[^>\t]/', ' ', $host) ?? $host;
            }
        }

        return implode("\n", $out);
    }

    /**
     * How many bold fallback lines the Markdown target wrote for an opener's
     * own metadata: one for a quoted title, one for a `[label]`.
     */
    protected function carrierFallbackLines(string $payload): int
    {
        if (CarrierMarkers::isCaption($payload)) {
            return 1;
        }
        $width = CarrierMarkers::fenceWidth($payload);
        if ($width === 0) {
            return 0;
        }
        $rest = substr($payload, $width);
        $lines = 0;
        if (preg_match('/[ \t]?\[.*\]$/D', $rest, $label) === 1) {
            $lines++;
            $rest = substr($rest, 0, -strlen($label[0]));
        }
        if (preg_match('/[ \t]"[^"]*"$/D', $rest) === 1) {
            $lines++;
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @param array<int, string> $payloads
     * @param string $host
     * @param int $start
     */
    protected function carrierParagraphFollows(array $lines, array $payloads, string $host, int $start): bool
    {
        $paragraph = [];
        $count = count($lines);
        for ($at = $start; $at < $count && !isset($payloads[$at]); $at++) {
            $line = $this->withoutCarrierHost($lines[$at], $host);
            if ($line === '') {
                break;
            }
            $paragraph[] = $line;
        }
        $source = (new MarkdownToCarve())->convert(implode("\n", $paragraph));
        $blocks = CarveConverter::create()->parse($source)->getChildren();

        return count($blocks) === 1 && $blocks[0] instanceof Paragraph;
    }

    /**
     * Definition markers pair at their stated depth.
     *
     * @param array<int, string> $payloads
     * @param array<int, int> $depths
     */
    protected function carrierDepthsPair(array $payloads, array $depths): bool
    {
        $lists = [];
        foreach ($payloads as $at => $payload) {
            $depth = $depths[$at] ?? 1;
            $term = preg_match('/^::[ \t]/', $payload) === 1;
            if (!$term && $payload !== ':' && $payload !== '') {
                if ($depth > 1) {
                    return false;
                }

                continue;
            }
            $open = count($lists);
            if ($term) {
                if ($depth === $open) {
                    $lists[$open - 1] = false;
                } elseif ($depth === $open + 1 && ($depth === 1 || $lists[$open - 1])) {
                    $lists[] = false;
                } else {
                    return false;
                }
            } elseif ($depth !== $open) {
                return false;
            } elseif ($payload === ':') {
                $lists[$open - 1] = true;
            } else {
                array_pop($lists);
            }
        }

        return $lists === [];
    }

    /**
     * @param array<int, string> $payloads
     * @param list<string> $lines
     * @param array<int, string> $hosts
     * @param array<int, int> $depths
     */
    protected function carrierSetBalances(array $payloads, array $lines, array $hosts, array $depths): bool
    {
        $open = [];
        $definitions = [];
        $previousAt = null;
        $previousPayload = null;
        foreach ($payloads as $at => $payload) {
            $listDepth = $depths[$at] ?? 1;
            $next = $this->withoutCarrierHost($lines[$at + 1] ?? '', $hosts[$at]);
            $nestedTerm = $previousAt === $at - 1 && $previousPayload === ':';
            $previousAt = $at;
            $previousPayload = $payload;
            if (str_starts_with($payload, '{')) {
                $nextMarker = $payloads[$at + 1] ?? null;
                $sameHost = $nextMarker !== null
                    && (preg_replace('/[^>\t]/', ' ', $hosts[$at + 1]) ?? $hosts[$at + 1])
                === (preg_replace('/[^>\t]/', ' ', $hosts[$at]) ?? $hosts[$at]);
                $lostBlock = $sameHost
                    && !str_starts_with($nextMarker, ':: ') && !CarrierMarkers::isCodeFence($nextMarker) && $this->carrierListStyle($nextMarker) === null
                    && (CarrierMarkers::fenceWidth($nextMarker) === 0 || CarrierMarkers::isCloser($nextMarker));
                if (!str_ends_with($payload, '}') || !AttributeParser::isValidPayload(substr($payload, 1, -1)) || $next === '' || $lostBlock) {
                    return false;
                }

                continue;
            }
            if (str_starts_with($payload, ':: ')) {
                $key = (preg_replace('/[^>\t]/', ' ', $hosts[$at]) ?? $hosts[$at]) . ':' . count($open);
                $last = count($definitions) - 1;
                if ($last < 0 || $definitions[$last]['key'] !== $key || $definitions[$last]['depth'] < $listDepth || $nestedTerm) {
                    $definitions[] = ['key' => $key, 'depth' => $listDepth];
                }
                if ($next === '' || isset($payloads[$at + 1]) || !$this->carrierParagraphFollows($lines, $payloads, $hosts[$at], $at + 1)) {
                    return false;
                }

                continue;
            }
            if ($payload === ':' || $payload === '') {
                $key = (preg_replace('/[^>\t]/', ' ', $hosts[$at]) ?? $hosts[$at]) . ':' . count($open);
                $last = count($definitions) - 1;
                if ($last < 0 || $definitions[$last]['key'] !== $key || $definitions[$last]['depth'] !== $listDepth) {
                    return false;
                }
                if ($payload === '') {
                    array_pop($definitions);
                } else {
                    $definitions[$last]['defined'] = true;
                }

                continue;
            }
            if (CarrierMarkers::isCaption($payload)) {
                if ($next === '' || isset($payloads[$at + 1]) || !$this->carrierParagraphFollows($lines, $payloads, $hosts[$at], $at + 1)) {
                    return false;
                }

                continue;
            }
            if (CarrierMarkers::isCodeFence($payload)) {
                if (
                    $this->codeFenceCarrierInfo($payload) === null
                    || preg_match('/^[ \t]{0,3}(?:`{3,}|~{3,})/', $next) !== 1
                    || isset($payloads[$at + 1])
                ) {
                    return false;
                }

                continue;
            }
            if ($this->carrierListStyle($payload) !== null) {
                if (
                    preg_match('/^[0-9]+[.)][ \t]+\S/', $next) !== 1 || isset($payloads[$at + 1])
                    || preg_match('/^\[\^[^]]+\]:[ \t]*$/D', trim($lines[$at - 1] ?? '')) === 1
                ) {
                    return false;
                }

                continue;
            }
            $width = CarrierMarkers::fenceWidth($payload);
            if ($width === 0) {
                return false;
            }
            if (CarrierMarkers::isCloser($payload) && $open !== [] && $width <= $open[count($open) - 1]) {
                if (array_pop($open) !== $width) {
                    return false;
                }

                continue;
            }
            if ($open !== [] && $width <= $open[count($open) - 1]) {
                return false;
            }
            $open[] = $width;
        }

        return $open === [] && $definitions === [];
    }

    /**
     * The canonical title and label following a code fence's language token.
     */
    protected function codeFenceCarrierInfo(string $payload): ?string
    {
        if (preg_match('/^(`{3,}|~{3,})(.*)$/D', $payload, $match) !== 1) {
            return null;
        }
        $children = (new CarveConverter())->parse($payload . "\n" . $match[1] . "\n")->getChildren();
        $block = $children[0] ?? null;
        if (
            count($children) !== 1 || !$block instanceof CodeBlock
            || ($block->getHeader() === null && $block->getLabel() === null)
        ) {
            return null;
        }
        $info = $match[2];
        if ($block->getLanguage() !== null) {
            $info = preg_replace('/^[ \t]*\S+[ \t]*/', '', $info) ?? $info;
        } else {
            $info = ltrim($info, " \t");
        }

        return $info;
    }

    protected function carrierListStyle(string $payload, ?int $start = null): ?string
    {
        if (preg_match('/^([A-Za-z]+[.)])(?: ([A-Za-z]+[.)]))?$/D', $payload, $markers) !== 1) {
            return null;
        }
        if (isset($markers[2])) {
            $source = $markers[1] . " item\n" . $markers[2] . " item\n";
        } else {
            $first = substr($markers[1], 0, -1);
            if ($start !== null && preg_match('/^[IVXLCDM]$/i', $first) === 1) {
                $roman = ['i' => 1, 'v' => 5, 'x' => 10, 'l' => 50, 'c' => 100, 'd' => 500, 'm' => 1000][strtolower($first)];
                if ($roman === $start) {
                    return ctype_lower($first) ? 'i' : 'I';
                }
                if (ord(strtolower($first)) - 96 === $start) {
                    return ctype_lower($first) ? 'a' : 'A';
                }
            }
            $source = $markers[1] . " item\n";
        }
        $lists = (new CarveConverter())->parse($source)->getChildren();
        $list = $lists[0] ?? null;

        if (isset($markers[2]) && (count($lists) !== 1 || !$list instanceof ListBlock || count($list->getChildren()) !== 2)) {
            return null;
        }

        return $list instanceof ListBlock && $list->getListType() === ListBlock::TYPE_ORDERED ? $list->getStyle() : null;
    }

    /**
     * Whether the next block begins directly after the carried code fence.
     *
     * @param list<string> $lines
     * @param int $markerAt
     * @param string $host
     */
    protected function carrierFenceTightAfter(array $lines, int $markerAt, string $host): bool
    {
        // Quote-hosted fence bodies can lose their list indent on import.
        if (str_contains($host, '>')) {
            return false;
        }
        $opener = ltrim($this->withoutCarrierHost($lines[$markerAt + 1] ?? '', $host), " \t");
        if (preg_match('/^(`{3,}|~{3,})/', $opener, $match) !== 1) {
            return false;
        }
        $run = $match[1];
        for ($at = $markerAt + 2, $count = count($lines); $at < $count; $at++) {
            $line = trim($this->withoutCarrierHost($lines[$at], $host));
            if (preg_match('/^(`{3,}|~{3,})$/D', $line, $close) === 1 && $close[1][0] === $run[0] && strlen($close[1]) >= strlen($run)) {
                return isset($lines[$at + 1]) && trim($this->withoutCarrierHost($lines[$at + 1], $host)) !== '';
            }
        }

        return false;
    }

    /**
     * @param list<string> $lines
     * @param int $markerAt
     * @param string $host
     */
    protected function carrierStyleTightAfter(array $lines, int $markerAt, string $host): bool
    {
        for ($at = $markerAt + 1, $count = count($lines); $at < $count; $at++) {
            $line = $lines[$at];
            if (trim($line) === '') {
                $next = $at + 1;
                while ($next < $count && trim($lines[$next]) === '') {
                    $next++;
                }
                if ($next === $count) {
                    return true;
                }
                // A loose styled list keeps going after its blank line.
                $body = str_starts_with($lines[$next], $host) ? substr($lines[$next], strlen($host)) : null;
                if ($body === null || preg_match('/^[0-9]+[.)][ \t]+/', $body) !== 1) {
                    return false;
                }
                $at = $next - 1;

                continue;
            }
            if (str_starts_with($line, $host)) {
                $body = substr($line, strlen($host));
                if (preg_match('/^[0-9]+[.)][ \t]+/', $body) === 1 || preg_match('/^[ \t]+\S/', $body) === 1) {
                    continue;
                }
            }

            return true;
        }

        return true;
    }

    /**
     * @param list<string> $lines
     * @param string $host
     * @param int $markerAt
     */
    protected function carrierStyleBlankBeforeOuter(array $lines, int $markerAt, string $host): bool
    {
        for ($at = $markerAt + 1, $count = count($lines); $at < $count; $at++) {
            if (preg_match('/^[ \t>]*(?:[-+*]|[0-9]+[.)])[ \t]+\S/', $lines[$at]) !== 1 || str_starts_with($lines[$at], $host)) {
                continue;
            }

            return $at > 0 && trim($lines[$at - 1]) === '';
        }

        return false;
    }

    public function attachedCaptionCount(Node $node): int
    {
        $count = $node instanceof Figure && $node->getCaption() !== null ? 1 : 0;
        if ($node instanceof Table || $node instanceof FigureGroup) {
            $count += $node->getCaption() === null ? 0 : 1;
        }
        foreach ($node->getChildren() as $child) {
            $count += $this->attachedCaptionCount($child);
        }

        return $count;
    }

    /**
     * Write every lifted payload back as the Carve line it is.
     *
     * The blank lines around it are the canonical writer's: a container's closer
     * hugs its body, and two siblings are separated by one blank line.
     */
    public function restoreCarrierMarkers(string $carve): string
    {
        if ($this->context->carrierSlots === []) {
            return $carve;
        }
        $pattern = '/^' . preg_quote($this->context->carrierToken, '/') . '(\d+)Z$/D';
        // Each line as its host prefix, its text, and which kind of marker, if
        // any, produced it: 'open' for an opener or the attribute line
        // travelling with it, 'close' for a bare closer, 'caption' for a
        // composite figure's caption line.
        /** @var list<array{lead: string, text: string, kind: string|null, fence?: bool, fenceTightAfter?: bool, styleTightAfter?: bool, styleBlankBeforeOuter?: bool, tightFenceOpener?: string, depth?: int}> $items */
        $items = [];
        foreach (explode("\n", $carve) as $line) {
            // THE PREFIX IS WHATEVER STANDS BEFORE THE TOKEN rather than a
            // character class: a container opening a list item puts the marker
            // behind that item's `-`, and leaving the token in the output would
            // be corruption rather than a missed restore.
            $at = strpos($line, $this->context->carrierToken);
            if ($at === false || preg_match($pattern, rtrim(substr($line, $at)), $match) !== 1) {
                $lead = strlen($line) - strlen(ltrim($line, " \t>"));
                $items[] = ['lead' => substr($line, 0, $lead), 'text' => substr($line, $lead), 'kind' => null];

                continue;
            }
            $slot = $this->context->carrierSlots[(int)$match[1]];
            if ($slot['equation'] !== null) {
                // Restore the equation alone, preserving attributes and the item opener.
                $before = count($items) - 1;
                while ($before >= 0 && $items[$before]['text'] === '' && $items[$before]['kind'] === null) {
                    $before--;
                }
                if ($before >= 0 && $items[$before]['kind'] === null) {
                    if (preg_match('/^(?:(?:[-+*]|\d+[.)])[ \t]+)?/', $items[$before]['text'], $prefix) === 1) {
                        $items[$before]['text'] = $prefix[0] . $slot['equation'];
                    }
                }
            }
            $kind = $slot['style'] ? 'style' : ($slot['caption'] ? 'caption' : ($slot['closer'] ? 'close' : 'open'));
            $items[] = ['lead' => substr($line, 0, $at), 'text' => $slot['payload'], 'kind' => $kind, 'fence' => $slot['fence'], 'fenceTightAfter' => $slot['fenceTightAfter'], 'styleTightAfter' => $slot['styleTightAfter'], 'styleBlankBeforeOuter' => $slot['styleBlankBeforeOuter'], 'depth' => $slot['depth']];
        }
        $items = $this->anchorCarrierClosers($items);
        $definitions = [];
        foreach ($items as $at => $item) {
            $last = count($definitions) - 1;
            if ($item['kind'] !== null && str_starts_with($item['text'], ':: ')) {
                $depth = $item['depth'] ?? 1;
                if ($last < 0 || $definitions[$last]->pending || $definitions[$last]->depth < $depth) {
                    $original = preg_replace('/[^>\t]/', ' ', $item['lead']) ?? $item['lead'];
                    $base = $last < 0 ? $original : $definitions[$last]->base . '  ';
                    if ($last >= 0 && $definitions[$last]->depth < $depth) {
                        $host = substr($item['lead'], strlen($definitions[$last]->original));
                        if (trim($host) !== '') {
                            $base .= preg_replace_callback(
                                '/(?:[-+*]|\d+[.)])[ \t]+$/D',
                                static fn (array $match): string => str_repeat(' ', strlen($match[0])),
                                $host,
                            ) ?? $host;
                        }
                        $items[$at]['lead'] = $base;
                        if ($definitions[$last]->pending && $definitions[$last]->marker !== null) {
                            $items[$at]['lead'] = $definitions[$last]->base . ': ' . $host;
                            $marker = $definitions[$last]->marker;
                            $items[$marker] = ['lead' => $items[$marker]['lead'], 'text' => '', 'kind' => null];
                            $definitions[$last]->pending = false;
                        }
                    }
                    if ($last >= 0 && $definitions[$last]->marker !== null && $definitions[$last]->depth === $depth) {
                        $items[$at] = ['lead' => $definitions[$last]->base . ': ', 'text' => $item['text'], 'kind' => $item['kind']];
                        $marker = $definitions[$last]->marker;
                        $items[$marker] = ['lead' => $items[$marker]['lead'], 'text' => '', 'kind' => null];
                        $definitions[$last]->pending = false;
                    }
                    $definitions[] = new CarrierDefinition($base, $original, $depth);
                } else {
                    $items[$at] = ['lead' => $definitions[$last]->base, 'text' => $item['text'], 'kind' => $item['kind']];
                }

                continue;
            }
            if ($last < 0) {
                continue;
            }
            if ($item['kind'] !== null && $item['text'] === '') {
                if ($definitions[$last]->pending && $definitions[$last]->marker !== null) {
                    $marker = $definitions[$last]->marker;
                    $items[$marker] = ['lead' => $items[$marker]['lead'], 'text' => ': {empty}', 'kind' => 'open'];
                }
                $items[$at] = ['lead' => $definitions[$last]->base, 'text' => '', 'kind' => 'close', 'depth' => $item['depth'] ?? 1];
                array_pop($definitions);

                continue;
            }
            if ($item['kind'] !== null && $item['text'] === ':') {
                $definitions[$last]->pending = true;
                $definitions[$last]->marker = $at;

                continue;
            }
            if ($definitions[$last]->marker !== null && !($item['fence'] ?? false) && $item['kind'] !== 'style') {
                $lead = $item['text'] === '' ? $definitions[$last]->base
                    : $definitions[$last]->base . '  ' . substr($item['lead'], strlen($definitions[$last]->original));
                $wasPending = $definitions[$last]->pending && $item['text'] !== '';
                if ($wasPending) {
                    $lead = $definitions[$last]->base . ': ' . substr($item['lead'], strlen($definitions[$last]->original));
                    $marker = $definitions[$last]->marker;
                    $items[$marker] = ['lead' => $items[$marker]['lead'], 'text' => '', 'kind' => null];
                    $definitions[$last]->pending = false;
                }
                $items[$at] = [
                    'lead' => $lead,
                    'text' => $item['text'],
                    'kind' => $wasPending ? 'definition' : $item['kind'],
                    'depth' => $definitions[$last]->depth,
                ];
            }
        }
        $items = $this->restoreListStyleMarkers($items);
        $spelled = [];
        $emptyCloser = false;
        $tightFenceCloser = null;
        $dropFenceBlank = false;
        $items = $this->separateCarrierLines($items);
        for ($at = 0, $count = count($items); $at < $count; $at++) {
            $item = $items[$at];
            if ($dropFenceBlank) {
                $dropFenceBlank = false;
                if ($item['kind'] === null && $item['text'] === '') {
                    continue;
                }
            }
            if ($item['fence'] ?? false) {
                $info = $this->codeFenceCarrierInfo($item['text']);
                $below = $at + 1;
                while (isset($items[$below]) && $items[$below]['kind'] === null && $items[$below]['text'] === '') {
                    $below++;
                }
                if (
                    $info !== null && isset($items[$below])
                    && preg_match('/^(`{3,}|~{3,})(.*)$/D', $items[$below]['text'], $fence) === 1
                ) {
                    if (trim($item['lead']) !== '') {
                        $items[$below]['lead'] = $item['lead'];
                    }
                    $language = trim($fence[2]);
                    $items[$below]['text'] = $fence[1] . $language . ($language === '' ? '' : ' ') . $info;
                    if ($item['fenceTightAfter'] ?? false) {
                        $items[$below]['tightFenceOpener'] = $fence[1];
                    }

                    continue;
                }
            }
            if ($item['tightFenceOpener'] ?? false) {
                $tightFenceCloser = $item['tightFenceOpener'];
            } elseif ($tightFenceCloser !== null && preg_match('/^(`{3,}|~{3,})[ \t]*$/D', $item['text'], $close) === 1 && $close[1][0] === $tightFenceCloser[0] && strlen($close[1]) >= strlen($tightFenceCloser)) {
                $tightFenceCloser = null;
                $dropFenceBlank = true;
            }
            if ($emptyCloser && $item['text'] === '') {
                continue;
            }
            if ($item['kind'] === 'close' && $item['text'] === '' && ($item['depth'] ?? 1) > 1) {
                $next = $at + 1;
                while (isset($items[$next]) && $items[$next]['text'] === '') {
                    $next++;
                }
                if (isset($items[$next]) && $items[$next]['kind'] === 'definition' && ($items[$next]['depth'] ?? 1) < $item['depth']) {
                    $emptyCloser = true;

                    continue;
                }
            }
            if ($item['kind'] === 'close' && $item['text'] === '' && str_contains($item['lead'], '>')) {
                $next = $at + 1;
                while (isset($items[$next]) && $items[$next]['text'] === '') {
                    $next++;
                }
                // A quoted closer that ends the document leaves no blank quote line.
                if (!isset($items[$next]) || (($item['depth'] ?? 1) > 1 && $items[$next]['kind'] === 'close')) {
                    $emptyCloser = true;

                    continue;
                }
            }
            $emptyCloser = $item['kind'] === 'close' && $item['text'] === '' && trim($item['lead']) === '';
            // The space classifyBareFencePayloads gave a bare opener is not
            // source, and a payload spanning lines continues behind the
            // lead's continuation (CARVE-P11-063).
            $text = $item['kind'] === 'open' && preg_match('/^:{3,} $/D', $item['text']) === 1 ? rtrim($item['text']) : $item['text'];
            if ($item['kind'] !== null && str_contains($text, "\n")) {
                $text = str_replace("\n", "\n" . (preg_replace('/[^>\t]/', ' ', $item['lead']) ?? $item['lead']), $text);
            }
            $spelled[] = $text === '' && trim($item['lead']) === ''
                ? '' : $item['lead'] . $text;
        }

        return rtrim(implode("\n", $spelled), " \t\n") . "\n";
    }

    /**
     * @param list<array{lead: string, text: string, kind: string|null, fence?: bool, fenceTightAfter?: bool, styleTightAfter?: bool, styleBlankBeforeOuter?: bool, tightFenceOpener?: string, depth?: int}> $items
     *
     * @return list<array{lead: string, text: string, kind: string|null, fence?: bool, fenceTightAfter?: bool, styleTightAfter?: bool, styleBlankBeforeOuter?: bool, tightFenceOpener?: string, depth?: int}>
     */
    protected function restoreListStyleMarkers(array $items): array
    {
        foreach ($items as $at => $item) {
            if ($item['kind'] !== 'style') {
                continue;
            }
            $below = $at + 1;
            while (isset($items[$below]) && $items[$below]['kind'] === null && $items[$below]['text'] === '') {
                $below++;
            }
            if (!isset($items[$below]) || preg_match('/^([0-9]+)([.)])([ \t]+.*)$/D', $items[$below]['text'], $first) !== 1) {
                continue;
            }
            $start = (int)$first[1];
            $style = $this->carrierListStyle($item['text'], $start);
            if ($style === null) {
                continue;
            }
            $lead = $items[$below]['lead'];
            $counter = $start;
            $delimiter = $first[2];
            $lastStyled = null;
            for ($cursor = $below, $last = array_key_last($items); $cursor <= $last; $cursor++) {
                if (!isset($items[$cursor])) {
                    continue;
                }
                $line = $items[$cursor];
                if ($line['text'] === '') {
                    continue;
                }
                if ($line['lead'] !== $lead) {
                    if (strlen($line['lead']) > strlen($lead)) {
                        continue;
                    }

                    break;
                }
                if (preg_match('/^([0-9]+)([.)])([ \t]+.*)$/D', $line['text'], $marker) !== 1 || (int)$marker[1] !== $counter || $marker[2] !== $delimiter) {
                    break;
                }
                $line['text'] = $this->carrierStyledNumber($counter, $style) . $delimiter . $marker[3];
                $items[$cursor] = $line;
                $lastStyled = $cursor;
                $counter++;
            }
            if (($item['styleTightAfter'] ?? false) && $lastStyled !== null) {
                $onlyTrailingBlanks = true;
                for ($look = $lastStyled + 1, $last = array_key_last($items); $look <= $last; $look++) {
                    if (isset($items[$look]) && $items[$look]['text'] !== '') {
                        $onlyTrailingBlanks = false;

                        break;
                    }
                }
                if ($onlyTrailingBlanks) {
                    for ($look = $at - 1; $look >= 0; $look--) {
                        if ($items[$look]['text'] === '{loose}') {
                            unset($items[$look]);

                            break;
                        }
                        if (preg_match('/^(?:[-+*]|[0-9]+[.)])[ \t]+\S/', $items[$look]['text']) === 1) {
                            if (isset($items[$look - 1]) && $items[$look - 1]['text'] === '{loose}') {
                                unset($items[$look - 1]);
                            }

                            break;
                        }
                    }
                }
                for ($after = $lastStyled + 1, $last = array_key_last($items); $after <= $last; $after++) {
                    if (!isset($items[$after])) {
                        continue;
                    }
                    $following = $items[$after];
                    if ($following['kind'] === null && $following['text'] === '') {
                        $next = $after + 1;
                        while (isset($items[$next]) && $items[$next]['text'] === '') {
                            $next++;
                        }
                        if (isset($items[$next])) {
                            if (strlen($items[$next]['lead']) > strlen($lead) || preg_match('/^(?:[-+*]|[0-9]+[.)])[ \t]+\S/', $items[$next]['text']) !== 1) {
                                break;
                            }
                            if ($after > $lastStyled + 1 && strlen($items[$next]['lead']) === strlen($lead)) {
                                break;
                            }
                            if (($item['styleBlankBeforeOuter'] ?? false) && strlen($items[$next]['lead']) < strlen($lead)) {
                                break;
                            }
                        }
                        unset($items[$after]);

                        continue;
                    }
                    if ($following['kind'] !== null) {
                        break;
                    }
                    if (strlen($following['lead']) < strlen($lead) && preg_match('/^(?:[-+*]|[0-9]+[.)])[ \t]+\S/', $following['text']) === 1) {
                        break;
                    }
                }
            }
            for ($drop = $at; $drop < $below; $drop++) {
                unset($items[$drop]);
            }
        }

        return array_values($items);
    }

    protected function carrierStyledNumber(int $number, string $style): string
    {
        if ($style === 'a' || $style === 'A') {
            $letter = chr((($number - 1) % 26) + 97);

            return $style === 'A' ? strtoupper($letter) : $letter;
        }
        $values = [[1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'], [100, 'C'], [90, 'XC'], [50, 'L'], [40, 'XL'], [10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I']];
        $roman = '';
        foreach ($values as [$value, $token]) {
            while ($number >= $value) {
                $roman .= $token;
                $number -= $value;
            }
        }

        return $style === 'i' ? strtolower($roman) : $roman;
    }

    /**
     * Put every restored closer at its OPENER's column.
     *
     * Where a container opens a list item and its body begins with a list, the
     * closer's placeholder is a lazy continuation of that inner item's
     * paragraph, so the written Carve puts it at the inner content column and
     * the container would close in the wrong host. The opener's own prefix is
     * the answer, with any list marker in it blanked out, because a closer
     * cannot repeat an item's marker.
     *
     * @param list<array{lead: string, text: string, kind: string|null, fence?: bool, fenceTightAfter?: bool, styleTightAfter?: bool, styleBlankBeforeOuter?: bool, tightFenceOpener?: string, depth?: int}> $items
     *
     * @return list<array{lead: string, text: string, kind: string|null, fence?: bool, fenceTightAfter?: bool, styleTightAfter?: bool, styleBlankBeforeOuter?: bool, tightFenceOpener?: string, depth?: int}>
     */
    protected function anchorCarrierClosers(array $items): array
    {
        $open = [];
        $closed = null;
        foreach ($items as $at => $item) {
            if ($item['kind'] === 'open' && CarrierMarkers::fenceWidth($item['text']) > 0) {
                // A TASK BOX IS NOT PART OF THE COLUMN. It stands in the item's
                // content, so the body of a container opening a task item sits
                // at the item's own content column, not past the box.
                $lead = preg_replace('/\[[ xX]\][ \t]+$/', '', $item['lead']) ?? $item['lead'];
                $open[] = preg_replace('/[^>\t]/', ' ', $lead) ?? $lead;
                $closed = null;

                continue;
            }
            if ($item['kind'] === 'close' && CarrierMarkers::isCloser($item['text']) && $open !== []) {
                $closed = array_pop($open);
                $items[$at]['lead'] = $closed;

                continue;
            }
            // A CAPTION HANGS ON THE CLOSING FENCE, so it stands at that
            // closer's column. Its placeholder is a lazy continuation of the
            // same inner item, and a caption at the wrong column attaches to
            // nothing.
            if ($item['kind'] === 'caption' && $closed !== null) {
                $items[$at]['lead'] = $closed;
            }
        }

        return $items;
    }

    /**
     * Give every restored marker line the blank lines the canonical writer puts
     * around it: a container's opener and closer hug its body, and what follows
     * a closer is a block of its own.
     *
     * @param list<array{lead: string, text: string, kind: string|null, fence?: bool, fenceTightAfter?: bool, styleTightAfter?: bool, styleBlankBeforeOuter?: bool, tightFenceOpener?: string, depth?: int}> $items
     *
     * @return list<array{lead: string, text: string, kind: string|null, fence?: bool, fenceTightAfter?: bool, styleTightAfter?: bool, styleBlankBeforeOuter?: bool, tightFenceOpener?: string, depth?: int}>
     */
    protected function separateCarrierLines(array $items): array
    {
        $count = count($items);
        $hugged = [];
        // A line carrying nothing but its host prefix is blank: inside a block
        // quote the canonical writer spells a blank line as a bare `>`.
        $blank = static fn (array $item): bool => $item['kind'] === null && $item['text'] === '';
        for ($at = 0; $at < $count; $at++) {
            if (!$blank($items[$at])) {
                continue;
            }
            $end = $at;
            while ($end < $count && $blank($items[$end])) {
                $end++;
            }
            $before = $at - 1;
            while ($before >= 0 && $blank($items[$before])) {
                $before--;
            }
            $above = $before >= 0 ? $items[$before]['kind'] : null;
            $below = $end < $count ? $items[$end]['kind'] : null;
            $marker = null;
            if ($below === 'close' || $below === 'caption' || $below === 'definition') {
                $marker = $items[$end];
            } elseif ($above === 'open') {
                $marker = $items[$before];
            } elseif ($above === 'close' && ($below === 'close' || $below === 'caption')) {
                $marker = $items[$end];
            }
            // The blank has to share the marker's HOST to be inside it: a `>`
            // line next to a marker standing at column 0 belongs to a block
            // quote of its own.
            if ($marker !== null) {
                for ($drop = $at; $drop < $end; $drop++) {
                    $blankLead = rtrim($items[$drop]['lead']);
                    $markerLead = rtrim(in_array($marker['kind'], ['open', 'definition'], true) ? (preg_replace('/[^>\t]/', ' ', $marker['lead']) ?? $marker['lead']) : $marker['lead']);
                    if (($marker['depth'] ?? 1) > 1 && str_contains($blankLead, '>') && str_contains($markerLead, '>')) {
                        $blankLead = preg_replace('/[ \t]+/', ' ', substr($blankLead, strcspn($blankLead, '>'))) ?? $blankLead;
                        $markerLead = preg_replace('/[ \t]+/', ' ', substr($markerLead, strcspn($markerLead, '>'))) ?? $markerLead;
                    }
                    if ($blankLead !== $markerLead) {
                        $marker = null;

                        break;
                    }
                }
            }
            if ($marker !== null) {
                for ($drop = $at; $drop < $end; $drop++) {
                    $hugged[$drop] = true;
                }
            }
            $at = $end - 1;
        }

        $out = [];
        foreach ($items as $at => $item) {
            if (isset($hugged[$at])) {
                continue;
            }
            if (
                $out !== []
                && (end($out)['kind'] === 'close' || end($out)['kind'] === 'caption')
                && $item['kind'] !== 'close'
                && $item['kind'] !== 'caption'
                && $item['kind'] !== 'definition'
                && $item['text'] !== ''
                // A closer and what follows take a blank line between them only
                // where they are SIBLINGS. A list item opening after a closer
                // is a block of the host above, and a blank there would turn a
                // tight list loose.
                && strlen($item['lead']) >= strlen(end($out)['lead'])
            ) {
                // The separator carries the closer's own host prefix, so a
                // blank line inside a block quote is still a `>` line.
                $out[] = ['lead' => rtrim(end($out)['lead']), 'text' => '', 'kind' => null];
            }
            $out[] = $item;
        }

        return $out;
    }

    public function emptyFootnoteStyleMarker(string $markdown): bool
    {
        $lines = explode("\n", $markdown);
        $fence = null;
        foreach ($lines as $at => $line) {
            $content = ltrim($line, " \t>");
            if ($fence !== null) {
                if (
                    preg_match('/^(`{3,}|~{3,})[ \t]*$/D', $content, $close) === 1
                    && $close[1][0] === $fence[0] && strlen($close[1]) >= strlen($fence)
                ) {
                    $fence = null;
                }

                continue;
            }
            if (preg_match('/^(`{3,}|~{3,})/', $content, $open) === 1) {
                $fence = $open[1];

                continue;
            }
            if (preg_match('/^\[\^[^]]+\]:[ \t]*$/D', $content) !== 1) {
                continue;
            }
            $marker = CarrierMarkers::marker(ltrim($lines[$at + 1] ?? ''));
            $next = ltrim($lines[$at + 2] ?? '');
            if (
                $marker !== null && $this->carrierListStyle($marker['payload']) !== null
                && preg_match('/^[0-9]+[.)][ \t]+\S/', $next) === 1
            ) {
                return true;
            }
        }

        return false;
    }
}
