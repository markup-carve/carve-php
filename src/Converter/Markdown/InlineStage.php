<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

use Closure;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\EscapesCarveConstructs;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\ImportedCrossrefs;
use MarkupCarve\Carve\Converter\MigrationDiagnostic;
use MarkupCarve\Carve\Converter\ReportsMigrationFidelity;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\BracketScanner;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\Utility\QuotedSlotEscaper;

final class InlineStage
{
    use EscapesCarveConstructs;
    use ReportsMigrationFidelity;

    /**
     * @var string
     */
    private const DEFINITION_MARKER = '(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)?';

    public function __construct(
        private InlineContext $context,
        private readonly Closure $cmarkReadsTaskCheckbox,
        private readonly Closure $escapeCarveOnlyMarker,
        private readonly Closure $backtickRunLength,
        private readonly Closure $escapeTableInlineText,
        private readonly Closure $escapeTablePipes,
    ) {
    }

    public function __clone(): void
    {
        $this->context = clone $this->context;
    }

    public function convertInlineFormatting(string $line, bool $terminal = true, bool $table = false, bool $unwrapEmptyDestinations = true): string
    {
        if (ImportWork::$on) {
            ImportWork::$inlineLines++;
            ImportWork::$inlineBytes += strlen($line);
        }
        $work = new InlineWork($line);
        $sourceInput = $work->sourceInput;
        $foldedSourceLines = [];
        foreach ($this->context->foldedHeadingSources as $heading => $segments) {
            if (str_ends_with($line, $heading)) {
                $lead = strlen($line) - strlen($heading);
                $foldedSourceLines = array_map(static fn (array $segment): array => ['offset' => $segment['offset'] + $lead, 'line' => $segment['line']], $segments);
                unset($this->context->foldedHeadingSources[$heading]);

                break;
            }
        }
        // Past any container markers: a decode at the head of the CONTENT is at
        // the head of a block just as one at column 0 is.
        $contentPrefix = preg_match('/^[ \t]*(?:>[ \t]?|[-*+][ \t]+|(?:[0-9]{1,9}|[A-Za-z])[.)][ \t]+)*/', $line, $prefixMatch) === 1
            ? $prefixMatch[0]
            : '';
        // A heading's marker separator is a run of spaces and none of it is
        // content, so the head of a heading's CONTENT sits past the marker too
        // (markup-carve/carve-rs#2449).
        // Not in a table cell: a `#` run there is literal text, and the space
        // after it is content Carve holds.
        $headingHead = !$table
            && preg_match('/^#{1,6}[ \t]/', substr($line, strlen($contentPrefix)), $headingMatch) === 1;
        if ($headingHead) {
            $contentPrefix .= $headingMatch[0];
        }
        $bareContentStart = !str_contains(" \t", substr($line, strlen($contentPrefix), 1) ?: 'x');
        $line = ($this->escapeCarveOnlyMarker)($line);
        $protected =&$work->protected;
        $protectedSources =&$work->protectedSources;
        $codeFallbacks =&$work->codeFallbacks;
        $protect = static function (string $span, ?string $source = null) use (&$protected, &$protectedSources): string {
            $protected[] = $span;
            $protectedSources[] = $source ?? $span;

            return "\x00P" . (count($protected) - 1) . "\x00";
        };
        $line = $this->maskDestinationGlyphs($line, $protect);

        $definitionPrefix = '[ \t]*(?:>[ \t]*)*' . self::DEFINITION_MARKER;
        $rawDefinition = preg_match('/^' . $definitionPrefix . '\[((?!\^)(?:[^\]\\\\]|\\\\.)+)\]:/', $sourceInput, $definition) === 1;
        $guardBareLinkPrefix = function (string $before) use ($protect): string {
            if ($before === '') {
                return $before;
            }
            $last = $before[strlen($before) - 1];
            if ($last === ']') {
                $depth = 1;
                $matchedLabel = false;
                for ($open = strlen($before) - 2; $open >= 0; $open--) {
                    if ($before[$open] !== '[' && $before[$open] !== ']') {
                        continue;
                    }
                    $slashes = 0;
                    for ($escape = $open - 1; $escape >= 0 && $before[$escape] === '\\'; $escape--) {
                        $slashes++;
                    }
                    if ($slashes % 2 !== 0) {
                        continue;
                    }
                    $depth += $before[$open] === '[' ? -1 : 1;
                    if ($depth === 0) {
                        $matchedLabel = true;
                        $label = substr($before, $open + 1, strlen($before) - $open - 2);
                        $key = $this->normalizeReferenceLabel($this->decodeLinkTitle($label));
                        $canonical = $this->context->referenceDefinitionLabels[$key] ?? null;
                        if (
                            ($canonical !== null && $this->normalizeReferenceLabel($canonical) === $this->normalizeReferenceLabel($label))
                            || isset($this->context->emptyDestinationLabels[$key])
                        ) {
                            return $before;
                        }

                        break;
                    }
                }
                if (!$matchedLabel) {
                    return $before;
                }
            }
            if (!in_array($last, ['!', '^', ']', '\\'], true)) {
                return $before;
            }
            $at = strlen($before) - ($last === '\\' ? 1 : 2);
            while ($at >= 0 && $before[$at] === '\\') {
                $at--;
            }
            $source = substr($before, $at + 1);
            $slashes = strlen($source) - ($last === '\\' ? 0 : 1);
            $written = $last === '\\'
                ? str_repeat('\\', 2 * intdiv($slashes + 1, 2))
                : str_repeat('\\', 2 * intdiv($slashes, 2)) . '\\' . $last;

            return substr($before, 0, $at + 1) . $protect($written, $source);
        };
        $definitionBody = $rawDefinition
            ? (preg_replace('/^' . $definitionPrefix . '(?=\[)/', '', $sourceInput) ?? $sourceInput)
            : '';
        $isReferenceDefinition = $rawDefinition
            && ReferenceDefinition::read($definitionBody, 0) !== null
            && isset($this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($definition[1]))]);
        if (!$isReferenceDefinition && (str_contains($sourceInput, ':') || str_contains($sourceInput, 'www.'))) {
            $cursor = 0;
            $captured = '';
            $rawOpaqueEnds = [];
            $rawUrls = BareLinks::rawUrls($sourceInput, function (int $at) use ($sourceInput, &$rawOpaqueEnds): ?int {
                $end = $this->opaqueHtmlEnd($sourceInput, $at, $rawOpaqueEnds) ?? ($this->htmlTagAt($sourceInput, $at)['end'] ?? null);
                if ($end !== null) {
                    return $end;
                }
                if (preg_match('/\G<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[A-Za-z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Za-z0-9.-]+)>/', $sourceInput, $angle, 0, $at) === 1) {
                    return $at + strlen($angle[0]);
                }

                return null;
            }, fn (int $at): ?int => $this->markdownActivationTailEnd($sourceInput, $at));
            foreach ($rawUrls as $candidate) {
                $body = substr($sourceInput, $candidate['start'], $candidate['end'] - $candidate['start']);
                $href = $candidate['prefix'] . $body;
                $document = new Document();
                $paragraph = new Paragraph();
                $link = new Link($href);
                foreach (preg_split('/([\[\]])/', $body, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
                    if ($piece !== '') {
                        $link->appendChild(new Text($piece));
                    }
                }
                $paragraph->appendChild($link);
                $document->appendChild($paragraph);
                $written = rtrim((new CarveRenderer())->render($document), "\n");
                if (preg_match('/\[[^]]+\]\([^)]*$/', $body) === 1) {
                    $destinationAt = strrpos($written, '](');
                    if ($destinationAt !== false) {
                        $label = substr($written, 0, $destinationAt);
                        $unclosedAt = strrpos($label, ']\\(');
                        $bareAt = strrpos($label, '](');
                        if ($unclosedAt !== false && ($bareAt === false || $bareAt < $unclosedAt)) {
                            $written = substr_replace($label, '](', $unclosedAt, 3) . substr($written, $destinationAt);
                        }
                    }
                }
                if ($table) {
                    $written = ($this->escapeTablePipes)($written);
                }
                $captured .= $guardBareLinkPrefix(substr($sourceInput, $cursor, $candidate['start'] - $cursor)) . $protect($written, $body);
                $cursor = $candidate['end'];
            }
            $line = ($this->escapeCarveOnlyMarker)($captured . substr($sourceInput, $cursor));
        }

        $preserveReferenceAutolinks = static function (string $value) use ($protect, $table): string {
            return preg_replace_callback('/<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[a-zA-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>/', static function (array $match) use ($protect, $table): string {
                $body = substr($match[0], 1, -1);
                $prefix = preg_match('/^[A-Za-z][A-Za-z0-9+.-]{1,31}:/', $body) === 1 ? '' : 'mailto:';
                $url = $prefix . ($table ? DestinationEncoding::destination($body, true) : $body);
                $html = '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                    . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';

                return $protect(rtrim((new HtmlToCarve())->convert($html), "\n"));
            }, $value) ?? $value;
        };

        $writeLiteralReference = function (string $value) use (&$protected, $preserveReferenceAutolinks): string {
            return implode('', array_map(function (string $part) use (&$protected, $preserveReferenceAutolinks): string {
                if (str_starts_with($part, '<') && str_ends_with($part, '>')) {
                    return $preserveReferenceAutolinks($part);
                }
                if (preg_match('/^\x00P(\d+)\x00$/', $part, $token) === 1 && preg_match('/^!?`/', $protected[(int)$token[1]] ?? '') === 1) {
                    return $part;
                }

                return preg_replace('/[!-\/:-@\[-`{-~]/', '\\\\$0', $this->decodeLinkTitle($part, $protected)) ?? $part;
            }, preg_split('/(\x00P\d+\x00|<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[a-zA-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>)/', $value, flags: PREG_SPLIT_DELIM_CAPTURE) ?: []));
        };

        $codeTokens = [];
        $protectCode = function (string $value, ?string $source = null) use ($protect, &$codeTokens): string {
            $token = $protect($value, $source);
            $codeTokens[$token] = $source ?? $value;

            return $token;
        };
        $codeWriter = null;
        $writeCodeSpan = function (string $span, int $offset = 0, ?string $raw = null) use ($protectCode, &$codeWriter): string {
            $fence = strspn($span, '`');
            if ($fence === 0) {
                return $protectCode($span, $raw);
            }
            $value = preg_replace('/\n[ \t]*/', ' ', substr($span, $fence, -$fence)) ?? '';
            if (str_starts_with($value, ' ') && str_ends_with($value, ' ') && trim($value, ' ') !== '') {
                $value = substr($value, 1, -1);
            }

            $codeWriter ??= $this->context->importWriter;

            return $protectCode($codeWriter->spellImportedCodeSpan($value), $span);
        };
        $line = $this->protectCodeSpans($line, $writeCodeSpan);
        $line = preg_replace_callback('/(?<!\\\\)\[(?!\^)([^\[\]\n]+)\](?=\[\^([^\[\]\n]+)\])/', function (array $match) use ($protect): string {
            $first = $this->normalizeReferenceLabel($match[1]);

            return in_array($match[2], $this->context->markdownFootnoteLabels, true) && !isset($this->context->definedReferenceLabels[$first])
                ? $protect('\\[', '[') . $match[1] . ']' : $match[0];
        }, $line) ?? $line;

        $validateCodeScalar = function (string $paren) use ($protected, $table): ?string {
            $inner = trim(substr($paren, 1, -1), " \t\n");
            if (str_starts_with($inner, '<')) {
                preg_match('/^<((?:[^>\\\\]|\\\\.)*)>/s', $inner, $pointy);
                $end = isset($pointy[0]) ? strlen($pointy[0]) - 1 : false;
                if ($end === false) {
                    return null;
                }
                $url = substr($inner, 1, $end - 1);
                if (preg_match('/(?<!\\\\)[\r\n]/', $url) === 1) {
                    return null;
                }
                $rest = substr($inner, $end + 1);
            } elseif (preg_match('/^((?:\x00P\d+\x00|[^\x00-\x20\x7f])+)([\s\S]*)$/', $inner, $matches)) {
                $url = $matches[1];
                $rest = $matches[2];
            } else {
                $url = $inner;
                $rest = '';
            }

            if (trim($rest) !== '') {
                if (preg_match('/^[ \t\n]+("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\((?:[^()\\\\]|\\\\.)*\))[ \t\n]*$/s', $rest, $title) !== 1) {
                    return null;
                }
                $decoded = $this->decodeLinkTitle(substr($title[1], 1, -1), $protected);
                $rest = ' ' . $this->context->importWriter->spellImportedTitle($decoded, $table);
            }

            return '(' . $this->writeMarkdownDestination($url, $protected, table: $table) . $rest . ')';
        };

        $destinationClosers = static function (string $subject): array {
            $opens = [];
            $closers = [];
            for ($at = 0, $length = strlen($subject); $at < $length; $at++) {
                if ($subject[$at] === '\\') {
                    $at++;
                } elseif ($subject[$at] === '[') {
                    $opens[] = $at;
                } elseif ($subject[$at] === ']' && $opens !== []) {
                    array_pop($opens);
                    $closers[$at] = true;
                }
            }

            return $closers;
        };
        $codeScalarTags = [];
        $protectCodeTags = function (string $value) use ($protect, &$codeScalarTags): string {
            $written = '';
            $opaqueEnds = [];
            for ($at = 0, $length = strlen($value); $at < $length;) {
                $end = $value[$at] === '<' ? $this->opaqueHtmlEnd($value, $at, $opaqueEnds) : null;
                if ($end === null && $value[$at] === '<') {
                    $end = $this->htmlTagAt($value, $at)['end'] ?? null;
                }
                if (
                    $end === null && $value[$at] === '&'
                    && preg_match('/\G&(?:#[xX][0-9a-fA-F]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/', $value, $entity, 0, $at) === 1
                    && strpbrk($this->decodeHtmlReferenceText($entity[0]), "\r\n") !== false
                ) {
                    $end = $at + strlen($entity[0]);
                }
                if ($end === null) {
                    $written .= $value[$at++];

                    continue;
                }
                $span = substr($value, $at, $end - $at);
                $token = $protect($span);
                $codeScalarTags[$token] = $span;
                $written .= $token;
                $at = $end;
            }

            return $written;
        };
        $scalarSubject = $line;
        $scalarDestinationClosers = $destinationClosers($line);
        $scalarOpaqueRanges = [];
        $scalarOpaqueEnds = [];
        for ($at = 0, $length = strlen($line); $at < $length;) {
            $end = $line[$at] === '<'
                ? $this->opaqueHtmlEnd($line, $at, $scalarOpaqueEnds) ?? ($this->htmlTagAt($line, $at)['end'] ?? null)
                : null;
            if ($end === null) {
                $at++;
            } else {
                $scalarOpaqueRanges[] = [$at, $end];
                $at = $end;
            }
        }
        $scalarOpaqueCursor = 0;
        $line = preg_replace_callback(
            '/(!?\[(?:[^\[\]\n]|\n(?![ \t]*\n)|\[(?:[^\[\]\n]|\n(?![ \t]*\n))*\])*\])(\((?:[^()\n]|\n(?![ \t]*\n)|\([^()\n]*\))*\))/',
            static function (array $match) use ($validateCodeScalar, $protectCodeTags, $protect, $scalarSubject, $scalarOpaqueRanges, $scalarDestinationClosers, &$scalarOpaqueCursor): string {
                $start = $match[0][1];
                while (isset($scalarOpaqueRanges[$scalarOpaqueCursor]) && $scalarOpaqueRanges[$scalarOpaqueCursor][1] <= $start) {
                    $scalarOpaqueCursor++;
                }
                if (isset($scalarOpaqueRanges[$scalarOpaqueCursor]) && $scalarOpaqueRanges[$scalarOpaqueCursor][0] <= $start) {
                    return $match[0][0];
                }
                $match = array_map(static fn (array $capture): string => $capture[0], $match);
                if (!isset($scalarDestinationClosers[$start + strlen($match[1]) - 1])) {
                    return $match[0];
                }
                $slashes = 0;
                for ($at = $start - 1; $at >= 0 && $scalarSubject[$at] === '\\'; $at--) {
                    $slashes++;
                }
                $closingSlashes = 0;
                for ($at = strlen($match[1]) - 2; $at >= 0 && $match[1][$at] === '\\'; $at--) {
                    $closingSlashes++;
                }
                if (($match[1][0] === '[' && $slashes % 2 !== 0) || $closingSlashes % 2 !== 0) {
                    return $match[0];
                }
                if ($validateCodeScalar($match[2]) === null) {
                    return preg_match('/<\/?code\b/i', $match[2]) === 1
                        ? $match[1] . $protect('\\(', '(') . substr($match[2], 1) : $match[0];
                }
                if (!str_starts_with($match[1], '!') && preg_match_all('/\[[^\[\]\n]*\](\([^()\n]*\))/', $match[1], $nestedDestinations) !== false) {
                    foreach ($nestedDestinations[1] as $destination) {
                        if ($validateCodeScalar($destination) !== null) {
                            return $match[0];
                        }
                    }
                }
                $label = str_starts_with($match[1], '!') && $slashes % 2 === 0 ? $protectCodeTags($match[1]) : $match[1];

                return $label . $protectCodeTags($match[2]);
            },
            $line,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $line;
        $line = preg_replace_callback(
            '/^(' . $definitionPrefix . ')(\[([^^\]][^\]]*)\]:\s*\S.*)$/',
            function (array $match) use ($protectCodeTags, $protected, $isReferenceDefinition): string {
                return $isReferenceDefinition && isset($this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($match[3], $protected))])
                    ? $match[1] . $protectCodeTags($match[2]) : $match[0];
            },
            $line,
        ) ?? $line;
        $codeSourceCursor = 0;
        $codeSourceOffset = 0;
        $codeSourceLines = 0;
        $codeSourceMatchesInput = null;
        $htmlCodeLineAt = function (int $offset) use (&$line, &$protectedSources, $sourceInput, $foldedSourceLines, &$codeSourceCursor, &$codeSourceOffset, &$codeSourceLines, &$codeSourceMatchesInput): ?int {
            $codeSourceMatchesInput ??= $foldedSourceLines === [] || $this->referenceSourceText($line, $protectedSources) === $sourceInput;
            while ($codeSourceCursor < $offset) {
                if ($line[$codeSourceCursor] === "\x00" && preg_match('/\G\x00P\d+\x00/', $line, $token, 0, $codeSourceCursor) === 1) {
                    $source = $this->referenceSourceText($token[0], $protectedSources);
                    $codeSourceOffset += strlen($source);
                    $codeSourceLines += substr_count($source, "\n");
                    $codeSourceCursor += strlen($token[0]);
                } else {
                    $codeSourceLines += (int)($line[$codeSourceCursor] === "\n");
                    $codeSourceOffset++;
                    $codeSourceCursor++;
                }
            }
            if (!$codeSourceMatchesInput) {
                return null;
            }
            if ($foldedSourceLines === []) {
                return $this->context->inlineRunSourceLine === null ? null : $this->context->inlineRunSourceLine + $codeSourceLines;
            }
            $low = 0;
            $high = count($foldedSourceLines);
            while ($low < $high) {
                $mid = ($low + $high) >> 1;
                if ($foldedSourceLines[$mid]['offset'] <= $codeSourceOffset) {
                    $low = $mid + 1;
                } else {
                    $high = $mid;
                }
            }

            return $foldedSourceLines[$low - 1]['line'] ?? null;
        };
        $standaloneCodeSource = trim($line);
        $unchangedHtmlCode = null;
        $activeCodeLinkOpeners = null;
        $nextCodeLinkOpener = 0;
        $writeHtmlCode = function (array $match, int $offset) use ($protect, $standaloneCodeSource, $terminal, $table, &$line, &$protected, &$unchangedHtmlCode, &$codeFallbacks, $htmlCodeLineAt, &$activeCodeLinkOpeners, &$nextCodeLinkOpener, &$protectedSources): ?string {
            $parts = [''];
            for ($at = 0, $length = strlen($match[1]); $at < $length;) {
                if ($match[1][$at] === '\\' && preg_match('/[!-\/:-@\[-`{-~]/', $match[1][$at + 1] ?? '') === 1) {
                    $parts[count($parts) - 1] .= substr($match[1], $at, 2);
                    $at += 2;
                } elseif (substr_compare($match[1], '<!---->', $at, 7) === 0) {
                    $parts[] = '';
                    $at += 7;
                } else {
                    $parts[count($parts) - 1] .= $match[1][$at++];
                }
            }
            $slashes = 0;
            for ($at = strlen($match[1]) - 1; $at >= 0 && $match[1][$at] === '\\'; $at--) {
                $slashes++;
            }
            if ($slashes % 2 !== 0) {
                return null;
            }
            if (str_contains($match[1], "\0")) {
                return null;
            }
            if (strpbrk($match[1], '[]') !== false) {
                if ($activeCodeLinkOpeners === null) {
                    $activeCodeLinkOpeners = [];
                    $opaqueEnds = [];
                    $activeCodeLinkOpeners = $this->markdownLinkScopes(
                        $line,
                        $protectedSources,
                        true,
                        function (int $start) use ($line, &$opaqueEnds): ?int {
                            return $this->opaqueHtmlEnd($line, $start, $opaqueEnds) ?? ($this->htmlTagAt($line, $start)['end'] ?? null);
                        },
                    )['active'];
                    sort($activeCodeLinkOpeners);
                }
                while (isset($activeCodeLinkOpeners[$nextCodeLinkOpener]) && $activeCodeLinkOpeners[$nextCodeLinkOpener] < $offset + 6) {
                    $nextCodeLinkOpener++;
                }
                if (($activeCodeLinkOpeners[$nextCodeLinkOpener] ?? PHP_INT_MAX) < $offset + strlen($match[0]) - 7) {
                    return null;
                }
            }
            if (strpbrk($match[1], '\\`*_~[]') !== false) {
                if (strpbrk($match[1], '*_~') !== false) {
                    $unchangedHtmlCode ??= $this->htmlCodeUnchangedByEmphasis($line, $protected);
                    if (!isset($unchangedHtmlCode[$offset])) {
                        return null;
                    }
                }
                $probe = (new CarveConverter())->parse('x ' . (clone $this)->convertInlineFormatting(implode('', $parts)) . "\n");
                $paragraphs = $probe->getChildren();
                if (count($paragraphs) !== 1 || !($paragraphs[0] instanceof Paragraph)) {
                    return null;
                }
                $plain = function (array $children) use (&$plain): bool {
                    foreach ($children as $child) {
                        if (in_array($child->getType(), ['text', 'escaped_text', 'smart_punctuation', 'non_breaking_space', 'soft_break'], true)) {
                            continue;
                        }
                        $label = $child instanceof Link ? $child->getReferenceLabel() : null;
                        if ($label === null || $child->getDestination() !== null && $child->getDestination() !== '' || isset($this->context->definedReferenceLabels[$this->normalizeReferenceLabel($label)]) || !$plain($child->getChildren())) {
                            return false;
                        }
                    }

                    return true;
                };
                if (!$plain($paragraphs[0]->getChildren())) {
                    return null;
                }
            }
            $decodedParts = array_map(function (string $part): string {
                $decoded = preg_replace_callback(
                    '/\\\\([!-\/:-@\[-`{-~])|&(?:#[xX][0-9a-fA-F]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/',
                    fn (array $piece): string => $piece[1] ?? $this->decodeHtmlReferenceText($piece[0]),
                    $part,
                ) ?? $part;

                return str_replace(["\r\n", "\r"], "\n", $decoded);
            }, $parts);
            foreach ($decodedParts as $part) {
                if (str_contains($part, '@') && BareLinks::ranges($part, null, 'email') !== []) {
                    return null;
                }
            }
            $value = implode('', $decodedParts);
            if ($this->context->nativeCodeSlot && $terminal && !$table && $standaloneCodeSource === $match[0]) {
                $document = new Document();
                $paragraph = new Paragraph();
                $paragraph->appendChild(new Code($value));
                $document->appendChild($paragraph);
                try {
                    $source = (new CarveRenderer())->render($document);
                    $read = (new CarveConverter())->parse($source)->getChildren();
                    $children = ($read[0] ?? null) instanceof Paragraph ? $read[0]->getChildren() : [];
                    if (count($read) === 1 && count($children) === 1 && $children[0] instanceof Code && $children[0]->getContent() === str_replace(["\r\n", "\r"], "\n", $value)) {
                        return $protect(rtrim($source, "\n"), $match[0]);
                    }
                } catch (SourceUnspellableException) {
                }
            }
            $before = substr($line, 0, $offset);
            // An empty code run is spellable only as an unclosed run ending its paragraph.
            if (
                $value === ''
                && $terminal
                && !$table
                && preg_match('/(?:^|[\p{L}\p{N} \t\n.,;:?)\'"-])$/u', $before) === 1
                && preg_match('/^[ \t]*$/', substr($line, $offset + strlen($match[0]))) === 1
            ) {
                return $protect('``', $match[0]);
            }
            // A line break in the value continues the paragraph when the caller keeps added lines.
            if (
                $this->context->breakableCodeSlot
                && str_contains($value, "\n")
                && !str_contains($value, "\r")
                && !$table
                && preg_match('/^[ \t]*#{1,6}(?:[ \t]|$)/', $line) !== 1
                && preg_match('/[!$\\\\`\x00{}\]]$/', $before) !== 1
            ) {
                $document = new Document();
                $paragraph = new Paragraph();
                $paragraph->appendChild(new Code($value));
                $document->appendChild($paragraph);
                try {
                    $written = rtrim((new CarveRenderer())->render($document), "\n");
                    $read = (new CarveConverter())->parse('x ' . $written . " y\n")->getChildren();
                    $children = ($read[0] ?? null) instanceof Paragraph ? $read[0]->getChildren() : [];
                    if (count($read) === 1 && count($children) === 3 && $children[1] instanceof Code && $children[1]->getContent() === $value) {
                        return $protect($written, $match[0]);
                    }
                } catch (SourceUnspellableException) {
                }
            }
            if ($value === '' || str_contains($value, "\n") || str_contains($value, "\r")) {
                $htmlCodeSourceLine = $htmlCodeLineAt($offset);
                $diagnostic = new MigrationDiagnostic(
                    'raw-code-fallback',
                    'Preserved an HTML code payload as raw HTML; targets and profiles that escape or omit raw HTML change its code structure and content',
                    'warning',
                    'degraded',
                    'exact',
                    $htmlCodeSourceLine === null ? null : 'line:' . $htmlCodeSourceLine,
                );

                $html = preg_replace_callback('/[ !-\/:-@\[-`{-~\t\n]/', static function (array $character): string {
                    if ($character[0] === "\n") {
                        return '<!---->&#10;<!---->';
                    }
                    $entity = '&#' . ord($character[0]) . ';';

                    return $character[0] === '@' ? $entity . '<!---->' : $entity;
                }, str_replace(["\r\n", "\r"], "\n", $value)) ?? $value;

                $token = $protect($this->verbatimHtmlInline('<code>' . $html . '</code>'), $match[0]);
                $codeFallbacks[$token] = $diagnostic;

                return $token;
            }
            $document = new Document();
            $paragraph = new Paragraph();
            $paragraph->appendChild(new Code($value));
            $document->appendChild($paragraph);

            return $protect(rtrim((new CarveRenderer())->render($document), "\n"), $match[0]);
        };
        $line = preg_replace_callback(
            '/\]\(([ \t]*)</',
            function (array $match) use ($line, $protect): string {
                $offset = $match[0][1];
                if (preg_match('/\G\]\([ \t]*<((?:[^<>\n\\\\]|\\\\[\s\S])*)>(?=(?:[ \t]*(?:\n[ \t]*)?\)|[ \t\n]+["\'\(]))/', $line, offset: $offset) === 1) {
                    return $match[0][0];
                }

                return ']' . $protect('\\(') . $match[1][0] . '<';
            },
            $line,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $line;
        $line = preg_replace_callback(
            '/(\]\([ \t]*|^ {0,3}\[(?:[^\]\n\\\\]|\\\\.)+\]:[ \t]*)<((?:[^<>\n\\\\]|\\\\[\s\S])+)>/',
            fn (array $match): string => $match[1] . $this->bareDestination($match[2]),
            $line,
        ) ?? $line;

        // A backslash is literal inside an autolink, including before its
        // closing bracket. Leave that bracket available to the autolink pass.
        $escaped = '';
        $htmlCodeDepth = 0;
        $opaqueEnds = [];
        for ($i = 0, $length = strlen($line); $i < $length;) {
            if ($htmlCodeDepth > 0 && $line[$i] === '&' && preg_match('/\G&(?:#[xX][0-9a-fA-F]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/', $line, $entity, 0, $i) === 1 && strpbrk($this->decodeHtmlReferenceText($entity[0]), "\r\n") !== false) {
                $start = $i;
                $i += strlen($entity[0]);
                if (
                    $this->decodeHtmlReferenceText($entity[0]) === "\r"
                    && preg_match('/\G&(?:#[xX][0-9a-fA-F]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/', $line, $following, 0, $i) === 1
                    && $this->decodeHtmlReferenceText($following[0]) === "\n"
                ) {
                    $i += strlen($following[0]);
                } elseif ($this->decodeHtmlReferenceText($entity[0]) === "\r" && ($line[$i] ?? '') === "\n") {
                    $i++;
                    $i += strspn($line, " \t", $i);
                }
                $escaped .= $protect($this->verbatimHtmlInline('<!---->&#10;<!---->'), substr($line, $start, $i - $start));

                continue;
            }
            $opaqueEnd = $line[$i] === '<' ? $this->opaqueHtmlEnd($line, $i, $opaqueEnds) : null;
            if ($opaqueEnd !== null) {
                $escaped .= substr($line, $i, $opaqueEnd - $i);
                $i = $opaqueEnd;

                continue;
            }
            if ($line[$i] === '<' && preg_match('/\G<code>((?:[^<\n]|<!---->)*)<\/code>/i', $line, $code, 0, $i) === 1) {
                $written = $writeHtmlCode($code, $i);
                if ($written !== null) {
                    $escaped .= $written;
                    $i += strlen($code[0]);

                    continue;
                }
            }
            $tag = $line[$i] === '<' ? $this->htmlTagAt($line, $i) : null;
            if ($tag !== null) {
                $sourceTag = substr($line, $i, $tag['end'] - $i);
                if ($tag['name'] === 'code') {
                    $htmlCodeDepth = max(0, $htmlCodeDepth + ($tag['closing'] ? -1 : 1));
                    if (!$tag['closing']) {
                        $htmlCodeSourceLine = $htmlCodeLineAt($i);
                        $diagnostic = new MigrationDiagnostic(
                            'raw-code-fallback',
                            'Preserved HTML code markup as raw HTML; targets and profiles that escape or omit raw HTML change its code structure and content',
                            'warning',
                            'degraded',
                            'exact',
                            $htmlCodeSourceLine === null ? null : 'line:' . $htmlCodeSourceLine,
                        );
                    }
                    $token = $protect($this->verbatimHtmlInline($sourceTag), $sourceTag);
                    if (!$tag['closing']) {
                        $codeFallbacks[$token] = $diagnostic;
                    }
                    $escaped .= $token;
                } elseif ($htmlCodeDepth > 0 && $tag['name'] === 'br') {
                    $escaped .= $protect($this->verbatimHtmlInline($sourceTag), $sourceTag);
                } else {
                    $escaped .= $sourceTag;
                }
                $i = $tag['end'];
            } elseif ($line[$i] === '\\' && preg_match('/[!-\/:-@\[-`{-~]/', $line[$i + 1] ?? '') === 1) {
                $pair = substr($line, $i, 2);
                $escaped .= $pair === '\\>' ? $protect('\\') . '>' : $protect($pair);
                $i += 2;
            } else {
                $escaped .= $line[$i] === '\\' && (($line[$i + 1] ?? '') === ' ' || ($terminal && $i + 1 === $length)) ? $protect('\\\\', $line[$i]) : $line[$i];
                $i++;
            }
        }
        $line = $escaped;
        if (
            !$isReferenceDefinition && str_contains($line, '/')
            && (preg_match('/<(?:em|i)\\b/i', $line) === 1
                || ($this->hasOddMarkdownDelimiterRun($line) && substr_count(Emphasis::convert($line, protectedSpans: $protected), '/') > substr_count($line, '/')))
        ) {
            $authored = $line;
            $guarded = '';
            $opaqueEnds = [];
            $bracketDepth = 0;
            for ($at = 0, $length = strlen($authored); $at < $length;) {
                if ($authored[$at] === "\0" && preg_match('/\\G\\x00P\\d+\\x00/', $authored, $token, 0, $at) === 1) {
                    $guarded .= $token[0];
                    $at += strlen($token[0]);

                    continue;
                }
                if ($this->context->convertMath && $authored[$at] === '$' && preg_match('/\G(?:\$\$[^$]+\$\$|\$(?:[^$\s][^$]*[^$\s]|\S)\$(?!\d))/', $authored, $math, 0, $at) === 1) {
                    $guarded .= $math[0];
                    $at += strlen($math[0]);

                    continue;
                }
                if ($authored[$at] === '\\') {
                    $guarded .= substr($authored, $at, 2);
                    $at += 2;

                    continue;
                }
                if ($authored[$at] === '<' && preg_match('/\G<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[A-Za-z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Za-z0-9.-]+)>/', $authored, $autolink, 0, $at) === 1) {
                    $guarded .= $autolink[0];
                    $at += strlen($autolink[0]);

                    continue;
                }
                $end = $authored[$at] === '<' ? $this->opaqueHtmlEnd($authored, $at, $opaqueEnds) ?? ($this->htmlTagAt($authored, $at)['end'] ?? null) : null;
                if ($end !== null) {
                    $guarded .= substr($authored, $at, $end - $at);
                    $at = $end;

                    continue;
                }
                if ($authored[$at] === '[') {
                    $bracketDepth++;
                } elseif ($authored[$at] === ']' && $bracketDepth > 0) {
                    $bracketDepth--;
                    $end = $this->markdownActivationTailEnd($authored, $at + 1);
                    if ($end !== null) {
                        $guarded .= substr($authored, $at, $end - $at + 1);
                        $at = $end + 1;

                        continue;
                    }
                }
                $guarded .= $authored[$at] === '/' ? $protect('\\/', '/') : $authored[$at];
                $at++;
            }
            $line = $guarded;
        }
        $hasProtectedEscapeBefore = static function (string $subject, int $offset) use (&$protected): bool {
            $before = substr($subject, max(0, $offset - 32), min(32, $offset));

            return preg_match('/\x00P(\d+)\x00$/', $before, $token) === 1
                && ($protected[(int)$token[1]] ?? '') === '\\';
        };
        $line = $this->rewriteEditorialHtml($line, $work, $protect);
        // Native inline tags with an exact Carve spelling. They are converted by
        // `$htmlRules` later in this method in BOTH modes, so neither the
        // HtmlToCarve import path nor the verbatim raw-HTML path may swallow
        // them first - carve-js converts `<b>`/`<em>`/`<sup>`/... to Carve even
        // when other raw HTML passes through verbatim.
        $nativeInline = 'code|mark|ins|del|s|sup|sub|strong|b|em|i|u';
        $line = $this->rewriteInlineHtml($line, $work, $protect, $nativeInline);

        $line = preg_replace_callback('/\[\^([^[\]\n]+)\]/', function (array $match) use ($protected, $protect): string {
            $label = $this->referenceSourceText($match[1], $protected);

            return isset($this->context->importedFootnoteLabels[$label]) ? '[^' . $protect($label) . ']' : $match[0];
        }, $line) ?? $line;
        $line = preg_replace_callback(
            '/&(?:#[xX][0-9A-Fa-f]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/',
            function (array $match) use ($protect): string {
                $decoded = $this->decodeHtmlReferenceText($match[0]);
                if ($decoded === $match[0]) {
                    return $match[0];
                }
                if ($decoded === "\r" || $decoded === "\n") {
                    $decoded = ' ';
                }

                return $protect($this->escapeDecodedCharacterReference($decoded), $match[0]);
            },
            $line,
        ) ?? $line;
        $line = $this->dropDecodedLeadingWhitespace($line, $protected, $bareContentStart, $contentPrefix, $protect, $headingHead);
        $line = $this->escapeMarkerRunADecodedSeparatorPromotes($line, $protected, $contentPrefix, $headingHead, $table);

        $closers = [];
        $line = $this->protectClosersOfLinksHoldingALink($line, $protected, $protect, $closers);

        // CommonMark links an empty destination; Carve reads `[t]()` as literal
        // text, so a link is written as its text and an image as its alt text
        // (markup-carve/carve#2069).
        $label = '(?<label>(?:[^[\]\n]|(?<nest>\[(?:[^[\]\n]|(?&nest))*\]))*)';
        $boundarySubject = null;
        $boundaryLines = [];
        $imageRanges = [];
        $autolinkRanges = [];
        $unwrap = function (array $match, string $title, string $subject, bool $hasTitle = false) use ($protected, $protectedSources, $protect, $sourceInput, $foldedSourceLines, $unwrapEmptyDestinations, &$boundarySubject, &$boundaryLines, &$imageRanges, &$autolinkRanges): string {
            if (!$unwrapEmptyDestinations) {
                return $match[0][0];
            }
            if ($boundarySubject !== $subject) {
                $boundarySubject = $subject;
                $boundaryLines = [];
                $events = [];
                preg_match_all('/\n|\x00P(\d+)\x00/', $subject, $events, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                $count = 0;
                $delta = 0;
                foreach ($events as $event) {
                    $source = $this->referenceSourceText($event[0][0], $protectedSources);
                    $count += substr_count($source, "\n");
                    $delta += strlen($source) - strlen($event[0][0]);
                    $boundaryLines[] = [$event[0][1] + strlen($event[0][0]) - 1, $count, $delta];
                }
                $autolinkRanges = [];
                $autolinks = [];
                preg_match_all('/<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[a-zA-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>/', $subject, $autolinks, PREG_OFFSET_CAPTURE);
                foreach ($autolinks[0] as [$text, $offset]) {
                    $autolinkRanges[] = [$offset, $offset + strlen($text)];
                }
                $imageRanges = [];
                $images = [];
                preg_match_all('/!\[(?<description>(?:[^\[\]\n]|(?<nested>\[(?:[^\[\]\n]|(?&nested))*\]))*)\](?:(?<destination>\((?:[^()\n]|\([^()\n]*\))*\))|\[(?<reference>[^\[\]\n]*)\])?/', $subject, $images, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($images as $image) {
                    $reference = isset($image['reference']) && $image['reference'][1] >= 0 && $image['reference'][0] !== '' ? $image['reference'][0] : $image['description'][0];
                    $key = $this->normalizeReferenceLabel($this->decodeLinkTitle($reference, $protected));
                    if (isset($image['destination']) && $image['destination'][1] >= 0 || isset($this->context->definedReferenceLabels[$key])) {
                        $imageRanges[] = [$image[0][1], $image['description'][1] + strlen($image['description'][0])];
                    }
                }
            }
            foreach ($autolinkRanges as [$start, $end]) {
                if ($match[0][1] > $start && $match[0][1] < $end) {
                    return $match[0][0];
                }
            }
            $low = 0;
            $high = count($boundaryLines);
            while ($low < $high) {
                $mid = ($low + $high) >> 1;
                if ($boundaryLines[$mid][0] < $match[0][1]) {
                    $low = $mid + 1;
                } else {
                    $high = $mid;
                }
            }
            $sourceLine = $this->context->inlineRunSourceLine === null ? null : $this->context->inlineRunSourceLine + ($boundaryLines[$low - 1][1] ?? 0);
            $sourceOffset = $match[0][1] + ($boundaryLines[$low - 1][2] ?? 0);
            if ($foldedSourceLines !== []) {
                $sourceMatch = $this->referenceSourceText($match[0][0], $protectedSources);
                $originalOffset = strpos($sourceInput, $sourceMatch);
                $restoredSubject = $this->referenceSourceText($subject, $protectedSources);
                if ($originalOffset !== false && substr_count($sourceInput, $sourceMatch) === 1 && substr_count($restoredSubject, $sourceMatch) === 1) {
                    $sourceOffset = $originalOffset;
                } elseif ($restoredSubject !== $sourceInput) {
                    // Earlier rewrites can make a repeated or changed label ambiguous.
                    // Keep the loss report without claiming an exact source line.
                    $sourceOffset = null;
                    $sourceLine = null;
                }
                if ($sourceOffset !== null) {
                    foreach ($foldedSourceLines as $segment) {
                        if ($segment['offset'] > $sourceOffset) {
                            break;
                        }
                        $sourceLine = $segment['line'];
                    }
                }
            }
            $insideImage = false;
            foreach ($imageRanges as [$start, $end]) {
                if ($match[0][1] > $start && $match[0][1] < $end) {
                    $insideImage = true;

                    break;
                }
            }

            return $this->unwrapEmptyDestination(
                $match['label'][0],
                $match[0][0][0] === '!',
                $title,
                substr($subject, 0, $match[0][1]),
                $protected,
                $protect,
                $hasTitle,
            );
        };
        if ($this->context->emptyDestinationLabels !== []) {
            $unwrapReference = function (array $match, string $reference, string $subject) use ($protected, $unwrap): string {
                $key = $this->normalizeReferenceLabel($this->decodeLinkTitle($reference, $protected));
                if (!isset($this->context->emptyDestinationLabels[$key])) {
                    return $match[0][0];
                }

                $definition = $this->context->emptyDestinationLabels[$key];

                return $unwrap($match, $definition['title'], $subject, $definition['hasTitle']);
            };
            $subject = $line;
            $line = preg_replace_callback(
                '/!?\[' . $label . '\](?:\[\]|\[(?<reference>[^[\]\n]+)\])/',
                fn (array $match): string => $unwrapReference($match, isset($match['reference']) && $match['reference'][1] >= 0 ? $match['reference'][0] : $match['label'][0], $subject),
                $line,
                flags: PREG_OFFSET_CAPTURE,
            ) ?? $line;
            $subject = $line;
            $line = preg_replace_callback(
                '/!?\[(?<label>[^[\]\n]+)\](?![[(:])/',
                fn (array $match): string => $unwrapReference($match, $match['label'][0], $subject),
                $line,
                flags: PREG_OFFSET_CAPTURE,
            ) ?? $line;
        }
        $subject = $line;
        $line = preg_replace_callback(
            '/!?\[' . $label . '\]\([ \t]*(?:<>(?:[ \t]+(?<title>"[^"\n]*"|\'[^\'\n]*\'|\([^()\n]*\)))?[ \t]*)?\)/',
            fn (array $match): string => $unwrap(
                $match,
                isset($match['title']) && $match['title'][1] >= 0 ? $this->decodeLinkTitle(substr($match['title'][0], 1, -1), $protected) : '',
                $subject,
                isset($match['title']) && $match['title'][1] >= 0,
            ),
            $line,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $line;

        $imageLabel = function (string $label) use (&$protected, &$protectedSources): string {
            $alt = $this->plainAltText(substr($label, 2, -1), $protected, $protectedSources);

            return '![' . (BracketScanner::rawRunCloses($alt) && AttributeParser::processEscapes($alt) === $alt
                ? $alt : str_replace(['\\', '[', ']', '`'], ['\\\\', '\\[', '\\]', '\\`'], $alt)) . ']';
        };

        $encodeDest = function (string $paren, bool $reference = false) use ($protected, &$protectedSources, $table): ?string {
            $sourceParen = $this->referenceSourceText($paren, $protectedSources);
            if (
                $reference ? ReferenceDefinition::read('[x]: ' . substr($sourceParen, 1, -1), 0) === null
                    : $this->markdownActivationTailEnd($sourceParen, 0) !== strlen($sourceParen) - 1
            ) {
                return null;
            }
            $paren = $sourceParen;
            $inner = trim(substr($paren, 1, -1), " \t\n");
            if (str_starts_with($inner, '<')) {
                preg_match('/^<((?:[^>\\\\]|\\\\.)*)>/s', $inner, $pointy);
                $end = isset($pointy[0]) ? strlen($pointy[0]) - 1 : false;
                if ($end === false) {
                    return null;
                }
                $url = substr($inner, 1, $end - 1);
                $pointyLine = 0;
                $url = preg_replace_callback('/\\\\\n[ \t]*/', function (array $match) use (&$pointyLine): string {
                    $pointyLine++;

                    return "\\\n" . (($this->context->pointyContinuationStrip[$pointyLine] ?? false) ? '' : substr($match[0], 2));
                }, $url) ?? $url;
                if (preg_match('/(?<!\\\\)[\r\n]/', $url) === 1) {
                    return null;
                }
                $rest = substr($inner, $end + 1);
            } elseif (preg_match('/^((?:\x00P\d+\x00|[^\x00-\x20\x7f])+)([\s\S]*)$/', $inner, $matches)) {
                $url = $matches[1];
                $rest = $matches[2];
            } else {
                $url = $inner;
                $rest = '';
            }

            if (trim($rest) !== '') {
                if (preg_match('/^[ \t\n]+("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\((?:[^()\\\\]|\\\\.)*\))[ \t\n]*$/s', $rest, $title) !== 1) {
                    return null;
                }
                $decoded = $this->decodeMarkdownScalar($this->referenceSourceText(substr($title[1], 1, -1), $protected));
                $rest = ' ' . $this->context->importWriter->spellImportedTitle($decoded, $table);
            }

            return '(' . $this->writeMarkdownDestination($url, originalSource: true, table: $table) . $rest . ')';
        };

        $protectDestination = function (string $alt, string $paren, ?string $source = null, bool $reference = false) use ($encodeDest, $protect): string {
            $encoded = $encodeDest($paren, $reference);

            $originalLabel = $source === null ? $alt : substr($source, 0, -strlen($paren));

            return $encoded === null ? $originalLabel . $protect('\\(', '(') . substr($paren, 1) : $protect($alt . $encoded, $source ?? $alt . $paren);
        };

        // A bare destination can stop at whitespace even with open parentheses.
        // The regular expression below cannot pair that shape without also
        // accepting invalid destinations, so read its closing offset directly.
        if (str_contains($line, '](')) {
            $source = $line;
            $opens = [];
            $pieces = [];
            $copied = 0;
            for ($at = 0, $length = strlen($source); $at < $length; $at++) {
                if ($source[$at] === "\0" && preg_match('/\G\x00P\d+\x00/', $source, $token, 0, $at) === 1) {
                    $at += strlen($token[0]) - 1;

                    continue;
                }
                if ($source[$at] === '\\') {
                    $at++;

                    continue;
                }
                if ($source[$at] === '[') {
                    $opens[] = $at;

                    continue;
                }
                if ($source[$at] !== ']' || $opens === []) {
                    continue;
                }
                $open = array_pop($opens);
                if (($source[$at + 1] ?? '') !== '(') {
                    continue;
                }
                $end = $this->markdownActivationTailEnd($source, $at + 1);
                if ($end === null || $open < $copied) {
                    continue;
                }
                $paren = substr($source, $at + 1, $end - $at);
                $image = $open > 0 && $source[$open - 1] === '!';
                if (!$image && strpbrk($paren, " \t\n") === false) {
                    continue;
                }
                $label = substr($source, $open, $at - $open + 1);
                $start = $image ? $open - 1 : $at + 1;
                if ($start < $copied) {
                    continue;
                }
                $pieces[] = substr($source, $copied, $start - $copied);
                $pieces[] = $image
                    ? $protectDestination($imageLabel('!' . $label), $paren, '!' . $label . $paren)
                    : $protectDestination('', $paren);
                $copied = $end + 1;
                $at = $end;
            }
            if ($pieces !== []) {
                $line = implode('', $pieces) . substr($source, $copied);
            }
        }

        $line = preg_replace_callback(
            '/(!?\[(?:[^\[\]\n]|\n(?![ \t]*\n)|\[(?:[^\[\]\n]|\n(?![ \t]*\n))*\])*\])(\([ \t\n]*(?:[^()\s]|\([^()\n]*\))+[ \t\n]+(?:"(?:[^"\n]|\n(?![ \t]*\n))*"|\'(?:[^\'\n]|\n(?![ \t]*\n))*\'|\((?:[^()\n]|\n(?![ \t]*\n))*\))[ \t\n]*\))/',
            fn (array $match): string => str_starts_with($match[1], '!')
                ? $protectDestination($imageLabel($match[1]), $match[2], $match[1] . $match[2])
                : $match[1] . $protectDestination('', $match[2]),
            $line,
        ) ?? $line;

        $activeDestinationClosers = $destinationClosers($line);
        $line = preg_replace_callback(
            '/(?<=\])(\([ \t\n]*(?:[^()\s]|\([^()\n]*\))+[ \t\n]+(?:"(?:[^"\n]|\n(?![ \t]*\n))*"|\'(?:[^\'\n]|\n(?![ \t]*\n))*\'|\((?:[^()\n]|\n(?![ \t]*\n))*\))[ \t\n]*\))/',
            static fn (array $match): string => isset($activeDestinationClosers[$match[1][1] - 1])
                ? $protectDestination('', $match[1][0]) : $match[0][0],
            $line,
            -1,
            $destinationCount,
            PREG_OFFSET_CAPTURE,
        ) ?? $line;

        $destination = '\((?:[ \t]*\n(?![ \t]*\n))?(?:[^()\n]|\([^()\n]*\)|\n(?=[ \t]*\)))*\)';
        $line = preg_replace_callback(
            '/(!\[(?:[^[\]]|\[[^\]]*\])*\])(' . $destination . ')/',
            fn (array $match): string => $protectDestination($imageLabel($match[1]), $match[2], $match[1] . $match[2]),
            $line,
        ) ?? $line;
        $activeDestinationClosers = $destinationClosers($line);
        $line = preg_replace_callback(
            '/(?<=\])(' . $destination . ')/',
            static fn (array $match): string => isset($activeDestinationClosers[$match[1][1] - 1])
                ? $protectDestination('', $match[1][0]) : $match[0][0],
            $line,
            -1,
            $destinationCount,
            PREG_OFFSET_CAPTURE,
        ) ?? $line;

        $line = preg_replace_callback(
            '/\[\^([^\[\]\n]+)\](?=\x00P(\d+)\x00)/',
            static fn (array $match): string => str_starts_with($protected[(int)$match[2]] ?? '', '(')
                ? '[' . $protect('\\^', '^') . $match[1] . ']' : $match[0],
            $line,
        ) ?? $line;

        $chainSubject = $line;
        $chainCursor = 0;
        $line = preg_replace_callback(
            '/(?<![\\\\\]])\[([^[\]\n^]+)\]\[([^[\]\n^]+)\]\[([^[\]\n^]+)\]/u',
            function (array $match) use ($chainSubject, &$chainCursor, $protect, $protected, $protectedSources, $protectDestination): string {
                $offset = $match[0][1];
                while ($chainCursor < $offset) {
                    if ($chainSubject[$chainCursor] === '<') {
                        $tag = $this->htmlTagAt($chainSubject, $chainCursor);
                        if ($tag !== null) {
                            $chainCursor = $tag['end'];

                            continue;
                        }
                        if (preg_match('/\G<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[^<>\s@]+@[^<>\s]+)>/', $chainSubject, $auto, 0, $chainCursor) === 1) {
                            $chainCursor += strlen($auto[0]);

                            continue;
                        }
                    }
                    $chainCursor++;
                }
                if ($chainCursor > $offset) {
                    return $match[0][0];
                }
                if (
                    preg_match('/\G\x00P(\d+)\x00/', $chainSubject, $tail, 0, $offset + strlen($match[0][0])) === 1
                    && str_starts_with($protected[(int)$tail[1]] ?? '', '(')
                ) {
                    return $match[0][0];
                }
                [$first, $second, $third] = [$match[1][0], $match[2][0], $match[3][0]];
                $middleTarget = $this->complexReferenceTarget($second, $protectedSources);
                $lastTarget = $this->complexReferenceTarget($third, $protectedSources);
                $middle = $this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($second)] ?? null;
                $last = $this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($third)] ?? null;
                $middleTail = $middleTarget !== null ? $protectDestination('', '(' . $middleTarget . ')', reference: true) : ($middle !== null ? $protect('[' . $middle . ']') : null);
                $lastTail = $lastTarget !== null ? $protectDestination('', '(' . $lastTarget . ')', reference: true) : ($last !== null ? $protect('[' . $last . ']') : null);
                if ($middleTail !== null) {
                    if (($chainSubject[$offset - 1] ?? '') === '!' || ($chainSubject[$offset + strlen($match[0][0])] ?? '') === '[') {
                        return $match[0][0];
                    }

                    return '[' . $first . ']' . $middleTail . '[' . $third . ']' . ($lastTail ?? '');
                }
                if ($lastTail !== null) {
                    return $protect('\\[' . $first . ']') . '[' . $second . ']' . $lastTail;
                }

                return $match[0][0];
            },
            $line,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $line;
        $line = preg_replace_callback(
            '/!\[((?:[^\[\]]|\[[^\]]*\])*)\](?:\[([^\]\n]*)\])?/',
            function (array $match) use ($protected, $protectedSources, $protect, $protectDestination, $imageLabel, $table): string {
                $label = ($match[2] ?? '') !== '' ? $match[2] : $match[1];
                $target = $this->complexReferenceTarget($label, $protectedSources);
                if ($target !== null) {
                    return $protectDestination($imageLabel('![' . $match[1] . ']'), '(' . $target . ')', reference: true);
                }
                $canonical = $this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($label, $protected))] ?? null;
                if ($table && $canonical !== null && str_contains($canonical, '|') && $this->normalizeReferenceLabel($label) !== $this->normalizeReferenceLabel($canonical)) {
                    $canonical = null;
                }
                if ($canonical === null) {
                    return $table && str_contains($match[0], '|') ? $protect(str_replace(['[', ']'], ['\\[', '\\]'], $match[0])) : $match[0];
                }
                if (strpbrk($canonical, '[]') !== false) {
                    return $match[0];
                }

                return $protect($imageLabel('![' . $match[1] . ']') . '[' . ($canonical === $match[1] && preg_match('/^[\p{L}\p{N} .-]*$/u', $canonical) === 1 ? '' : $canonical) . ']');
            },
            $line,
        ) ?? $line;
        $line = preg_replace_callback('/\[\^(?=[^[\]\n]*\[(?!\^))/', fn (): string => $protect('\\[') . '^', $line) ?? $line;

        $footnoteSource = $line;
        $footnoteCursor = 0;
        $line = preg_replace_callback('/\[\^([^[\]\n]+)\]/', function (array $match) use ($protected, $protect, $table, $footnoteSource, &$footnoteCursor): string {
            $offset = $match[0][1];
            while ($footnoteCursor < $offset) {
                if ($footnoteSource[$footnoteCursor] === '<') {
                    $tag = $this->htmlTagAt($footnoteSource, $footnoteCursor);
                    if ($tag !== null) {
                        $footnoteCursor = $tag['end'];

                        continue;
                    }
                    if (preg_match('/\G<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[^<>\s@]+@[^<>\s]+)>/', $footnoteSource, $auto, 0, $footnoteCursor) === 1) {
                        $footnoteCursor += strlen($auto[0]);

                        continue;
                    }
                }
                $footnoteCursor++;
            }
            if ($footnoteCursor > $offset) {
                return $match[0][0];
            }
            $label = $this->referenceSourceText($match[1][0], $protected);
            $renamed = $this->context->importedFootnoteLabels[$label] ?? null;

            return $renamed === null
                ? ($table && str_contains($label, '|') ? $protect(str_replace(['[', ']'], ['\\[', '\\]'], $match[0][0])) : $match[0][0])
                : '[^' . $renamed . ']';
        }, $line, flags: PREG_OFFSET_CAPTURE) ?? $line;

        if ($table) {
            $line = preg_replace_callback('/(?<!\\\\)\[([^[\]\n^]+)\]\[([^[\]\n]+)\]/', function (array $match) use ($protected, $protectedSources, $protect, $writeLiteralReference): string {
                if (!str_contains($match[1], '|') || $this->complexReferenceTarget($match[2], $protectedSources) !== null) {
                    return $match[0];
                }
                $canonical = $this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($match[2], $protected))] ?? null;
                if ($canonical !== null && (!str_contains($canonical, '|') || $this->normalizeReferenceLabel($canonical) === $this->normalizeReferenceLabel($match[2]))) {
                    return $match[0];
                }

                return $protect($writeLiteralReference($match[0]));
            }, $line) ?? $line;
            $line = preg_replace_callback(
                '/(?<!\\\\)(!?)\[([^[\]\n^][^[\]\n]*)\]\[\]/',
                function (array $match) use ($protected, $protectedSources, $protect, $writeLiteralReference): string {
                    if (!str_contains($match[2], '|') || $this->complexReferenceTarget($match[2], $protectedSources) !== null) {
                        return $match[0];
                    }
                    $canonical = $this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($match[2], $protected))] ?? null;
                    if ($canonical !== null && $this->normalizeReferenceLabel($canonical) === $this->normalizeReferenceLabel($match[2])) {
                        return $match[0];
                    }

                    return $protect($writeLiteralReference($match[0]));
                },
                $line,
            ) ?? $line;
        }
        $literalReferenceOpeners = [];
        if (str_contains($line, '][') && preg_match('/\]\[[^\[\]\n]+\]\x00P\d+\x00/', $line) === 1) {
            $inlineChainMath = [];
            if ($this->context->convertMath && str_contains($line, '$')) {
                preg_match_all('/\$\$[^$]+\$\$|\$(?:[^$\s][^$]*[^$\s]|\S)\$(?!\d)/', $line, $mathRanges, PREG_OFFSET_CAPTURE);
                foreach ($mathRanges[0] as [$body, $start]) {
                    $inlineChainMath[] = [$start, $start + strlen($body)];
                }
            }
            $resolvedReferenceTailOpeners = [];
            $referenceScopes = $this->markdownLinkScopes($line, $protected, scopeOnly: true)['active'];
            for ($at = 1, $count = count($referenceScopes); $at < $count; $at += 2) {
                $tailAt = $referenceScopes[$at] + 1;
                if (($line[$tailAt] ?? '') === '[') {
                    $resolvedReferenceTailOpeners[$tailAt] = true;
                }
            }
            $inlineChainUrls = $this->plainMarkdownUrlRanges($line, $protected, $protectedSources);
            $inlineChainMathAt = 0;
            $inlineChainUrlAt = 0;
            $line = preg_replace_callback(
                '/(?<!\\\\)\[((?!\^)[^\[\]\n]+)\]\[([^\[\]\n]+)\](?=\x00P(\d+)\x00)/',
                function (array $match) use ($inlineChainUrls, $inlineChainMath, &$inlineChainMathAt, &$inlineChainUrlAt, $resolvedReferenceTailOpeners, $protected, $protectedSources, $protect, &$literalReferenceOpeners): string {
                    $offset = $match[0][1];
                    if (isset($resolvedReferenceTailOpeners[$offset])) {
                        return $match[0][0];
                    }
                    while (isset($inlineChainMath[$inlineChainMathAt]) && $inlineChainMath[$inlineChainMathAt][1] <= $offset) {
                        $inlineChainMathAt++;
                    }
                    if (isset($inlineChainMath[$inlineChainMathAt]) && $inlineChainMath[$inlineChainMathAt][0] <= $offset) {
                        return $match[0][0];
                    }
                    while (isset($inlineChainUrls[$inlineChainUrlAt]) && $inlineChainUrls[$inlineChainUrlAt]['end'] <= $offset) {
                        $inlineChainUrlAt++;
                    }
                    if (isset($inlineChainUrls[$inlineChainUrlAt]) && $inlineChainUrls[$inlineChainUrlAt]['start'] <= $offset) {
                        return $match[0][0];
                    }
                    $tail = $protected[(int)$match[3][0]] ?? '';
                    $label = $match[2][0];
                    $defined = isset($this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($label, $protected))])
                        || $this->complexReferenceTarget($label, $protectedSources) !== null;
                    if (!str_starts_with($tail, '(') || $defined) {
                        return $match[0][0];
                    }

                    $opener = $protect('\\[', '[');
                    $literalReferenceOpeners[$opener] = true;

                    return $opener . $match[1][0] . $protect('\\]', ']') . '[' . $label . ']';
                },
                $line,
                flags: PREG_OFFSET_CAPTURE,
            ) ?? $line;
        }
        $linkClosers = [];
        $openLabels = [];
        for ($at = 0, $length = strlen($line); $at < $length; $at++) {
            if ($this->context->convertMath && $line[$at] === '$' && preg_match('/\G(?:\$\$[^$]+\$\$|\$(?:[^$\s][^$]*[^$\s]|\S)\$(?!\d))/', $line, $math, 0, $at) === 1) {
                $at += strlen($math[0]) - 1;

                continue;
            }
            if (($line[$at] === '<' || ($line[$at] === 'h' && $openLabels === [])) && preg_match('~\G(?:<[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*>|https?://[^\s<>`\x00]+)~', $line, $url, 0, $at) === 1) {
                $at += strlen($url[0]) - 1;

                continue;
            }
            if ($line[$at] === '[') {
                $openLabels[] = $at;
            } elseif ($line[$at] === ']' && $openLabels !== []) {
                $open = array_pop($openLabels);
                if (($line[$open + 1] ?? '') !== '^') {
                    $linkClosers[$at] = $open;
                }
            }
        }
        $referenceClosers = [];
        $subject = $line;
        $line = preg_replace_callback(
            '/(?<=\])\[([^\]]*)\]/',
            function (array $match) use ($subject, $protected, $protectedSources, $protect, $protectDestination, $linkClosers, &$referenceClosers, &$literalReferenceOpeners, $table, $writeLiteralReference): string {
                $reference = $match[1][0];
                if (str_starts_with($reference, '^')) {
                    return $protect($match[0][0]);
                }
                $offset = $match[0][1];
                if (!isset($linkClosers[$offset - 1]) || isset($referenceClosers[$offset - 1])) {
                    return $match[0][0];
                }
                $referenceClosers[$offset + strlen($match[0][0]) - 1] = true;
                $labelStart = $reference === '' ? strrpos(substr($subject, 0, max(0, $offset - 1)), '[') : false;
                $preceding = $labelStart === false ? '' : substr($subject, $labelStart + 1, $offset - $labelStart - 2);
                $label = $reference !== '' ? $reference : (strpbrk($preceding, "]\n") === false ? $preceding : null);
                $target = $label !== null ? $this->complexReferenceTarget($label, $protectedSources) : null;
                if ($target !== null) {
                    return $protectDestination('', '(' . $target . ')', reference: true);
                }
                $canonical = $label !== null && ($reference === '' || strpbrk($label, "\\&\0") === false)
                    ? ($this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($label, $protected))] ?? null)
                    : null;
                if ($table && $canonical !== null && str_contains($canonical, '|') && $this->normalizeReferenceLabel($label) !== $this->normalizeReferenceLabel($canonical)) {
                    $canonical = null;
                }
                $firstStart = $linkClosers[$offset - 1];
                $firstLabel = substr($subject, $firstStart + 1, $offset - $firstStart - 2);
                $literal = $this->decodeLinkTitle($reference, $protected);
                $raw = $reference;
                $protectedCount = count($protected);
                for ($pass = 0; $pass < $protectedCount; $pass++) {
                    $restored = preg_replace_callback('/\x00P(\d+)\x00/', static fn (array $part): string => $protected[(int)$part[1]] ?? $part[0], $raw) ?? $raw;
                    if ($restored === $raw) {
                        break;
                    }
                    $raw = $restored;
                }
                if (
                    $canonical === null && preg_match('/\\\\[!*]/', $raw) === 1 && $literal !== $reference
                    && $this->normalizeReferenceLabel($this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($literal)] ?? '') !== $this->normalizeReferenceLabel($raw)
                ) {
                    $firstStart = $linkClosers[$offset - 1];
                    $firstLabel = substr($subject, $firstStart + 1, $offset - $firstStart - 2);
                    if (!isset($this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($firstLabel, $protected))])) {
                        return $protect('\\[' . $writeLiteralReference($reference) . '\\]');
                    }
                }
                if ($canonical === null && str_contains($raw, '|')) {
                    return $protect('\\[' . $writeLiteralReference($reference) . '\\]');
                }
                $definedReference = isset($this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($literal)])
                    || isset($this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->referenceSourceText($reference, $protectedSources))]);
                $end = $offset + strlen($match[0][0]);
                $inlineTail = preg_match('/\G\x00P(\d+)\x00/', $subject, $next, 0, $end) === 1
                    && str_starts_with($protected[(int)$next[1]] ?? '', '(');
                if ($canonical === null && !$definedReference && !$inlineTail && preg_match('/[\x00*_`\\\\<&~"\'=^]/', $firstLabel . $reference) === 1) {
                    $opener = $protect('\\[', '[');
                    $literalReferenceOpeners[$opener] = true;

                    return $opener . $reference . $protect('\\]', ']');
                }
                $collapsed = $reference === '' && $label === $canonical && preg_match('/^[\p{L}\p{N} .-]*$/u', $label ?? '') === 1
                    && preg_match('/(?:\.{3,}|--)/', $label ?? '') !== 1;

                return $protect($canonical === null || strpbrk($canonical, '[]') !== false || $collapsed ? $match[0][0] : '[' . $canonical . ']');
            },
            $line,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $line;
        $line = preg_replace_callback(
            '/<([A-Za-z][A-Za-z0-9+.-]*):[^<>\s]*>/',
            function (array $match) use ($protect, $protected, $table): string {
                // CommonMark requires 2–32 scheme characters. Keep a Carve-only
                // autolink literal while the surrounding Markdown still parses.
                if (strlen($match[1]) < 2 || strlen($match[1]) > 32) {
                    return $protect('\\<') . substr($match[0], 1);
                }
                $body = substr($match[0], 1, -1);
                do {
                    $previous = $body;
                    $body = preg_replace_callback('/\x00P(\d+)\x00/', static fn (array $part): string => $protected[(int)$part[1]], $body) ?? $body;
                } while ($body !== $previous);
                if (!str_contains($body, '\\') && strpbrk($body, '`|{}^"') === false) {
                    return $protect($match[0]);
                }
                $url = $table ? DestinationEncoding::destination($body, true) : $body;
                $html = '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                    . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';

                return $protect(rtrim((new HtmlToCarve())->convert($html), "\n"));
            },
            $line,
        ) ?? $line;
        $line = preg_replace_callback('/<[^>\s@]+@[^>\s]+>/', function (array $match) use ($protect, $table): string {
            if (str_contains($match[0], "\0") || str_contains($match[0], '\\')) {
                return $match[0];
            }
            if ((!$table || !str_contains($match[0], '|')) && !str_contains($match[0], '`') && !str_contains($match[0], '/')) {
                return $protect($match[0]);
            }
            $body = substr($match[0], 1, -1);
            $url = 'mailto:' . ($table ? DestinationEncoding::destination($body, true) : $body);
            $html = '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';

            return $protect(rtrim((new HtmlToCarve())->convert($html), "\n"));
        }, $line) ?? $line;
        // A definition kept where it stands is a definition, not link text -
        // on a nested item's marker line too, which is where fmt writes it.
        $line = preg_replace_callback(
            '/^(' . $definitionPrefix . ')(\[([^^\]][^\]]*)\]:\s*\S.*)$/',
            function (array $match) use ($protect, $protected, $protectedSources, $encodeDest, $isReferenceDefinition): string {
                if (!$isReferenceDefinition || !isset($this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($match[3], $protected))])) {
                    return $match[0];
                }
                $source = $this->referenceSourceText($match[2], $protectedSources);
                if (preg_match('/^(\[(?:[^\]\\\\]|\\\\.)*\]:[ \t]*)(.*)$/s', $source, $parts) !== 1) {
                    return $match[1] . $protect($match[2]);
                }
                $target = $encodeDest('(' . $parts[2] . ')', reference: true);

                return $target === null ? $match[1] . $protect($match[2]) : $match[1] . $protect($parts[1] . substr($target, 1, -1), $source);
            },
            $line,
        ) ?? $line;

        // Carve has no shortcut reference, so a defined `[r]` is written in the
        // full form, collapsed only where Carve's exact label match still holds.
        if ($this->context->referenceDefinitionLabels !== [] || $this->context->complexReferenceTargets !== []) {
            $referenceUrls = $this->plainMarkdownUrlRanges($line, $protected, $protectedSources);
            $referenceUrlAt = 0;
            $subject = $line;
            // A literal closer written by protectClosersOfLinksHoldingALink() is text, not a reference tail.
            $line = preg_replace_callback(
                '/(!?)\[([^[\]\n^][^[\]\n]*)\]/',
                function (array $match) use ($subject, $protected, $protectedSources, $protect, $protectDestination, $closers, $literalReferenceOpeners, $table, $isReferenceDefinition, &$referenceUrls, &$referenceUrlAt): string {
                    $offset = $match[0][1];
                    while (isset($referenceUrls[$referenceUrlAt]) && $referenceUrls[$referenceUrlAt]['end'] <= $offset) {
                        $referenceUrlAt++;
                    }
                    $url = $referenceUrls[$referenceUrlAt] ?? null;
                    if ($url !== null && $offset >= $url['start'] && $offset < $url['end']) {
                        return $match[0][0];
                    }
                    $label = $match[2][0];
                    $end = $match[0][1] + strlen($match[0][0]);
                    if (preg_match('/\G\x00P(\d+)\x00/', $subject, $next, 0, $end) === 1 && !isset($closers[(int)$next[1]])) {
                        $tail = $protectedSources[(int)$next[1]] ?? '';
                        if (isset($literalReferenceOpeners[$next[0]]) || str_starts_with($tail, '(') && ($protected[(int)$next[1]] ?? '') !== '\\(' || str_starts_with($tail, '{title=') || preg_match('/^\[[^\]]*\]$/', $tail) === 1) {
                            return $match[0][0];
                        }
                    }
                    if ($isReferenceDefinition && ($subject[$end] ?? '') === ':' && preg_match('/^[ \t>]*' . self::DEFINITION_MARKER . '$/', substr($subject, 0, $match[0][1])) === 1) {
                        return $match[0][0];
                    }
                    // A task checkbox, whose label the reader never resolves.
                    if (($this->cmarkReadsTaskCheckbox)($subject, $match[0][1])) {
                        return $match[0][0];
                    }
                    $target = $this->complexReferenceTarget($label, $protectedSources);
                    $rawLabel = $this->referenceSourceText($label, $protectedSources);
                    $definition = preg_match('/&(?:#[xX][0-9A-Fa-f]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/', $rawLabel) === 1
                        && !isset($this->context->authoredReferenceLabelKeys[$this->normalizeReferenceLabel($rawLabel)])
                        ? null
                        : ($this->context->referenceDefinitionLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($label, $protectedSources))] ?? null);
                    if ($url !== null && $offset < $url['start'] && ($target !== null || $definition !== null)) {
                        for ($at = $referenceUrlAt; isset($referenceUrls[$at]) && $referenceUrls[$at]['start'] < $end; $at++) {
                            $referenceUrls[$at]['end'] = min($referenceUrls[$at]['end'], $end);
                        }
                    }
                    if ($target !== null) {
                        return $match[1][0] . '[' . $label . ']' . $protectDestination('', '(' . $target . ')', reference: true);
                    }
                    if ($table && str_contains($label, '|') && ($definition === null || $this->normalizeReferenceLabel($definition) !== $this->normalizeReferenceLabel($label))) {
                        return $protect(str_replace(['[', ']'], ['\\[', '\\]'], $match[0][0]));
                    }
                    if ($definition === null) {
                        return $match[0][0];
                    }

                    return $match[1][0] . '[' . $label . ']'
                        . ($definition === $label && preg_match('/^[\p{L}\p{N} .-]*$/u', $label) === 1 && preg_match('/(?:\.{3,}|--)/', $label) !== 1 ? '[]' : $protect('[' . $definition . ']'));
                },
                $line,
                flags: PREG_OFFSET_CAPTURE,
            ) ?? $line;
        }

        if (str_contains($line, ':') || str_contains($line, 'www.')) {
            $labelRanges = Emphasis::linkLabelRanges($line, $protected);
            $labelAt = $urlCursor = 0;
            $protectedUrls = '';
            $reparseCode = false;
            foreach (
                BareLinks::ranges($line, function (string $token) use ($protected, $protectedSources): string|array|null {
                    $index = (int)substr($token, 2, -1);

                    return $this->markdownUrlTokenSource($index, $protected, $protectedSources);
                }, 'url') as $candidate
            ) {
                $offset = $candidate['start'];
                while (isset($labelRanges[$labelAt]) && $labelRanges[$labelAt]['end'] <= $offset) {
                    $labelAt++;
                }
                $label = $labelRanges[$labelAt] ?? null;
                if ($label !== null && $offset >= $label['start'] && $offset < $label['end']) {
                    continue;
                }
                $body = $candidate['body'] ?? substr($line, $offset, $candidate['end'] - $offset);
                $href = $candidate['prefix'] . $body;
                $document = new Document();
                $paragraph = new Paragraph();
                $link = new Link($href);
                foreach (preg_split('/([\[\]])/', $body, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
                    if ($piece !== '') {
                        $link->appendChild(new Text($piece));
                    }
                }
                $paragraph->appendChild($link);
                $document->appendChild($paragraph);
                $written = rtrim((new CarveRenderer())->render($document), "\n");
                if ($table) {
                    $written = ($this->escapeTablePipes)($written);
                }
                preg_match_all('/\x00P\d+\x00/', substr($line, $offset, $candidate['end'] - $offset), $consumedTokens);
                foreach ($consumedTokens[0] as $token) {
                    if (isset($codeTokens[$token])) {
                        $reparseCode = true;
                    }
                }
                $protectedUrls .= $guardBareLinkPrefix(substr($line, $urlCursor, $offset - $urlCursor)) . $protect($written) . ($candidate['tail'] ?? '');
                $urlCursor = $candidate['end'];
            }
            $line = $protectedUrls . substr($line, $urlCursor);
            if ($reparseCode) {
                $line = preg_replace_callback('/\x00P\d+\x00/', static fn (array $token): string => $codeTokens[$token[0]] ?? $token[0], $line) ?? $line;
                $line = $this->protectCodeSpans($line, $writeCodeSpan);
            }
        }

        if ($this->context->convertMath) {
            $line = preg_replace_callback('/\$\$([^$]+)\$\$/', fn (array $match): string => $protect('$$`' . $match[1] . '`'), $line) ?? $line;
            $line = preg_replace_callback('/\$([^$\s][^$]*[^$\s]|\S)\$(?!\d)/', function (array $match) use ($protect): string {
                return preg_match('/^[\d.,]+$/', $match[1])
                    ? $match[0]
                    : $protect('$`' . $match[1] . '`');
            }, $line) ?? $line;
        }

        if (!$this->context->convertAttributes) {
            $line = $this->escapeAttributeBlockOpener($line);
        }

        $line = preg_replace('/ {2,}\n/', "\\\n", $line) ?? $line;

        $nativeInlineTags = [];
        $line = preg_replace_callback('/<\/?(?:' . $nativeInline . ')>/i', static function (array $match) use ($protect, &$nativeInlineTags): string {
            $token = $protect($match[0]);
            $nativeInlineTags[$token] = $match[0];

            return $token;
        }, $line) ?? $line;

        $resolved = $this->context->resolvedCrossrefs ?? [];
        $line = preg_replace_callback(ImportedCrossrefs::RUN, static fn (array $match): string => isset($resolved[$match[1]]) ? $match[0] : $protect($match[0]), $line) ?? $line;
        $line = $this->escapePlainCarveInlineSyntax($line, self::HANDLED_MARKDOWN);
        $line = $this->restoreNumericReferenceHashes($line);
        $line = str_replace('&#', '&\\#', $line);

        $line = $this->escapeLinkLabelSlashes($line, $protected);
        $line = Emphasis::convert($line, function (): void {
            $this->context->flattenedEmphasis = true;
        }, null, $protected, strikethrough: true);

        $line = strtr($line, $nativeInlineTags);

        // ==highlight== -> =highlight=. Carve highlight is a single `=`; a
        // doubled `==x==` is literal text in Carve, so a Markdown highlight
        // left unchanged would silently mis-render. Off by default: `==x==` is
        // literal in CommonMark and GFM alike, so converting it unconditionally
        // invented a highlight the source never had.
        $replaceNativeInline = static function (string $pattern, string $replacement, string $subject) use ($protect): string {
            [$open, $close] = explode('$1', $replacement);

            return preg_replace_callback($pattern, static fn (array $match): string => $protect($open) . $match[1] . $protect($close), $subject) ?? $subject;
        };
        if ($this->context->convertHighlight) {
            $line = $replaceNativeInline('/==(?!\s)([^=]+?)(?<!\s)==/', '=$1=', $line);
        }

        // Highlight/super/subscript use the forced brace forms: an HTML tag can
        // sit intraword (e.g. H<sub>2</sub>O), where a bare ,2, / ^2^ / =2= is
        // literal in Carve; the {,x,} / {^x^} / {=x=} forms render anywhere.
        // Highlight is the one of these with a bare Carve form (`=x=`, like
        // emphasis). In verbatim mode carve-js emits it bare, bracing only when a
        // word character sits directly against either delimiter - where a bare
        // `=` would be literal (`a=x=b`). The opt-in HtmlToCarve mode keeps the
        // always-braced form. The others below have no bare form and are always
        // braced (sup/sub/ins) or always bare (del/s).
        if (!$this->context->convertRawHtml) {
            $line = preg_replace_callback(
                '/(?:<mark>[^<]+<\/mark>){2,}/i',
                static fn (array $run): string => preg_replace_callback(
                    '/<mark>([^<]+)<\/mark>/i',
                    static fn (array $mark): string => $protect('{=') . $mark[1] . $protect('=}'),
                    $run[0],
                ) ?? $run[0],
                $line,
            ) ?? $line;
            $line = $replaceNativeInline('/(?<=\w)<mark>([^<]+)<\/mark>/i', '{=$1=}', $line);
            $prefixSubject = $line;
            $prefixCursor = 0;
            $prefixTagEnd = 0;
            $line = preg_replace_callback(
                '/([!<>=])<mark>([^<]+)<\/mark>/i',
                function (array $match) use ($protect, $prefixSubject, $hasProtectedEscapeBefore, &$prefixCursor, &$prefixTagEnd): string {
                    $offset = $match[0][1];
                    while ($prefixCursor <= $offset) {
                        $tag = $prefixSubject[$prefixCursor] === '<' ? $this->htmlTagAt($prefixSubject, $prefixCursor) : null;
                        if ($tag !== null) {
                            $prefixTagEnd = $tag['end'];
                            $prefixCursor = $prefixTagEnd;
                        } else {
                            $prefixCursor++;
                        }
                    }
                    if ($prefixTagEnd > $offset) {
                        return $match[0][0];
                    }

                    if ($hasProtectedEscapeBefore($prefixSubject, $offset)) {
                        return $match[1][0] . $protect('{=') . $match[2][0] . $protect('=}');
                    }

                    return $protect('\\' . $match[1][0] . '{=') . $match[2][0] . $protect('=}');
                },
                $line,
                flags: PREG_OFFSET_CAPTURE,
            ) ?? $line;
            $line = $replaceNativeInline('/<mark>([^<]+)<\/mark>(?=\w)/i', '{=$1=}', $line);
            $line = $replaceNativeInline('/<mark>([^<]+)<\/mark>/i', '=$1=', $line);
            // §8c writes `underline` as `<u>`, which this table did not read back
            // at all (carve#2838). The bare `_x_` form is preferred, braced where
            // a bare underline would be literal: against a word character, or
            // around a whitespace-padded body.
            $line = $replaceNativeInline('/(?<=[A-Za-z0-9])<u>([^<]+)<\/u>/i', '{_$1_}', $line);
            $line = $replaceNativeInline('/<u>([^<]+)<\/u>(?=[A-Za-z0-9])/i', '{_$1_}', $line);
            $line = $replaceNativeInline('/<u>(\s[^<]*|[^<]*\s)<\/u>/i', '{_$1_}', $line);
            $line = $replaceNativeInline('/<u>([^<]+)<\/u>/i', '_$1_', $line);
        }
        $htmlRules = [
            '/<mark>([^<]+)<\/mark>/i' => '{=$1=}',
            '/<u>([^<]+)<\/u>/i' => '{_$1_}',
            '/<ins>([^<]+)<\/ins>/i' => '{+$1+}',
            '/<del>([^<]+)<\/del>/i' => '~$1~',
            '/<s>([^<]+)<\/s>/i' => '~$1~',
            '/<sup>([^<]+)<\/sup>/i' => '{^$1^}',
            '/<sub>([^<]+)<\/sub>/i' => '{,$1,}',
        ];
        foreach ($htmlRules as $pattern => $replacement) {
            $line = $replaceNativeInline($pattern, $replacement, $line);
        }
        foreach (['strong|b', 'em|i'] as $tagPattern) {
            $htmlSource = $line;
            $line = preg_replace_callback(
                '/<(' . $tagPattern . ')>([^<]+)<\/\1>/i',
                static function (array $match) use ($htmlSource, $protected): string {
                    $marker = in_array(strtolower($match[1][0]), ['em', 'i'], true) ? '/' : '*';
                    $body = $match[2][0];
                    $offset = $match[0][1];
                    $before = $offset === 0 ? '' : $htmlSource[$offset - 1];
                    if ($before === "\x00") {
                        $start = $offset - 2;
                        while ($start >= 0 && $htmlSource[$start] !== "\x00") {
                            $start--;
                        }
                        if ($start >= 0 && preg_match('/^\x00P(\d+)\x00$/', substr($htmlSource, $start, $offset - $start), $token)) {
                            $index = (int)$token[1];
                            $value = $protected[$index] ?? '';
                            while (preg_match('/\x00P(\d+)\x00$/', $value, $nested) === 1 && (int)$nested[1] < $index) {
                                $index = (int)$nested[1];
                                $value = $protected[$index] ?? '';
                            }
                            $before = $value !== '' ? substr($value, -1) : $before;
                        }
                    }
                    $after = $htmlSource[$offset + strlen($match[0][0])] ?? '';
                    $forced = preg_match('/[A-Za-z0-9]/', $before . $after) === 1 || preg_match('/^\s|\s$/u', $body) === 1
                        || preg_match('/^[*\/]|[*\/]$/', $body) === 1 || ($marker === '/' && $before === '/');
                    $written = $marker . $body . $marker;

                    return $forced ? '{' . $written . '}' : $written;
                },
                $line,
                flags: PREG_OFFSET_CAPTURE,
            ) ?? $line;
        }

        // A bare native tag still standing is unpaired - the paired ones
        // converted above. carve-js keeps an unpaired `<b>` or `</b>` as an
        // inline raw span rather than letting it fall through to literal
        // text that renders escaped.
        $line = preg_replace_callback(
            '/<\/?(?:' . $nativeInline . ')>/i',
            fn (array $match): string => $protect($this->verbatimHtmlInline($match[0]), $match[0]),
            $line,
        ) ?? $line;

        $line = $this->escapeFinalInlineText($line, $work, $protect, $hasProtectedEscapeBefore, $table);

        return $this->restoreProtectedInlineOutput($line, $work, $table);
    }

    /**
     * Rewrite editorial and abbreviation tags before general HTML handling.
     *
     * @param string $line
     * @param \MarkupCarve\Carve\Converter\Markdown\InlineWork $work
     * @param \Closure(string, ?string=): string $protect
     */
    private function rewriteEditorialHtml(string $line, InlineWork $work, Closure $protect): string
    {
        if (!str_contains($line, '<')) {
            return $line;
        }
        $protectedSources = $work->protectedSources;
        // PART 11 §8c writes two constructs with no Markdown delimiter spelling
        // as an ATTRIBUTE-BEARING inline tag: an abbreviation as
        // `<abbr title="...">` and an editorial comment as
        // `<span class="critic-comment">`. Later native-tag rules exclude
        // attribute-bearing tags because migrating one would drop its
        // attributes - the Carve construct carries them here, so nothing drops
        // and the exclusion does not apply (carve#2838). The match is on the
        // shape §8c emits and refuses anything wider, leaving the general
        // inline HTML phase to write the raw span.
        if (!$this->context->convertRawHtml) {
            $line = preg_replace_callback(
                '/<span[ \t]+class[ \t]*=[ \t]*"critic-comment"[ \t]*>([^<]*)<\/span[ \t]*>/i',
                function (array $match) use ($protect, $protectedSources): string {
                    // CARVE-P3-016 keeps comment content literal.
                    $rawHtml = false;
                    $value = preg_replace_callback(
                        '~\x00P(\d+)\x00|\\\\([!-/:-@\[-\x60{-\x7e])|&(?:#[0-9]{1,8}|#[xX][0-9a-fA-F]{1,8}|[A-Za-z][A-Za-z0-9]{0,31});~',
                        function (array $part) use ($protectedSources, &$rawHtml): string {
                            if (($part[1] ?? '') !== '') {
                                $source = $this->referenceSourceText($part[0], $protectedSources);
                                if (preg_match('~^\\\\[!-/:-@\[-\x60{-\x7e]$~', $source) === 1) {
                                    return substr($source, 1);
                                }
                                $rawHtml = $rawHtml || str_contains($source, '<');

                                return $source;
                            }

                            return ($part[2] ?? '') !== '' ? $part[2] : html_entity_decode($part[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        },
                        $match[1],
                    ) ?? $match[1];

                    return $rawHtml || preg_match('/[{}\\\\\n]/', $value) === 1 ? $match[0] : $protect('{#' . $value . '#}', $match[0]);
                },
                $line,
            ) ?? $line;
            // §8c spells a deletion `<del class="critic-delete">`, so it no
            // longer collides with the bare `<del>` a `strike` falls back to
            // (carve#2845). The substitution shape is read FIRST: its two
            // elements are one construct, and the deletion rule alone would
            // leave a strike beside an unrelated insertion.
            $delete = '<del[ \t]+class[ \t]*=[ \t]*"critic-delete"[ \t]*>([^<]*)<\/del[ \t]*>';
            $line = preg_replace_callback(
                '/' . $delete . '<ins[ \t]*>([^<]*)<\/ins[ \t]*>/i',
                function (array $match) use ($protect): string {
                    if ($this->breaksOutOfACriticBody($match[1]) || $this->breaksOutOfACriticBody($match[2])) {
                        return $match[0];
                    }

                    return $protect('{~') . $match[1] . $protect('~>') . $match[2] . $protect('~}');
                },
                $line,
            ) ?? $line;
            $line = preg_replace_callback(
                '/' . $delete . '/i',
                function (array $match) use ($protect): string {
                    if ($this->breaksOutOfACriticBody($match[1])) {
                        return $match[0];
                    }

                    return $protect('{-') . $match[1] . $protect('-}');
                },
                $line,
            ) ?? $line;
            $line = preg_replace_callback(
                '/<abbr[ \t]+title[ \t]*=[ \t]*"([^"]*)"[ \t]*>([^<]*)<\/abbr[ \t]*>/i',
                function (array $match) use ($protect): string {
                    $value = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (preg_match('/["\\\\\n{}]/', $value) === 1 || preg_match('/[\[\]\\\n]/', $match[2]) === 1) {
                        return $match[0];
                    }

                    // The label stays live so the later inline passes still
                    // convert markup inside it; only the attribute block is
                    // protected, from the quote escaping further down.
                    return '[' . $match[2] . ']' . $protect('{abbr=' . CarveRenderer::quoteImportedAttrValue($value) . '}');
                },
                $line,
            ) ?? $line;
        }

        return $line;
    }

    /**
     * Convert or preserve raw inline HTML according to the import mode.
     *
     * @param string $line
     * @param \MarkupCarve\Carve\Converter\Markdown\InlineWork $work
     * @param \Closure(string, ?string=): string $protect
     * @param string $nativeInline
     */
    private function rewriteInlineHtml(string $line, InlineWork $work, Closure $protect, string $nativeInline): string
    {
        if (!str_contains($line, '<')) {
            return $line;
        }
        if ($this->context->convertRawHtml) {
            return $this->convertRawInlineHtml($line, $work, $protect, $nativeInline);
        }

        return $this->preserveRawInlineHtml($line, $work, $protect, $nativeInline);
    }

    /**
     * @param string $line
     * @param \MarkupCarve\Carve\Converter\Markdown\InlineWork $work
     * @param \Closure(string, ?string=): string $protect
     * @param string $nativeInline
     */
    private function convertRawInlineHtml(string $line, InlineWork $work, Closure $protect, string $nativeInline): string
    {
        $protectedSources =&$work->protectedSources;
        $htmlBodyIndex = 0;
        preg_match_all('/CarveHtmlBody(\d+)/', $this->decodeHtmlReferenceText($line), $htmlBodyMatches);
        $htmlBodyReserved = array_fill_keys(array_map(static fn (string $index): string => 'CarveHtmlBody' . $index, $htmlBodyMatches[1]), true);
        $line = $this->mapOpaqueHtmlFragments($line, fn (string $span): string => str_starts_with($span, '<!--')
            ? '' : $protect(rtrim((new HtmlToCarve())->convert($span), "\n")));
        $line = preg_replace_callback(
            '/<(?!(?:' . $nativeInline . ')\b)([A-Za-z][A-Za-z0-9-]*)(?:[ \t]+(?:"[^"]*"|\'[^\']*\'|[^<>"\'])*?)?>[\s\S]*?<\/\1[ \t]*>/i',
            function (array $match) use ($protect, &$protectedSources, &$htmlBodyIndex, $htmlBodyReserved): string {
                if (preg_match('/^<(?:address|article|aside|blockquote|details|dialog|div|dl|fieldset|figure|footer|form|h[1-6]|header|main|nav|ol|p|pre|script|section|style|table|template|ul|noscript)\b/i', $match[0]) === 1) {
                    $html = $this->referenceSourceText($match[0], $protectedSources);

                    return $protect(rtrim((new HtmlToCarve())->convert($html), "\n"), $html);
                }
                while (isset($htmlBodyReserved['CarveHtmlBody' . $htmlBodyIndex])) {
                    $htmlBodyIndex++;
                }
                $marker = 'CarveHtmlBody' . $htmlBodyIndex++;
                $opening = $this->htmlTagAt($match[0], 0);
                $closing = strripos($match[0], '</');
                if ($opening === null || $closing === false) {
                    return $match[0];
                }
                $body = substr($match[0], $opening['end'], $closing - $opening['end']);
                $depth = 0;
                $unbalanced = false;
                for ($at = 0, $length = strlen($body); $at < $length; $at++) {
                    if ($body[$at] === '\\') {
                        $at++;
                    } elseif ($body[$at] === '[') {
                        $depth++;
                    } elseif ($body[$at] === ']' && --$depth < 0) {
                        $unbalanced = true;

                        break;
                    }
                }
                if ($unbalanced || $depth !== 0) {
                    $html = $this->referenceSourceText($match[0], $protectedSources);

                    return $protect(rtrim((new HtmlToCarve())->convert($html), "\n"), $html);
                }
                $template = substr($match[0], 0, $opening['end']) . $marker . substr($match[0], $closing);
                $converted = rtrim((new HtmlToCarve())->convert($template), "\n");

                $parts = explode($marker, $converted, 2);
                if (count($parts) !== 2) {
                    return $match[0];
                }

                return $protect($parts[0], substr($match[0], 0, $opening['end'])) . $body . $protect($parts[1], substr($match[0], $closing));
            },
            $line,
        ) ?? $line;
        $line = preg_replace_callback(
            '/<br(?:[ \t]+[^<>]*?)?[ \t]*\/?>/i',
            fn (array $match): string => $protect((new HtmlToCarve())->convert($match[0])),
            $line,
        ) ?? $line;
        $line = preg_replace_callback(
            '/<(?:area|base|col|embed|hr|img|input|link|meta|param|source|track|wbr)(?:[ \t]+[^<>]*?)?[ \t]*\/?>/i',
            fn (array $match): string => $protect(rtrim((new HtmlToCarve())->convert($match[0]), "\n")),
            $line,
        ) ?? $line;

        return $line;
    }

    /**
     * @param string $line
     * @param \MarkupCarve\Carve\Converter\Markdown\InlineWork $work
     * @param \Closure(string, ?string=): string $protect
     * @param string $nativeInline
     */
    private function preserveRawInlineHtml(string $line, InlineWork $work, Closure $protect, string $nativeInline): string
    {
        $protected =&$work->protected;
        $rawInline = function (array $match) use ($protect, &$protected): string {
            $head = substr($match[0], 0, strcspn($match[0], '>'));
            preg_match_all('/\x00P(\d+)\x00/', $head, $tokens);
            foreach ($tokens[1] as $index) {
                if (str_starts_with($protected[(int)$index] ?? '', '`')) {
                    return $match[0];
                }
            }

            return $protect($this->verbatimHtmlInline($match[0]), $match[0]);
        };
        $line = $this->mapOpaqueHtmlFragments($line, fn (string $span): string => $rawInline([$span]));
        $written = '';
        for ($i = 0, $length = strlen($line); $i < $length;) {
            $tag = $line[$i] === '<' ? $this->htmlTagAt($line, $i) : null;
            if ($tag === null) {
                $written .= $line[$i++];

                continue;
            }
            $end = $tag['end'];
            if (preg_match('~^</?(?:' . $nativeInline . ')>$~i', substr($line, $i, $end - $i)) === 1) {
                $written .= substr($line, $i, $end - $i);
            } else {
                $written .= $rawInline([substr($line, $i, $end - $i)]);
            }
            $i = $end;
        }

        return $written;
    }

    /**
     * Escape Carve spellings after Markdown constructs have been rewritten.
     *
     * @param string $line
     * @param \MarkupCarve\Carve\Converter\Markdown\InlineWork $work
     * @param \Closure(string, ?string=): string $protect
     * @param \Closure(string, int): bool $hasProtectedEscapeBefore
     * @param bool $table
     */
    private function escapeFinalInlineText(string $line, InlineWork $work, Closure $protect, Closure $hasProtectedEscapeBefore, bool $table): string
    {
        $protected =&$work->protected;
        $line = $this->escapeCarveConstructsSpelledLikeText($line, $protected);
        $line = $this->escapeTypographicDashes($line);
        $line = preg_replace_callback('/(?<!\\\\)\.{3,}/', static fn (array $run): string => str_replace('.', '\\.', $run[0]), $line) ?? $line;
        $symbolSubject = $line;
        $line = preg_replace_callback(
            '/(?<!\\\\)(?:<-->|<->|-->|<--|<=>|==>|<==|\(tm\)|->|<-|<=|>=|!=|\+-|\(c\)|\(r\))/',
            static fn (array $symbol): string => $hasProtectedEscapeBefore($symbolSubject, $symbol[0][1])
                ? $symbol[0][0][0] . (preg_replace('/[!-\/:-@\[-`{-~]/', '\\\\$0', substr($symbol[0][0], 1)) ?? substr($symbol[0][0], 1))
                : preg_replace('/[!-\/:-@\[-`{-~]/', '\\\\$0', $symbol[0][0]) ?? $symbol[0][0],
            $line,
            flags: PREG_OFFSET_CAPTURE,
        ) ?? $line;
        if (!$this->context->convertAttributes) {
            $line = $this->escapeAttributeListsThatAttach($line);
        }

        if ($this->context->convertAttributes) {
            $wholeLine = trim($line);
            $subject = $line;
            $line = preg_replace_callback(
                '/(?<!\\\\)\{((?:[^{}"\'\\\\]|\\\\.|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')*)\}/',
                static function (array $match) use ($wholeLine, $subject, $protect): string {
                    $offset = $match[0][1];
                    $before = $offset > 0 ? $subject[$offset - 1] : '';
                    $attached = preg_match('/[\x00\]\\/*_~=,^}]/', $before) === 1;

                    return ($wholeLine === $match[0][0] || $attached) && AttributeParser::isValidPayload($match[1][0])
                        ? $protect($match[0][0]) : $match[0][0];
                },
                $line,
                flags: PREG_OFFSET_CAPTURE,
            ) ?? $line;
        }
        $line = preg_replace_callback('/["\']|\x00P(\d+)\x00/', static function (array $match) use ($protected): string {
            $text = isset($match[1]) ? $protected[(int)$match[1]] : $match[0];

            return in_array($text, ['"', "'"], true) ? '\\' . $text : $match[0];
        }, $line) ?? $line;

        if ($table) {
            $line = ($this->escapeTableInlineText)($line);
        }
        if (str_contains($line, '\\')) {
            $line = $this->context->importWriter->spellImportedLiteralBackslashes($line);
        }

        return $line;
    }

    /**
     * Keep code delimiters and angle brackets inside bare link destinations opaque.
     *
     * @param string $line
     * @param \Closure(string, ?string=): string $protect
     */
    private function maskDestinationGlyphs(string $line, Closure $protect): string
    {
        if (str_contains($line, '](') && strpbrk($line, '`<') !== false) {
            preg_match_all('/`+/', $line, $tickRuns, PREG_OFFSET_CAPTURE);
            $nextTick = [];
            $codeEnds = [];
            for ($run = count($tickRuns[0]) - 1; $run >= 0; $run--) {
                [$ticks, $tickAt] = $tickRuns[0][$run];
                $width = strlen($ticks);
                if (isset($nextTick[$width])) {
                    $codeEnds[$tickAt] = $nextTick[$width] + $width;
                }
                $nextTick[$width] = $tickAt;
            }
            $opens = [];
            $glyphs = [];
            for ($at = 0, $length = strlen($line); $at < $length; $at++) {
                if ($line[$at] === '`' && isset($codeEnds[$at])) {
                    $at = $codeEnds[$at] - 1;

                    continue;
                }
                if ($line[$at] === '\\') {
                    $at++;

                    continue;
                }
                if ($line[$at] === '[') {
                    $opens[] = $at;

                    continue;
                }
                if ($line[$at] !== ']' || $opens === []) {
                    continue;
                }
                array_pop($opens);
                $end = $this->markdownActivationTailEnd($line, $at + 1);
                if ($end === null) {
                    continue;
                }
                $pointy = preg_match('/\G\([ \t\n]*</', $line, offset: $at + 1) === 1;
                if ($pointy) {
                    $at = $end;

                    continue;
                }
                for ($pos = $at + 2; $pos < $end; $pos++) {
                    if ($line[$pos] === '`' || $line[$pos] === '<' && preg_match('/\G<\/?[A-Za-z]/', $line, offset: $pos) !== 1) {
                        $glyphs[$pos] = true;
                    }
                }
                $at = $end;
            }
            if ($glyphs !== []) {
                $native = '';
                for ($at = 0, $length = strlen($line); $at < $length; $at++) {
                    $native .= isset($glyphs[$at]) ? $protect($line[$at], $line[$at]) : $line[$at];
                }
                $line = $native;
            }
        }
        if (str_contains($line, '\\<')) {
            $line = preg_replace_callback('/\]\([ \t]*<((?:[^>\n]|\\\\\n)*\\\\<[^>\n]*)>/s', static function (array $match) use ($protect): string {
                return str_replace('\\<', $protect('\\<', '\\<'), $match[0]);
            }, $line) ?? $line;
        }

        return $line;
    }

    /**
     * Restore protected spans after all inline rewrites have finished.
     *
     * @param string $line
     * @param \MarkupCarve\Carve\Converter\Markdown\InlineWork $work
     * @param bool $table
     */
    private function restoreProtectedInlineOutput(string $line, InlineWork $work, bool $table): string
    {
        $protected = $work->protected;
        $codeFallbacks = $work->codeFallbacks;
        $sourceInput = $work->sourceInput;
        $liveCodeFallbacks = [];
        // Restore stashes and protected spans until stable: a protected or
        // stashed span may itself contain placeholders (e.g. a reference
        // definition that wrapped an already-protected URL), so one pass is
        // not enough.
        do {
            $previous = $line;
            $line = preg_replace_callback('/(\x00P(\d+)\x00)(?=\x00P(\d+)\x00)/', static function (array $match) use ($protected): string {
                $left = $protected[(int)$match[2]] ?? '';
                $right = $protected[(int)$match[3]] ?? '';
                $slashes = 0;
                for ($at = strlen($left) - 2; $at >= 0 && $left[$at] === '\\'; $at--) {
                    $slashes++;
                }

                return str_ends_with($left, '`') && str_starts_with($right, '`') && (str_starts_with($left, '`') || $slashes % 2 === 0)
                    ? $match[0] . '{%  %}' : $match[0];
            }, $line) ?? $line;
            $line = preg_replace_callback('/\x00P(\d+)\x00/', function (array $match) use ($protected, $table, &$codeFallbacks, &$liveCodeFallbacks): string {
                if (isset($codeFallbacks[$match[0]])) {
                    $liveCodeFallbacks[$match[0]] = true;
                }
                $span = $protected[(int)$match[1]];
                if (!$table) {
                    return $span;
                }
                if (str_starts_with($span, '![') || str_starts_with($span, '(')) {
                    return ($this->escapeTablePipes)($span);
                }

                return ($this->escapeTableInlineText)($span, !str_contains($span, '[') || (!str_contains($span, '|') && !str_contains($span, '{%')));
            }, $line) ?? $line;
        } while ($line !== $previous);

        foreach ($codeFallbacks as $token => $diagnostic) {
            if (isset($liveCodeFallbacks[$token])) {
                $this->context->codeFallbackDiagnostics[] = $diagnostic;
            }
        }

        $line = preg_replace_callback(
            '/%00P(\d+)%00/',
            fn (array $match): string => isset($protected[(int)$match[1]])
                ? $this->decodeLinkTitle($protected[(int)$match[1]], $protected) : $match[0],
            $line,
        ) ?? $line;

        if (str_contains($line, ']\\(') && str_contains($sourceInput, '](<')) {
            $line = preg_replace('~\]\\\\\((`<[^`]*\n[^`]*>`\{=html\}\))~', ']($1', $line) ?? $line;
        }
        if (str_contains($line, '\\]') && preg_match('/([*_])[^]\n]*\[[^]\n]*\1[^]\n]*\]/', $sourceInput) === 1) {
            $line = preg_replace_callback(
                '~(/[^/\n]* )\[([^]\n]*?/[^]\n]*)\\\\\]~',
                static fn (array $match): string => $match[1] . '\\[' . $match[2] . ']',
                $line,
            ) ?? $line;
        }

        return str_replace("\x00FNEMPTY\x00", '{empty}', $line);
    }

    /**
     * CommonMark lets no link hold a link: once one closes, every `[` opened
     * before it stays literal. Each such bracket's closer, with an inline
     * destination after it, is written as the Carve writer writes that text.
     *
     * @param string $line
     * @param array<string> $protected
     * @param \Closure(string): string $protect
     * @param array<int, true> $closers Receives the protected index of each closer written.
     */
    protected function protectClosersOfLinksHoldingALink(string $line, array $protected, Closure $protect, array &$closers): string
    {
        if (!str_contains($line, '](') && !str_contains($line, '][')) {
            return $line;
        }
        $literal = $this->markdownLinkScopes($line, $protected)['literal'];
        if ($literal === []) {
            return $line;
        }
        $written = $this->writtenTexts(array_map(static fn (string $tail): string => ']' . $tail, $literal), $protected);
        $out = '';
        $from = 0;
        foreach ($literal as $offset => $tail) {
            $bytes = $written[']' . $tail];
            $consumed = 1 + strlen($tail);
            // A tail that holds inline markup or a reference is still Markdown to convert.
            if (str_starts_with($tail, '[') || preg_match('/[*_`<&!\\[\x00]/', $tail) === 1) {
                $bytes = '\\]';
                $consumed = 1;
            }
            $placeholder = $protect($bytes);
            $closers[(int)substr($placeholder, 2, -1)] = true;
            $out .= substr($line, $from, $offset - $from) . $placeholder;
            $from = $offset + $consumed;
        }

        return $out . substr($line, $from);
    }

    /**
     * @param string $line
     * @param array<int, string> $protected
     * @param bool $scopeOnly
     * @param \Closure(int): ?int|null $opaqueEnd
     *
     * @return array{active: list<int>, literal: array<int, string>}
     */
    private function markdownLinkScopes(string $line, array $protected, bool $scopeOnly = false, ?Closure $opaqueEnd = null): array
    {
        $reference = '/\G\[([^[\]\n]*)\]/';
        $defined = fn (string $label): bool => isset($this->context->definedReferenceLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($label, $protected))])
            || $scopeOnly && $this->complexReferenceTarget($label, $protected) !== null;

        $active = [];
        /** @var array<array{start: int, image: bool}> $openers */
        $openers = [];
        // Every non-image opener pushed before this offset is inactive.
        $deactivatedBefore = -1;
        /** @var array<int, string> $literal closer offset => the tail after it */
        $literal = [];
        $length = strlen($line);
        $escapedPosition = -1;
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($scopeOnly && $char === '\\') {
                $escapedPosition = $i + 1;
                $i++;

                continue;
            }
            if ($char === '<' && preg_match('/\\G<[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\\s]*>/', $line, $autolink, 0, $i) === 1) {
                // Brackets inside an autolink are text; the enclosing link stays active.
                $i += strlen($autolink[0]) - 1;

                continue;
            }
            if ($char === '<' && $opaqueEnd !== null) {
                $end = $opaqueEnd($i);
                if ($end !== null) {
                    $i = $end - 1;

                    continue;
                }
            }
            if ($char === '[') {
                $image = $i > 0 && $line[$i - 1] === '!' && (!$scopeOnly || $escapedPosition !== $i - 1);
                $openers[] = ['start' => $i, 'image' => $image];

                continue;
            }
            if ($char !== ']' || $openers === []) {
                continue;
            }
            $opener = array_pop($openers);
            $inlineEnd = $this->markdownActivationTailEnd($line, $i + 1);
            $inline = $inlineEnd === null ? null : substr($line, $i + 1, $inlineEnd - $i);
            if ($inline !== null) {
                $authored = $this->referenceSourceText($inline, $protected);
                if ($this->markdownActivationTailEnd($authored, 0) !== strlen($authored) - 1) {
                    $inline = null;
                }
            }
            $full = $inline === null && preg_match($reference, $line, $ref, 0, $i + 1) === 1 ? $ref : null;
            if (!$opener['image'] && $opener['start'] < $deactivatedBefore) {
                if ($inline !== null || $full !== null) {
                    $literal[$i] = $inline ?? $full[0];
                }

                continue;
            }
            $label = substr($line, $opener['start'] + 1, $i - $opener['start'] - 1);
            $end = null;
            if ($inline !== null) {
                $end = $i + strlen($inline);
            } elseif ($full !== null) {
                if ($defined($full[1] === '' ? $label : $full[1])) {
                    $end = $i + strlen($full[0]);
                }
            } elseif ($defined($label)) {
                $end = $i;
            }
            if ($end === null) {
                continue;
            }
            $active[] = $opener['start'];
            if ($scopeOnly) {
                $active[] = $i;
            }
            if (!$opener['image']) {
                $deactivatedBefore = $i;
            }
            $i = $end;
        }

        return ['active' => $active, 'literal' => $literal];
    }

    /**
     * The bytes the Carve writer writes for each text when it follows a link
     * inside an open bracket. Each is rendered as its own document: the writer
     * reads document-wide context, so a shared one changes the bytes.
     *
     * @param array<string> $texts
     * @param array<string> $protected
     *
     * @return array<string, string>
     */
    protected function writtenTexts(array $texts, array $protected): array
    {
        $codec = new AstCodec();
        $renderer = new CarveRenderer();
        $written = [];
        foreach (array_unique($texts) as $text) {
            $decoded = preg_replace_callback('/\x00P(\d+)\x00/', fn (array $match): string => $this->decodeLinkTitle($protected[(int)$match[1]], $protected), $text) ?? $text;
            $document = $codec->decode([
                'type' => 'document',
                'srcByteLength' => 0,
                'children' => [
                    [
                        'type' => 'paragraph',
                        'children' => [
                            ['type' => 'text', 'value' => '['],
                            ['type' => 'link', 'href' => 'x', 'children' => [['type' => 'text', 'value' => 'x']]],
                            ['type' => 'text', 'value' => $decoded],
                        ],
                    ],
                ],
            ]);
            $written[$text] = substr(rtrim($renderer->render($document), "\n"), strlen('[[x](x)'));
        }

        return $written;
    }

    public function markdownActivationTailEnd(string $source, int $open): ?int
    {
        if (($source[$open] ?? '') !== '(') {
            return null;
        }
        $at = $open + 1;
        $length = strlen($source);
        $skipSpace = static function () use ($source, &$at, $length): bool {
            $begin = $at;
            while ($at < $length && str_contains(" \t\n", $source[$at])) {
                $at++;
            }

            return $at > $begin;
        };
        $skipSpace();
        if (($source[$at] ?? '') === '<') {
            for ($at++; $at < $length && $source[$at] !== '>'; $at++) {
                if ($source[$at] === '\\' && preg_match('/[!-\/:-@\[-`{-~]/', $source[$at + 1] ?? '') === 1) {
                    $at++;
                } elseif ($source[$at] === '\\' && ($source[$at + 1] ?? '') === "\n") {
                    $at++;
                } elseif ($source[$at] === '<' || $source[$at] === "\n") {
                    return null;
                }
            }
            if (($source[$at] ?? '') !== '>') {
                return null;
            }
            $at++;
        } else {
            $depth = 0;
            for (; $at < $length; $at++) {
                if ($source[$at] === "\0" && preg_match('/\\G\\x00P\\d+\\x00/', $source, $token, 0, $at) === 1) {
                    $at += strlen($token[0]) - 1;

                    continue;
                }
                if ($source[$at] === '\\' && preg_match('/[!-\/:-@\[-`{-~]/', $source[$at + 1] ?? '') === 1) {
                    $at++;

                    continue;
                }
                if ($source[$at] === '(') {
                    if (++$depth > 32) {
                        return null;
                    }
                } elseif ($source[$at] === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif (ord($source[$at]) <= 32 || ord($source[$at]) === 127) {
                    break;
                }
            }
            if ($depth > 0 && $at >= $length) {
                return null;
            }
        }
        $spaced = $skipSpace();
        if (($source[$at] ?? '') === ')') {
            return $at;
        }
        if (!$spaced || !in_array($source[$at] ?? '', ['"', "'", '('], true)) {
            return null;
        }
        $close = $source[$at] === '(' ? ')' : $source[$at];
        $at++;
        for (; $at < $length && $source[$at] !== $close; $at++) {
            if ($source[$at] === '\\') {
                $at++;
            } elseif ($close === ')' && $source[$at] === '(') {
                return null;
            } elseif ($source[$at] === "\n" && preg_match('/\\G[ \\t]*\\n/', $source, $blank, 0, $at + 1) === 1) {
                return null;
            }
        }
        if ($at >= $length) {
            return null;
        }
        $at++;
        $skipSpace();

        return ($source[$at] ?? '') === ')' ? $at : null;
    }

    /**
     * The Carve a link or image with no destination leaves behind: a link's
     * text, or an image's plain alt text written as the HTML importer writes
     * `<img src="">`, in a span when a title survives.
     *
     * @param string $label
     * @param bool $image
     * @param string $title Decoded title, or empty.
     * @param string $before The line up to the link.
     * @param array<string> $protected
     * @param \Closure(string): string $protect
     * @param bool $hasTitle
     */
    protected function unwrapEmptyDestination(
        string $label,
        bool $image,
        string $title,
        string $before,
        array $protected,
        Closure $protect,
        bool $hasTitle = false,
    ): string {
        // Bare at the start of a line or container, the text would open a block.
        $lineStart = preg_match('/^[ \t]*(?:(?:>|[-*+]|(?:[0-9]{1,9}|[A-Za-z])[.)])[ \t]+|>)*$/', $before) === 1;
        if ($image) {
            $html = '<p><img src="" alt="' . htmlspecialchars($this->plainAltText($label, $protected), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
                . (!$hasTitle ? '' : ' title="' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"') . '></p>';
            $text = trim((new HtmlToCarve())->convert($html), "\n");

            return $protect($lineStart ? $this->escapeLineInitialBlockSyntax($text) : $text);
        }
        $slot = $hasTitle ? '( "' . QuotedSlotEscaper::escape($title, '"') . '")' : '()';

        return '[' . $label . ']' . $protect($slot);
    }

    /**
     * @param int $index
     * @param array<string> $protected
     * @param array<string> $sources
     *
     * @return array{source: string, opaque: bool}|string|null
     */
    private function markdownUrlTokenSource(int $index, array $protected, array $sources): array|string|null
    {
        $span = $protected[$index] ?? '';
        $source = $sources[$index] ?? $span;
        if (preg_match('/^\\\\[\x21-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]$/', $span) === 1 || $span === '\\' || preg_match('/^&(?:#[xX][0-9A-Fa-f]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});$/', $source) === 1) {
            return $source;
        }
        if (preg_match('/^!?`/', $span) === 1 || str_starts_with($span, '(') || str_starts_with($span, '![') || str_starts_with($span, '[')) {
            return ['source' => $source, 'opaque' => true];
        }

        return null;
    }

    /**
     * @param string $line
     * @param array<string> $protected
     * @param array<string> $protectedSources
     *
     * @return list<array{start: int, end: int}>
     */
    private function plainMarkdownUrlRanges(string $line, array $protected, array $protectedSources): array
    {
        return BareLinks::ranges($line, function (string $token) use ($protected, $protectedSources): string|array|null {
            $index = (int)substr($token, 2, -1);

            return $this->markdownUrlTokenSource($index, $protected, $protectedSources);
        }, 'url');
    }

    private function hasOddMarkdownDelimiterRun(string $source): bool
    {
        // Even runs are consumed in pairs as strong delimiters. A new emphasis
        // slash requires an odd run; escaped or unmatched runs may over-admit.
        $length = strlen($source);
        for ($offset = 0; $offset < $length;) {
            $offset += strcspn($source, '*_', $offset);
            if ($offset === $length) {
                break;
            }
            $width = strspn($source, $source[$offset], $offset);
            if ($width % 2 !== 0) {
                return true;
            }
            $offset += $width;
        }

        return false;
    }

    /**
     * @param string $source
     * @param array<string> $protected
     */
    private function escapeLinkLabelSlashes(string $source, array $protected): string
    {
        if (!str_contains($source, '[')) {
            return $source;
        }

        $ranges = Emphasis::linkLabelRanges($source, $protected);
        $outermost = [];
        $previousEnd = 0;
        foreach ($ranges as $range) {
            if ($range['start'] < $previousEnd) {
                continue;
            }
            $outermost[] = $range;
            $previousEnd = $range['end'];
        }
        $ranges = array_values(array_filter($outermost, static function (array $range) use ($source, $protected): bool {
            $label = substr($source, $range['start'], $range['end'] - $range['start']);
            if (!str_contains($label, '/')) {
                return false;
            }
            $converted = Emphasis::convert($label, protectedSpans: $protected);

            return substr_count($converted, '/') > substr_count($label, '/') || preg_match('/<(?:em|i)>/i', $label) === 1;
        }));
        if ($ranges === []) {
            return $source;
        }
        $rangeAt = 0;
        $out = '';
        $opaqueEnds = [];
        for ($at = 0, $length = strlen($source); $at < $length; $at++) {
            if ($source[$at] === '\\' && $at + 1 < $length) {
                $out .= substr($source, $at, 2);
                $at++;

                continue;
            }
            if ($source[$at] === "\0") {
                $end = strpos($source, "\0", $at + 1);
                if ($end !== false) {
                    $out .= substr($source, $at, $end - $at + 1);
                    $at = $end;

                    continue;
                }
            }
            $tagEnd = $source[$at] === '<'
                ? $this->opaqueHtmlEnd($source, $at, $opaqueEnds) ?? ($this->htmlTagAt($source, $at)['end'] ?? null)
                : null;
            if ($tagEnd !== null) {
                $out .= substr($source, $at, $tagEnd - $at);
                $at = $tagEnd - 1;

                continue;
            }
            while (isset($ranges[$rangeAt]) && $ranges[$rangeAt]['end'] <= $at) {
                $rangeAt++;
            }
            $range = $ranges[$rangeAt] ?? null;
            if ($source[$at] === '/' && $range !== null && $at >= $range['start'] && $at < $range['end']) {
                $out .= '\\';
            }
            $out .= $source[$at];
        }

        return $out;
    }

    /**
     * CommonMark's alt text: the description's content with its markup removed.
     *
     * @param string $label
     * @param array<string> $protected
     * @param array<string>|null $sources
     */
    protected function plainAltText(string $label, array $protected, ?array $sources = null): string
    {
        $sources ??= $protected;
        do {
            $previous = $label;
            $label = preg_replace_callback('/\x00P(\d+)\x00/', static function (array $match) use ($protected): string {
                $span = $protected[(int)$match[1]] ?? $match[0];

                return str_starts_with($span, '(') || str_starts_with($span, '![') || str_starts_with($span, '[') ? $span : $match[0];
            }, $label) ?? $label;
            $nested = '';
            $nestedCursor = 0;
            $nestedAt = 0;
            while (preg_match('/!?\[(?<text>(?:[^[\]\n]|(?<nest>\[(?:[^[\]\n]|(?&nest))*\]))*)\](?=\()/', $label, $link, PREG_OFFSET_CAPTURE, $nestedAt) === 1) {
                $start = $link[0][1];
                $open = $start + strlen($link[0][0]);
                $end = $this->markdownActivationTailEnd($label, $open);
                $destination = $end === null ? '' : substr($label, $open, $end - $open + 1);
                $original = str_contains($destination, "\0") ? $this->referenceSourceText($destination, $sources) : $destination;
                if ($end === null || $this->markdownActivationTailEnd($original, 0) !== strlen($original) - 1) {
                    $nestedAt = $start + 1;

                    continue;
                }
                $nested .= substr($label, $nestedCursor, $start - $nestedCursor) . $link['text'][0];
                $nestedCursor = $nestedAt = $end + 1;
            }
            $label = $nested . substr($label, $nestedCursor);
            $written = '';
            $cursor = 0;
            $offset = 0;
            while (preg_match('/!?\[(?<text>(?:[^[\]\n]|(?<nest>\[(?:[^[\]\n]|(?&nest))*\]))*)\]/', $label, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $start = $match[0][1];
                $end = $start + strlen($match[0][0]);
                $text = $match['text'][0];
                $written .= substr($label, $cursor, $start - $cursor);
                $tail = [];
                $hasTail = preg_match('/\G\[([^[\]\n]*)\]/', $label, $tail, 0, $end) === 1;
                $reference = $hasTail && $tail[1] !== '' ? $tail[1] : $text;
                if ($hasTail && isset($this->context->definedReferenceLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($reference, $protected))])) {
                    $written .= $text;
                    $end += strlen($tail[0]);
                } else {
                    $next = $label[$end] ?? '';
                    $written .= !$hasTail && $next !== '(' && $next !== ':' && isset($this->context->definedReferenceLabels[$this->normalizeReferenceLabel($this->decodeLinkTitle($text, $protected))]) ? $text : $match[0][0];
                }
                $cursor = $offset = $end;
            }
            $label = $written . substr($label, $cursor);
        } while ($label !== $previous);

        $label = preg_replace_callback(
            '/\x00P(\d+)\x00(?=>)/',
            static fn (array $match): string => ($protected[(int)$match[1]] ?? '') === '\\' ? '' : $match[0],
            $label,
        ) ?? $label;

        $label = Emphasis::convert($label, protectedSpans: $protected, plainText: true);

        return preg_replace_callback(
            '/\x00P(\d+)\x00/',
            function (array $match) use ($protected): string {
                $span = $protected[(int)$match[1]];
                if (preg_match('/^(`+)(.*)\1$/s', $span, $code) === 1) {
                    return preg_match('/^ (.*\S.*) $/s', $code[2], $padded) === 1 ? $padded[1] : $code[2];
                }

                return $this->decodeLinkTitle($span, $protected);
            },
            $label,
        ) ?? $label;
    }

    protected function bareDestination(string $pointy): string
    {
        $pointyLine = 0;
        $pointy = preg_replace_callback('/\\\\\n[ \t]*/', function (array $match) use (&$pointyLine): string {
            $pointyLine++;

            return "\\\n" . (($this->context->pointyContinuationStrip[$pointyLine] ?? false) ? '' : substr($match[0], 2));
        }, $pointy) ?? $pointy;
        $url = preg_replace_callback('/\\\\([!-\/:-@\[-`{-~])/', static fn (array $match): string => $match[1] === '&' ? $match[0] : $match[1], $pointy) ?? $pointy;

        return str_replace('\\%0A', '\\\\%0A', DestinationEncoding::destination($url));
    }

    private function decodeMarkdownScalar(string $text): string
    {
        return preg_replace_callback(
            '/\\\\([!-\/:-@\[-`{-~])|&(?:#[xX][0-9A-Fa-f]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/',
            fn (array $match): string => $match[1] ?? $this->decodeHtmlReferenceText($match[0]),
            $text,
            flags: PREG_UNMATCHED_AS_NULL,
        ) ?? $text;
    }

    /**
     * @param string $url
     * @param array<string> $protected
     * @param bool $originalSource
     * @param bool $table
     */
    protected function writeMarkdownDestination(string $url, array $protected = [], bool $originalSource = false, bool $table = false): string
    {
        $decoded = $originalSource
            ? $this->decodeMarkdownScalar($this->referenceSourceText($url, $protected))
            : $this->decodeLinkTitle($url, $protected);

        $encoded = DestinationEncoding::destination($decoded, $table);
        $this->recordAuthoredDestination($encoded, $this->referenceSourceText($url, $protected));

        return $encoded;
    }

    /**
     * @param string $label
     * @param array<string> $protected
     */
    protected function complexReferenceTarget(string $label, array $protected): ?string
    {
        return $this->context->complexReferenceTargets[$this->normalizeReferenceLabel($this->referenceSourceText($label, $protected))] ?? null;
    }

    /**
     * @param string $label
     * @param array<string> $protected
     *
     * @return string
     */
    protected function referenceSourceText(string $label, array $protected): string
    {
        $protectedCount = count($protected);
        for ($pass = 0; $pass < $protectedCount; $pass++) {
            $restored = preg_replace_callback('/\x00P(\d+)\x00/', static fn (array $match): string => $protected[(int)$match[1]] ?? $match[0], $label) ?? $label;
            if ($restored === $label) {
                break;
            }
            $label = $restored;
        }

        return $label;
    }

    public function normalizeReferenceLabel(string $label): string
    {
        return mb_convert_case(preg_replace('/\s+/u', ' ', trim($label)) ?? $label, MB_CASE_FOLD, 'UTF-8');
    }

    /**
     * A title's text: backslash escapes and character references decoded,
     * protected spans decoded one at a time so their output is not read again.
     *
     * @param string $title
     * @param array<string> $protected
     */
    public function decodeLinkTitle(string $title, array $protected = []): string
    {
        return preg_replace_callback(
            '/\x00P(\d+)\x00|\\\\([!-\/:-@\[-`{-~])|&(?:#[xX][0-9A-Fa-f]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/',
            fn (array $match): string => match (true) {
                $match[1] !== null => $this->decodeLinkTitle($protected[(int)$match[1]], $protected),
                $match[2] !== null => $match[2],
                default => html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            },
            $title,
            flags: PREG_UNMATCHED_AS_NULL,
        ) ?? $title;
    }

    private function decodeHtmlReferenceText(string $text): string
    {
        return preg_replace_callback(
            '/&(?:#[xX][0-9A-Fa-f]{1,6}|#[0-9]{1,7}|[A-Za-z][A-Za-z0-9]{1,31});/',
            static function (array $match): string {
                if (preg_match('/^&#([xX]?)([0-9a-fA-F]+);$/', $match[0], $numeric) !== 1) {
                    return html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $point = intval($numeric[2], $numeric[1] === '' ? 10 : 16);

                return $point === 0 || $point > 0x10ffff || ($point >= 0xd800 && $point <= 0xdfff)
                    ? "\u{FFFD}"
                    : mb_chr($point, 'UTF-8');
            },
            $text,
        ) ?? $text;
    }

    protected function verbatimHtmlInline(string $html): string
    {
        $this->reportRawSpanTrailingWhitespace($html);
        $delimiterLength = 1;
        if (preg_match_all('/`+/', $html, $runs) > 0) {
            foreach ($runs[0] as $run) {
                $delimiterLength = max($delimiterLength, strlen($run) + 1);
            }
        }
        $delimiter = str_repeat('`', $delimiterLength);

        return $delimiter . $html . $delimiter . '{=html}';
    }

    /**
     * Report every whitespace run a raw span would leave at the end of a
     * content line.
     *
     * CARVE-P2-025 drops such a run from every content line and a verbatim run
     * crossing a line break is no exception, so the bytes are written and never
     * read back (markup-carve/carve#2804). Whitespace anywhere else in the span
     * survives and is not reported.
     */
    private function reportRawSpanTrailingWhitespace(string $html): void
    {
        if (preg_match_all('/[ \t\v\f]+\n/', $html, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return;
        }
        foreach ($matches[0] as $match) {
            $line = $this->context->inlineRunSourceLine === null
                ? null
                : $this->context->inlineRunSourceLine + substr_count(substr($html, 0, (int)$match[1]), "\n");
            $this->context->rawSpanWhitespaceDiagnostics[] = new MigrationDiagnostic(
                'raw-span-whitespace-trimmed',
                self::RAW_SPAN_WHITESPACE_TRIMMED,
                'warning',
                'degraded',
                'exact',
                $line === null ? null : 'line:' . $line,
            );
        }
    }

    /**
     * Escape a marker run that only a DECODED separator turns into a heading.
     *
     * CommonMark decides the block before any reference is decoded, and an ATX
     * heading needs a literal space or tab after its marker run, so
     * `#&#32;nosep` is a PARAGRAPH whose text begins with `#`. Written bare the
     * line reads back as a level-1 heading: it gains an id and enters the
     * outline, and nothing marks the change. Escaping the run keeps the block
     * the source had, which is what carve-rs writes
     * (markup-carve/carve-php#3058).
     *
     * Only a SPACE opens a Carve heading, so a decoded tab is left bare, as is
     * a run of seven. `$headingHead` is the control: there the SOURCE supplies
     * the separator, the line IS a heading, and carve-php#3057 rules it. In a
     * table cell a `#` run is literal text, so nothing is owed there.
     *
     * @param string $line
     * @param array<int, string> $protected
     * @param string $contentPrefix
     * @param bool $headingHead
     * @param bool $table
     *
     * @return string
     */
    private function escapeMarkerRunADecodedSeparatorPromotes(
        string $line,
        array $protected,
        string $contentPrefix,
        bool $headingHead,
        bool $table,
    ): string {
        if ($table || $headingHead || !str_starts_with($line, $contentPrefix)) {
            return $line;
        }

        $content = substr($line, strlen($contentPrefix));
        if (preg_match('/^(#{1,6})\x00P(\d+)\x00/', $content, $match) !== 1) {
            return $line;
        }
        if (($protected[(int)$match[2]] ?? '') !== ' ') {
            return $line;
        }

        $rest = substr($content, strlen($match[0]));
        // The decode WAS the whole content. A marker run alone on its line is
        // already a paragraph in Carve, so the separator goes and the run stays
        // bare: `#&#32;` writes `#`.
        if (preg_match('/^[ \t]*$/', $rest) === 1) {
            return $contentPrefix . $match[1];
        }

        return $contentPrefix . str_replace('#', '\\#', $match[1]) . substr($content, strlen($match[1]));
    }

    /**
     * Drop whitespace a decoded character reference put at the start of a line,
     * and report it.
     *
     * To every block rule that runs after the decode that whitespace is
     * indentation, and Carve spells none at a paragraph's start, so it reaches
     * the document either way as nothing: written, it is read back as nothing.
     * Dropped rather than substituted - see
     * `self::LEADING_WHITESPACE_UNSPELLABLE`.
     *
     * A marker the drop uncovers at column 0 is escaped, so the line stays the
     * paragraph it was: `&#32;- item` is text, not a list. A reference
     * definition needs its own escape, since it reaches no output at all rather
     * than merely becoming a different block.
     *
     * @param string $line
     * @param array<int, string> $protected
     * @param bool $bareContentStart
     * @param string $contentPrefix
     * @param \Closure $protect
     * @param bool $headingHead
     */
    private function dropDecodedLeadingWhitespace(
        string $line,
        array &$protected,
        bool $bareContentStart,
        string $contentPrefix,
        Closure $protect,
        bool $headingHead = false,
    ): string {
        if (!$bareContentStart || $this->context->inlineRunContinuesPrevious || !str_starts_with($line, $contentPrefix)) {
            return $line;
        }

        $content = substr($line, strlen($contentPrefix));
        if ($headingHead) {
            $headingContent = rtrim($content, " \t");
            $decodedContent = preg_replace_callback('/\x00P(\d+)\x00/', static fn (array $match): string => $protected[(int)$match[1]] ?? $match[0], $headingContent) ?? $headingContent;
            if ($decodedContent !== '' && preg_match('/^[ \t]+$/', $decodedContent) === 1) {
                $this->context->leadingWhitespaceDiagnostics[] = new MigrationDiagnostic(
                    'raw-preserved',
                    self::HEADING_WHITESPACE_RAW_PRESERVED,
                    'warning',
                    'degraded',
                    'exact',
                    $this->context->inlineRunSourceLine === null ? null : 'line:' . $this->context->inlineRunSourceLine,
                );

                return $contentPrefix . $protect($this->verbatimHtmlInline($decodedContent), $headingContent);
            }
        }
        $dropped = false;
        while (preg_match('/^\x00P(\d+)\x00/', $content, $match) === 1) {
            $index = (int)$match[1];
            if (preg_match($headingHead ? '/^ +$/' : '/^[ \t]+$/', $protected[$index] ?? '') !== 1) {
                break;
            }
            $protected[$index] = '';
            $content = substr($content, strlen($match[0]));
            $dropped = true;
        }
        if (!$dropped) {
            return $line;
        }

        $this->context->leadingWhitespaceDiagnostics[] = new MigrationDiagnostic(
            'structure-unspellable',
            $headingHead ? self::HEADING_LEADING_WHITESPACE_UNSPELLABLE : self::LEADING_WHITESPACE_UNSPELLABLE,
            'warning',
            'dropped',
            'exact',
            $this->context->inlineRunSourceLine === null ? null : 'line:' . $this->context->inlineRunSourceLine,
        );

        // Past a heading marker there is no block to open, so what the drop
        // uncovers needs no escape: `# &#32;- x` is the heading `# - x`.
        if ($headingHead) {
            return $contentPrefix . $content;
        }

        // The uncovered opener can itself be a DECODED character, a protected
        // span by now, so that escape goes inside the span: without it
        // `&#32;&gt; quote` left a `>` at column 0 and opened a quote. A span
        // the decode escaper already froze needs nothing.
        if (preg_match('/^\x00P(\d+)\x00/', $content, $head) === 1) {
            $index = (int)$head[1];
            $span = $protected[$index] ?? '';
            if (!str_starts_with($span, '\\') && str_starts_with($this->escapeLineInitialBlockSyntax($span . substr($content, strlen($head[0]))), '\\')) {
                $protected[$index] = '\\' . $span;
            }

            return $contentPrefix . $content;
        }

        $content = $this->escapeLineInitialBlockSyntax($content);

        return $contentPrefix . (preg_replace('/^\*?(?=\[[^\]\n]*\]:)/', '\\\\$0', $content) ?? $content);
    }

    /**
     * Make decoded HTML-reference text inert in Carve source.
     *
     * A reference is text even when it decodes to punctuation that could open
     * Carve markup. Escaping those ASCII punctuation characters before the
     * protected span is restored keeps `&ast;x&ast;` as literal `*x*` while
     * leaving ordinary Unicode references such as `&copy;` untouched.
     */
    protected function escapeDecodedCharacterReference(string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+.!~\/=^,:@\$%|\-<>])/', '\\\\$1', $text) ?? $text;
    }

    /**
     * Escape the Carve constructs that CommonMark and GFM read as ORDINARY
     * TEXT (markup-carve/carve#1130; the carve-js#1060 rule set).
     *
     * @param string $line
     * @param array<string> $protected The protected spans, so a sigil can be checked against the code span it precedes.
     */
    protected function escapeCarveConstructsSpelledLikeText(string $line, array $protected): string
    {
        // `$`x`` / `$$`x`` (math) and `!`x`` (literal). The code span is a
        // placeholder by now, so the sigil is matched against the placeholder
        // and the stored span is checked to BE a code span rather than some
        // other protected construct. EVERY dollar of the run is escaped, not
        // just the first: in `\$$`x`` the second dollar still opens math.
        $line = preg_replace_callback('/(?<!\\\\)(\$+|!)\x00P(\d+)\x00/', function (array $match) use ($protected): string {
            if (!str_starts_with($protected[(int)$match[2]] ?? '', '`')) {
                return $match[0];
            }
            $escaped = '';
            foreach (str_split($match[1]) as $char) {
                $escaped .= '\\' . $char;
            }

            return $escaped . substr($match[0], strlen($match[1]));
        }, $line) ?? $line;

        // `:name[…]` calls an extension. The opener needs no left boundary -
        // Carve reads `foo:term[x]` as an extension call the same as
        // ` :term[x]` - so the rule takes none either. `at 10:30[x]` is
        // untouched because the name must start with a letter.
        $line = preg_replace_callback('/(?<!\\\\):(?=[A-Za-z][A-Za-z0-9-]*\[)/', static fn (): string => '\\:', $line) ?? $line;

        if (!$this->context->convertInlineFootnotes) {
            // `^[body]` is an inline footnote: the text moves to the foot of
            // the document. A footnote REFERENCE is untouched - the caret in
            // `a[^1]` is followed by the label, not by a bracket.
            $line = preg_replace_callback('/(?<!\\\\)\^(?=\[)/', static fn (): string => '\\^', $line) ?? $line;
        }

        if (!$this->context->convertAbbreviations) {
            // `*[HTML]: HyperText` defines an abbreviation: the definition
            // line disappears from the render and every later `HTML` becomes
            // an `<abbr>`. Carve wants the space after the colon, so `*[A]:x`
            // is already literal.
            $line = preg_replace_callback('/^(?=\*\[[^\]\n]+\]:[ \t])/m', static fn (): string => '\\', $line) ?? $line;
        }

        if (!$this->context->convertFencedDivs) {
            // `::: name` opens a div and `:::` closes it: both fence lines
            // disappear from the render and everything between them is
            // wrapped. Carve wants a space or a line end after the colons, so
            // `:::note` is already literal.
            $line = preg_replace_callback('/^(?=:{3,}([ \t]|$))/m', static fn (): string => '\\', $line) ?? $line;
        }

        return $line;
    }

    /**
     * Escape every hyphen of a `--` or `---` run, which Carve's smart typography
     * renders as an en or em dash and Markdown keeps as typed. A line that is
     * only a thematic break, alone or under its containers' markers, is
     * structure rather than text and keeps its hyphens.
     */
    protected function escapeTypographicDashes(string $line): string
    {
        if (!str_contains($line, '--')) {
            return $line;
        }
        $rest = preg_replace('/^[ \t]*(?:>[ \t]?)*/', '', $line) ?? $line;
        while (true) {
            if (preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $rest) === 1) {
                return $line;
            }
            if (preg_match('/^[ \t]*(?:(?:[-*+]|\d+[.)]) +|>[ \t]?)/', $rest, $marker) !== 1) {
                break;
            }
            $rest = substr($rest, strlen($marker[0]));
        }

        return preg_replace_callback('/(?<!\\\\)-{2,}/', static fn (array $run): string => str_replace('-', '\\-', $run[0]), $line) ?? $line;
    }

    protected function escapeAttributeListsThatAttach(string $line): string
    {
        $escapeUnlessDelimiterPair = function (array $match): string {
            $interior = $match[1];
            if (preg_match('/^([\^,=+\-~\/#*_])[^\n]*\1$/', $interior) === 1 || (preg_match('/^[\/*_^,~=]/', $interior) === 1 && str_contains($interior, '{'))) {
                return $match[0];
            }
            // A TAG opener at the head of the payload needs escaping too: the
            // general tag rule skips a `#` behind an unescaped `{`, and
            // escaping the brace here takes that premise away, so `{#id}`
            // came out as `\{` plus a live `#id` tag. Only the head position
            // is affected; `{.a #b}` is escaped by the general rule already.
            $interior = preg_replace('/^#(?=[A-Za-z0-9-])/', '\\\\#', $interior) ?? $interior;

            return '\\{' . $interior . '}';
        };

        $line = preg_replace_callback('/(?<=\x00|[\]\/*_~=,^}])\{([^}\n]*)\}/', $escapeUnlessDelimiterPair, $line) ?? $line;

        return preg_replace_callback('/^\{([^}\n]*)\}(?=[ \t]*$)/m', $escapeUnlessDelimiterPair, $line) ?? $line;
    }

    /**
     * A body that would close or re-open the braced critic construct it is
     * about to be written into, so the tag stays a raw span instead.
     */
    protected function breaksOutOfACriticBody(string $body): bool
    {
        return preg_match('/[{}\\\\\n]|~>/', $body) === 1;
    }

    /**
     * @param string $source
     * @param int $start
     * @param array<string, int|false> $ends
     */
    private function opaqueHtmlEnd(string $source, int $start, array &$ends): ?int
    {
        if (substr_compare($source, '<!-->', $start, 5) === 0) {
            return $start + 5;
        }
        if (substr_compare($source, '<!--->', $start, 6) === 0) {
            return $start + 6;
        }
        $kind = null;
        $terminator = '';
        $minimum = $start;
        if (substr_compare($source, '<!--', $start, 4) === 0) {
            [$kind, $terminator, $minimum] = ['comment', '-->', $start + 4];
        } elseif (substr_compare($source, '<?', $start, 2) === 0) {
            [$kind, $terminator, $minimum] = ['processing', '?>', $start + 2];
        } elseif (substr_compare($source, '<![CDATA[', $start, 9) === 0) {
            [$kind, $terminator, $minimum] = ['cdata', ']]>', $start + 9];
        } elseif (substr_compare($source, '<!', $start, 2) === 0 && preg_match('/[A-Za-z]/', $source[$start + 2] ?? '') === 1) {
            [$kind, $terminator, $minimum] = ['declaration', '>', $start + 3];
        }
        if ($kind === null) {
            return null;
        }
        if (!array_key_exists($kind, $ends) || ($ends[$kind] !== false && $ends[$kind] < $minimum)) {
            $ends[$kind] = strpos($source, $terminator, $minimum);
        }

        return $ends[$kind] === false ? null : $ends[$kind] + strlen($terminator);
    }

    /**
     * @param string $source
     * @param array<string> $protected
     *
     * @return array<int, true>
     */
    private function htmlCodeUnchangedByEmphasis(string $source, array $protected): array
    {
        $restorations = [];
        $masked = '';
        $ends = [];
        for ($at = 0, $length = strlen($source); $at < $length;) {
            if ($source[$at] === '\\' && preg_match('/[!-\/:-@\[-`{-~]/', $source[$at + 1] ?? '') === 1) {
                $token = "\x00P" . count($protected) . "\x00";
                $protected[] = $restorations[$token] = substr($source, $at, 2);
                $masked .= $token;
                $at += 2;

                continue;
            }
            $end = $source[$at] === '<'
                ? $this->opaqueHtmlEnd($source, $at, $ends) ?? ($this->htmlTagAt($source, $at)['end'] ?? null)
                : null;
            if ($end === null && $source[$at] === '<' && preg_match('/\G<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[A-Za-z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*)>/', $source, $autolink, 0, $at) === 1) {
                $end = $at + strlen($autolink[0]);
            }
            if ($end === null) {
                $masked .= $source[$at++];

                continue;
            }
            $token = "\x00P" . count($protected) . "\x00";
            $protected[] = $restorations[$token] = substr($source, $at, $end - $at);
            $masked .= $token;
            $at = $end;
        }
        $converted = strtr(Emphasis::convert($masked, protectedSpans: $protected, plainText: true, strikethrough: true), $restorations);
        preg_match_all('/<code>((?:[^<\n]|<!---->)*)<\/code>/i', $source, $before, PREG_OFFSET_CAPTURE);
        preg_match_all('/<code>((?:[^<\n]|<!---->)*)<\/code>/i', $converted, $after);
        $unchanged = [];
        foreach ($before[0] as $index => [$code, $offset]) {
            if (($after[0][$index] ?? null) === $code) {
                $unchanged[$offset] = true;
            }
        }

        return $unchanged;
    }

    /**
     * @param string $source
     * @param callable(string): string $map
     */
    private function mapOpaqueHtmlFragments(string $source, callable $map): string
    {
        $out = '';
        $copied = 0;
        $ends = [];
        for ($at = 0, $length = strlen($source); $at < $length;) {
            if ($source[$at] !== '<') {
                $at++;

                continue;
            }
            $end = $this->opaqueHtmlEnd($source, $at, $ends);
            if ($end !== null) {
                $out .= substr($source, $copied, $at - $copied) . $map(substr($source, $at, $end - $at));
                $copied = $at = $end;
            } else {
                $at = $this->htmlTagAt($source, $at)['end'] ?? $at + 1;
            }
        }

        return $out . substr($source, $copied);
    }

    /**
     * @return array{end: int, name: string, closing: bool, attrs: bool}|null
     */
    public function htmlTagAt(string $source, int $start): ?array
    {
        if (preg_match('/\G<(\/?)([A-Za-z][A-Za-z0-9-]*)/', $source, $head, 0, $start) !== 1) {
            return null;
        }
        $i = $start + strlen($head[0]);
        $length = strlen($source);
        $closing = $head[1] === '/';
        $attrs = false;
        while ($i < $length) {
            $beforeSpace = $i;
            $i += strspn($source, " \t\n\r\f", $i);
            if (($source[$i] ?? '') === '>' || (!$closing && substr($source, $i, 2) === '/>')) {
                return ['end' => $i + ($source[$i] === '/' ? 2 : 1), 'name' => strtolower($head[2]), 'closing' => $closing, 'attrs' => $attrs];
            }
            if ($closing || $i === $beforeSpace || preg_match('/\G[A-Za-z_:][A-Za-z0-9_.:-]*/', $source, $attribute, 0, $i) !== 1) {
                return null;
            }
            $attrs = true;
            $i += strlen($attribute[0]);
            $afterName = $i;
            $i += strspn($source, " \t\n\r\f", $i);
            if (($source[$i] ?? '') !== '=') {
                $i = $afterName;

                continue;
            }
            $i++;
            $i += strspn($source, " \t\n\r\f", $i);
            if (($source[$i] ?? '') === '"' || ($source[$i] ?? '') === "'") {
                $end = strpos($source, $source[$i], $i + 1);
                if ($end === false || str_contains(substr($source, $i, $end - $i), "\0")) {
                    return null;
                }
                $i = $end + 1;
            } else {
                $width = strcspn($source, " \t\n\r\f\"'=<>`\0", $i);
                if ($width === 0) {
                    return null;
                }
                $i += $width;
            }
        }

        return null;
    }

    /**
     * Protect inline code spans, including multi-backtick spans.
     *
     * @param string $line
     * @param callable $replace
     */
    public function protectCodeSpans(string $line, callable $replace): string
    {
        if (!str_contains($line, '`')) {
            return $line;
        }

        $out = '';
        $i = 0;
        $opaqueEnds = [];
        $length = strlen($line);
        $nextRuns = [];
        $lastRuns = [];
        preg_match_all('/`+/', $line, $runs, PREG_OFFSET_CAPTURE);
        for ($at = count($runs[0]) - 1; $at >= 0; $at--) {
            [$run, $offset] = $runs[0][$at];
            $size = strlen($run);
            $nextRuns[$offset] = $lastRuns[$size] ?? -1;
            if ($size > 1 && $offset > 0 && $line[$offset - 1] === '\\') {
                $nextRuns[$offset + 1] = $lastRuns[$size - 1] ?? -1;
            }
            $lastRuns[$size] = $offset;
        }
        while ($i < $length) {
            if ($line[$i] === '\\' && preg_match('/[!-\/:-@\[-`{-~]/', $line[$i + 1] ?? '') === 1) {
                $out .= substr($line, $i, 2);
                $i += 2;

                continue;
            }
            if ($line[$i] === '<') {
                $opaque = $this->opaqueHtmlEnd($line, $i, $opaqueEnds);
                if ($opaque !== null) {
                    $out .= substr($line, $i, $opaque - $i);
                    $i = $opaque;

                    continue;
                }
            }
            if ($line[$i] === '<' && preg_match('/\G<(?:[A-Za-z][A-Za-z0-9+.-]{1,31}:[^<>\s]*|[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>/', $line, $autolink, 0, $i) === 1) {
                $out .= $autolink[0];
                $i += strlen($autolink[0]);

                continue;
            }
            $tag = $line[$i] === '<' ? $this->htmlTagAt($line, $i) : null;
            if ($tag !== null) {
                $out .= substr($line, $i, $tag['end'] - $i);
                $i = $tag['end'];

                continue;
            }
            if ($line[$i] !== '`') {
                $out .= $line[$i];
                $i++;

                continue;
            }

            $runLength = ($this->backtickRunLength)($line, $i);
            $closed = $nextRuns[$i] ?? -1;

            if ($closed === -1) {
                $out .= $replace(str_repeat('\\`', $runLength), $i, str_repeat('`', $runLength));
                $i += $runLength;

                continue;
            }

            $out .= $replace(substr($line, $i, $closed - $i + $runLength));
            $i = $closed + $runLength;
        }

        return $out;
    }

    /**
     * The fence `carve fmt` writes around a code body: backticks, one longer
     * than the longest backtick run in the body, three at least.
     *
     * @param array<int, string> $body
     */
    public function canonicalFence(array $body): string
    {
        $longest = 0;
        foreach ($body as $line) {
            if (preg_match_all('/`+/', $line, $runs) > 0) {
                $longest = max($longest, ...array_map('strlen', $runs[0]));
            }
        }

        return str_repeat('`', max(3, $longest + 1));
    }

    public function backtickRunLength(string $line, int $index): int
    {
        $length = strlen($line);
        $runLength = 0;
        while ($index + $runLength < $length && $line[$index + $runLength] === '`') {
            $runLength++;
        }

        return $runLength;
    }

    /**
     * Record the author's spelling for a converted destination.
     */
    public function recordAuthoredDestination(string $encoded, string $authored): void
    {
        $known = array_key_exists($encoded, $this->context->authoredDestinations) ? $this->context->authoredDestinations[$encoded] : $authored;
        $this->context->authoredDestinations[$encoded] = $known === $authored ? $authored : null;
    }
}
