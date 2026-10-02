<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Performance;

use MarkupCarve\Carve\Parser\LabelKey;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Conservative, allocation-light HTML facade for an unambiguous core subset.
 *
 * It is deliberately whole-document: an unsupported boundary returns null
 * before any output is published, leaving the authoritative AST pipeline in
 * charge. Text is borrowed as string slices and no public node is constructed.
 */
final class BorrowedHtmlLayout
{
    private HtmlOutput $output;

    /**
     * @var array{
     *   headingNumbers: array{minLevel: int}|null,
     *   headingPermalinks: array{symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>, showOnHover: bool, copyToClipboard: bool}|null,
     *   externalLinks: array{internalHosts: array<string>, target: string, rel: string, nofollow: bool}|null,
     *   lowercaseIds: bool,
     *   mathBlockLanguage: string|null,
     *   collectHeadings: bool
     * }
     */
    private array $events = [
        'headingNumbers' => null,
        'headingPermalinks' => null,
        'externalLinks' => null,
        'lowercaseIds' => false,
        'mathBlockLanguage' => null,
        'collectHeadings' => false,
    ];

    /**
     * @var list<array{level: int, text: string, html: string, id: string}>
     */
    private array $headings = [];

    /**
     * @var list<int>
     */
    private array $numberLevels = [];

    /**
     * @var list<int>
     */
    private array $numbers = [];

    /**
     * @var int
     */
    private const MAX_SOURCE_BYTES = 65536;

    /**
     * @param string $source
     * @param bool $observe
     * @param array{
     *   headingNumbers?: array{minLevel: int}|null,
     *   headingPermalinks?: array{symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>, showOnHover: bool, copyToClipboard: bool}|null,
     *   externalLinks?: array{internalHosts: array<string>, target: string, rel: string, nofollow: bool}|null,
     *   lowercaseIds?: bool,
     *   mathBlockLanguage?: string|null,
     *   collectHeadings?: bool
     * } $events
     * @param \MarkupCarve\Carve\Performance\HtmlOutput|null $output
     *
     * @return array{html: string, accepted: array<string, int>, headings: list<array{level: int, text: string, html: string, id: string}>}|null
     */
    public function render(string $source, bool $observe = false, array $events = [], ?HtmlOutput $output = null): ?array
    {
        $streaming = $output !== null;
        $this->output = $output ?? new HtmlOutput();
        $this->events = array_replace(
            [
                'headingNumbers' => null,
                'headingPermalinks' => null,
                'externalLinks' => null,
                'lowercaseIds' => false,
                'mathBlockLanguage' => null,
                'collectHeadings' => false,
            ],
            $events,
        );
        $this->headings = [];
        $this->numberLevels = [];
        $this->numbers = [];
        if (!$this->eligibleSource($source, $streaming)) {
            return null;
        }

        $lines = explode("\n", $source);
        if (end($lines) === '') {
            array_pop($lines);
        }
        foreach ($lines as $line) {
            if ($line !== rtrim($line, ' ')) {
                return null;
            }
        }

        $stats = $this->emptyStats();
        $definitions = $this->collectDefinitions($lines, $stats);
        if ($definitions === null) {
            return null;
        }
        $rendered = $this->renderBlocks($lines, $definitions, $stats);
        if ($rendered === null) {
            return null;
        }
        if ($rendered['wrote'] && !$rendered['endsWithoutNewline']) {
            $this->output->push("\n");
        }
        $html = $this->output->finish();

        return [
            'html' => $html,
            'accepted' => $observe ? $stats : [],
            'headings' => $this->headings,
        ];
    }

    private function eligibleSource(string $source, bool $streaming): bool
    {
        return ($streaming || strlen($source) <= self::MAX_SOURCE_BYTES)
            && preg_match('/[^\x00-\x7F]|[\x00\x09\x0B\x0C\x0D]/', $source) !== 1
            && !str_starts_with($source, '---')
            && !str_contains($source, '[^')
            && !str_contains($source, '^[')
            && !str_contains($source, '[@')
            && !str_contains($source, '</#')
            && !str_contains($source, '![')
            && !str_contains($source, '%%')
            && !str_contains($source, ':::')
            && preg_match('/(?:^|\n)( *)- [^\n]*\n\n(?:\n)*\1- /', $source) !== 1;
    }

    /**
     * @return array<string, int>
     */
    private function emptyStats(): array
    {
        return array_fill_keys([
            'headings', 'paragraphs', 'blockQuotes', 'codeFences',
            'thematicBreaks', 'unorderedListItems', 'orderedListItems',
            'tableRows', 'linkDefinitions', 'consumedLines', 'activeDefinitions',
        ], 0);
    }

    /**
     * @param array<string, int> $stats
     * @param bool $active
     * @param int $end
     * @param int $start
     * @param string $event
     */
    private function accept(array &$stats, string $event, int $start, int $end, bool $active = false): void
    {
        $stats[$event]++;
        $stats['consumedLines'] += $end - $start;
        if ($active) {
            $stats['activeDefinitions']++;
        }
    }

    /**
     * @param list<string> $lines
     * @param array<string, int> $stats
     *
     * @return array<string, array{href: string, title: ?string}>|null
     */
    private function collectDefinitions(array $lines, array &$stats): ?array
    {
        $definitions = [];
        $fence = null;
        foreach ($lines as $index => $line) {
            if ($fence !== null) {
                if ($this->isFenceClose($line, $fence)) {
                    $fence = null;
                }

                continue;
            }
            $open = $this->fenceOpen($line);
            if ($open !== null) {
                $fence = $open;

                continue;
            }
            if (!str_contains($line, ']:')) {
                continue;
            }
            if (
                preg_match('/^\[([^\]]+)\]: +(\S+?)(?: +"([^"]*)")?$/', $line, $match) !== 1
                || str_starts_with($match[1], '@')
                || ($index > 0 && trim($lines[$index - 1]) !== '')
                || (isset($lines[$index + 1]) && trim($lines[$index + 1]) !== '')
            ) {
                return null;
            }
            $definitions[LabelKey::normalize($match[1])] = ['href' => $match[2], 'title' => $match[3] ?? null];
            $this->accept($stats, 'linkDefinitions', $index, $index + 1, true);
        }

        return $definitions;
    }

    /**
     * @param list<string> $lines
     * @param array<string, array{href: string, title: ?string}> $definitions
     * @param array<string, int> $stats
     *
     * @return array{wrote: bool, endsWithoutNewline: bool}|null
     */
    private function renderBlocks(array $lines, array $definitions, array &$stats): ?array
    {
        $sections = [];
        $ids = new HeadingIdTracker();
        if ($this->events['lowercaseIds']) {
            $ids->setLowercase(true);
        }
        $i = 0;
        $wrote = false;
        $previousMath = false;
        $count = count($lines);
        while ($i < $count) {
            $line = $lines[$i];
            if (trim($line) === '' || preg_match('/^\[[^\]]+\]:/', $line) === 1) {
                $i++;

                continue;
            }
            if (preg_match('/^(#{1,6}) +(.*)$/', $line, $heading) === 1) {
                $level = strlen($heading[1]);
                $title = rtrim($heading[2]);
                if ($this->inlineComplex($title) || preg_match('/[*\/`[]/', $title) === 1) {
                    return null;
                }
                while ($sections !== [] && end($sections) >= $level) {
                    $this->output->push("\n" . $this->indent(count($sections) - 1) . '</section>');
                    array_pop($sections);
                }
                if ($wrote && !$previousMath) {
                    $this->output->push("\n");
                }
                $previousMath = false;
                $id = $ids->getIdForText($title);
                $number = null;
                if ($this->events['headingNumbers'] !== null) {
                    $number = $this->nextHeadingNumber($level, $this->events['headingNumbers']['minLevel']);
                }
                $anchor = null;
                $permalink = $this->events['headingPermalinks'];
                if ($permalink !== null && in_array($level, $permalink['levels'], true)) {
                    $anchor = '<a href="#' . $this->escapeAttribute($id)
                        . '" class="' . $this->escapeAttribute($permalink['cssClass'])
                        . '" aria-label="' . $this->escapeAttribute($permalink['ariaLabel']) . '"'
                        . ($permalink['copyToClipboard'] ? ' data-permalink-copy=""' : '')
                        . '>' . $this->escape($permalink['symbol']) . '</a>';
                    if ($permalink['showOnHover']) {
                        $anchor = '<span class="permalink-wrapper permalink-hover">' . $anchor . '</span>';
                    }
                }
                if ($this->events['collectHeadings']) {
                    $this->headings[] = [
                        'level' => $level,
                        'text' => $title,
                        'html' => $this->escape($title),
                        'id' => $id,
                    ];
                }
                $this->output->push($this->indent(count($sections)), '<section id="');
                $this->output->attr($id);
                $this->output->push('">', "\n", $this->indent(count($sections) + 1), '<h' . $level . '>');
                if ($anchor !== null && $permalink['position'] === 'before') {
                    $this->output->push($anchor, ' ');
                }
                if ($number !== null) {
                    $this->output->push('<span class="section-number">', $number, '</span> ');
                }
                $this->output->text($title);
                if ($anchor !== null && $permalink['position'] !== 'before') {
                    $this->output->push(' ', $anchor);
                }
                $this->output->push('</h' . $level . '>');
                $this->accept($stats, 'headings', $i, $i + 1);
                $sections[] = $level;
                $wrote = true;
                $i++;

                continue;
            }
            if ($wrote && !$previousMath) {
                $this->output->push("\n");
            }
            $previousMath = false;
            $depth = count($sections);
            $fence = $this->fenceOpen($line);
            if ($fence !== null) {
                if ($fence['char'] !== '`' || str_starts_with($line, ' ') || str_starts_with($line, '>')) {
                    return null;
                }
                $close = $i + 1;
                while ($close < $count && !$this->isFenceClose($lines[$close], $fence)) {
                    $close++;
                }
                if ($close >= $count) {
                    return null;
                }
                $slot = substr($line, $fence['len']);
                $info = trim($slot);
                if (str_starts_with($slot, '  ') || ($info !== '' && preg_match('/^[A-Za-z0-9-]+$/', $info) !== 1)) {
                    return null;
                }
                $math = $info === $this->events['mathBlockLanguage'];
                $this->output->push($this->indent($depth), $math ? '<div class="math display">\\[' : '<pre><code'
                    . ($info === '' ? '' : ' class="language-' . $info . '"') . '>');
                for ($j = $i + 1; $j < $close; $j++) {
                    $this->output->text($lines[$j]);
                    if (!$math || $j + 1 < $close) {
                        $this->output->push("\n");
                    }
                }
                $this->output->push($math ? '\\]</div>' : '</code></pre>');
                $previousMath = $math;
                $this->accept($stats, 'codeFences', $i, $close + 1);
                $i = $close + 1;
                $wrote = true;

                continue;
            }
            if (str_starts_with($line, '- ')) {
                $rendered = $this->renderList($lines, $i, 0, $depth, $definitions, $stats);
                if ($rendered === null) {
                    return null;
                }
                $i = $rendered['next'];
                $wrote = true;

                continue;
            }
            if ($this->thematicBreak($line)) {
                $this->output->push($this->indent($depth) . '<hr>');
                $this->accept($stats, 'thematicBreaks', $i, $i + 1);
                $i++;
                $wrote = true;

                continue;
            }
            if ($this->decimalListItem($line) !== null) {
                $rendered = $this->renderOrderedList($lines, $i, $depth, $definitions, $stats);
                if ($rendered === null) {
                    return null;
                }
                $i = $rendered['next'];
                $wrote = true;

                continue;
            }
            if (str_starts_with($line, '> ')) {
                $start = $i;
                $this->output->push($this->indent($depth), '<blockquote><p>');
                while (isset($lines[$i]) && str_starts_with($lines[$i], '> ')) {
                    $text = substr($lines[$i], 2);
                    if ($i > $start) {
                        $this->output->push("\n");
                    }
                    if ($this->blockish($text) || $this->renderInline($text, $definitions) === null) {
                        return null;
                    }
                    $i++;
                }
                if (isset($lines[$i]) && trim($lines[$i]) !== '') {
                    return null;
                }
                $this->output->push('</p></blockquote>');
                $this->accept($stats, 'blockQuotes', $start, $i);
                $wrote = true;

                continue;
            }
            if (str_starts_with($line, '|')) {
                $rendered = $this->renderTable($lines, $i, $depth, $definitions, $stats);
                if ($rendered === null) {
                    return null;
                }
                $i = $rendered['next'];
                $wrote = true;

                continue;
            }
            if ($this->blockish($line)) {
                return null;
            }
            $start = $i;
            $this->output->push($this->indent($depth), '<p>');
            while (isset($lines[$i]) && trim($lines[$i]) !== '') {
                if ($this->blockish($lines[$i])) {
                    return null;
                }
                if ($i > $start) {
                    $this->output->push("\n");
                }
                if ($this->renderInline($lines[$i], $definitions) === null) {
                    return null;
                }
                $i++;
            }
            $this->output->push('</p>');
            $this->accept($stats, 'paragraphs', $start, $i);
            $wrote = true;
        }
        $hadOpenSections = $sections !== [];
        while ($sections !== []) {
            $this->output->push("\n" . $this->indent(count($sections) - 1) . '</section>');
            array_pop($sections);
        }

        return [
            'wrote' => $wrote,
            'endsWithoutNewline' => $previousMath && !$hadOpenSections,
        ];
    }

    /**
     * @param string $text
     * @param array<string, array{href: string, title: ?string}> $definitions
     */
    private function renderInline(string $text, array $definitions): ?bool
    {
        if ($this->inlineComplex($text)) {
            return null;
        }
        $plain = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length;) {
            $delimiter = $text[$i];
            if (!str_contains('*/`[', $delimiter)) {
                $i += strcspn($text, '*/`[', $i);

                continue;
            }
            $this->output->text(substr($text, $plain, $i - $plain));
            if ($delimiter === '*' || $delimiter === '/') {
                $close = strpos($text, $delimiter, $i + 1);
                if (
                    $close === false || $close <= $i + 1 || ctype_space($text[$i + 1])
                    || ctype_space($text[$close - 1])
                    // `bare_opener` (CARVE-P3-013) refuses a marker preceded by
                    // the same marker, so the second run of `/x//y/` is text.
                    || ($i > 0 && (ctype_alnum($text[$i - 1]) || $text[$i - 1] === $delimiter))
                    || (isset($text[$close + 1]) && ctype_alnum($text[$close + 1]))
                ) {
                    return null;
                }
                $tag = $delimiter === '*' ? 'strong' : 'em';
                $this->output->push('<' . $tag . '>');
                if ($this->renderInline(substr($text, $i + 1, $close - $i - 1), $definitions) === null) {
                    return null;
                }
                $this->output->push('</' . $tag . '>');
                $i = $close + 1;
            } elseif ($delimiter === '`') {
                $close = strpos($text, '`', $i + 1);
                if ($close === false) {
                    return null;
                }
                $code = substr($text, $i + 1, $close - $i - 1);
                if ($code !== trim($code)) {
                    return null;
                }
                $this->output->push('<code>');
                $this->output->text($code);
                $this->output->push('</code>');
                $i = $close + 1;
            } else {
                $labelEnd = strpos($text, ']', $i + 1);
                if ($labelEnd === false) {
                    return null;
                }
                $label = substr($text, $i + 1, $labelEnd - $i - 1);
                $title = null;
                if (($text[$labelEnd + 1] ?? '') === '(') {
                    $close = strpos($text, ')', $labelEnd + 2);
                    if ($close === false) {
                        return null;
                    }
                    $href = substr($text, $labelEnd + 2, $close - $labelEnd - 2);
                    if ($href === '' || preg_match('/[\s(]/', $href) === 1) {
                        return null;
                    }
                    $i = $close + 1;
                } elseif (($text[$labelEnd + 1] ?? '') === '[') {
                    $close = strpos($text, ']', $labelEnd + 2);
                    if ($close === false) {
                        return null;
                    }
                    $key = LabelKey::normalize(substr($text, $labelEnd + 2, $close - $labelEnd - 2));
                    if (!isset($definitions[$key])) {
                        return null;
                    }
                    $href = $definitions[$key]['href'];
                    $title = $definitions[$key]['title'];
                    $i = $close + 1;
                } else {
                    return null;
                }
                if (!$this->safeUrl($href)) {
                    return null;
                }
                $this->output->push('<a href="');
                $this->output->attr($href);
                $this->output->push('"');
                if ($title !== null) {
                    $this->output->push(' title="');
                    $this->output->attr($title);
                    $this->output->push('"');
                }
                $this->output->push($this->externalLinkAttributes($href), '>');
                if ($this->renderInline($label, $definitions) === null) {
                    return null;
                }
                $this->output->push('</a>');
            }
            $plain = $i;
        }

        $this->output->text(substr($text, $plain));

        return true;
    }

    /**
     * @param list<string> $lines
     * @param int $depth
     * @param int $offset
     * @param int $start
     * @param array<string, array{href: string, title: ?string}> $definitions
     * @param array<string, int> $stats
     *
     * @return array{next: int}|null
     */
    private function renderList(array $lines, int $start, int $offset, int $depth, array $definitions, array &$stats): ?array
    {
        $this->output->push($this->indent($depth), '<ul>');
        $i = $start;
        $count = count($lines);
        while ($i < $count) {
            $line = $lines[$i];
            $leading = strlen($line) - strlen(ltrim($line));
            if ($leading < $offset) {
                break;
            }
            if ($leading !== $offset || !str_starts_with(substr($line, $leading), '- ')) {
                return null;
            }
            $text = substr($line, $leading + 2);
            if ($text === '' || $text === '+' || str_starts_with($text, ' ') || $this->blockish($text)) {
                return null;
            }
            $this->accept($stats, 'unorderedListItems', $i, $i + 1);
            $this->output->push("\n", $this->indent($depth + 1), '<li>');
            if ($this->renderInline($text, $definitions) === null) {
                return null;
            }
            $i++;
            if (isset($lines[$i])) {
                $nextIndent = strlen($lines[$i]) - strlen(ltrim($lines[$i]));
                if ($nextIndent > $offset) {
                    if ($nextIndent !== $offset + 2 || !str_starts_with(substr($lines[$i], $nextIndent), '- ')) {
                        return null;
                    }
                    $this->output->push("\n");
                    $nested = $this->renderList($lines, $i, $offset + 2, $depth + 2, $definitions, $stats);
                    if ($nested === null) {
                        return null;
                    }
                    $this->output->push("\n", $this->indent($depth + 1));
                    $i = $nested['next'];
                }
            }
            $this->output->push('</li>');
            if (isset($lines[$i]) && trim($lines[$i]) === '') {
                for ($next = $i + 1; isset($lines[$next]) && trim($lines[$next]) === ''; $next++) {
                }
                if (isset($lines[$next])) {
                    $nextLeading = strlen($lines[$next]) - strlen(ltrim($lines[$next]));
                    if ($nextLeading === $offset && str_starts_with(substr($lines[$next], $nextLeading), '- ')) {
                        return null;
                    }
                }

                break;
            }
        }
        $this->output->push("\n" . $this->indent($depth) . '</ul>');

        return ['next' => $i];
    }

    /**
     * @param list<string> $lines
     * @param int $depth
     * @param int $start
     * @param array<string, array{href: string, title: ?string}> $definitions
     * @param array<string, int> $stats
     *
     * @return array{next: int}|null
     */
    private function renderOrderedList(array $lines, int $start, int $depth, array $definitions, array &$stats): ?array
    {
        $first = $this->decimalListItem($lines[$start]);
        if ($first === null) {
            return null;
        }
        $this->output->push($this->indent($depth) . '<ol' . ($first['number'] === 1 ? '' : ' start="' . $first['number'] . '"') . '>');
        $i = $start;
        $expected = $first['number'];
        while (isset($lines[$i]) && ($item = $this->decimalListItem($lines[$i])) !== null) {
            // A lone `+` opens a first-block item, as in the bullet list above.
            if ($item['number'] !== $expected || $item['text'] === '+' || $this->blockish($item['text'])) {
                return null;
            }
            $this->output->push("\n", $this->indent($depth + 1), '<li>');
            if ($this->renderInline($item['text'], $definitions) === null) {
                return null;
            }
            $this->output->push('</li>');
            $this->accept($stats, 'orderedListItems', $i, $i + 1);
            $expected++;
            $i++;
        }
        if (isset($lines[$i]) && trim($lines[$i]) === '') {
            for ($next = $i + 1; isset($lines[$next]) && trim($lines[$next]) === ''; $next++) {
            }
            if (isset($lines[$next]) && $this->decimalListItem($lines[$next]) !== null) {
                return null;
            }
        }
        if (isset($lines[$i]) && trim($lines[$i]) !== '') {
            return null;
        }
        $this->output->push("\n" . $this->indent($depth) . '</ol>');

        return ['next' => $i];
    }

    /**
     * @param list<string> $lines
     * @param int $depth
     * @param int $start
     * @param array<string, array{href: string, title: ?string}> $definitions
     * @param array<string, int> $stats
     *
     * @return array{next: int}|null
     */
    private function renderTable(array $lines, int $start, int $depth, array $definitions, array &$stats): ?array
    {
        $heads = $this->cells($lines[$start]);
        $delimiter = $this->cells($lines[$start + 1] ?? '');
        if ($heads === null || $delimiter === null || $heads === [] || count($heads) !== count($delimiter)) {
            return null;
        }
        if (in_array('^', $heads, true) || in_array('<', $heads, true)) {
            return null;
        }
        $aligns = [];
        foreach ($delimiter as $cell) {
            $align = $this->alignment($cell);
            if ($align === false) {
                return null;
            }
            $aligns[] = $align;
        }
        $renderRow = function (array $cells, string $tag) use ($definitions, $aligns): ?bool {
            foreach ($cells as $index => $cell) {
                $this->output->push('<' . $tag . ($tag === 'th' ? ' scope="col"' : '')
                    . ($aligns[$index] === null ? '' : ' style="text-align: ' . $aligns[$index] . ';"') . '>');
                if ($this->renderInline($cell, $definitions) === null) {
                    return null;
                }
                $this->output->push('</' . $tag . '>');
            }

            return true;
        };
        $this->output->push(
            $this->indent($depth),
            '<table>',
            "\n",
            $this->indent($depth + 1),
            '<thead>',
            "\n",
            $this->indent($depth + 2),
            '<tr>',
        );
        if ($renderRow($heads, 'th') === null) {
            return null;
        }
        $this->output->push(
            '</tr>',
            "\n",
            $this->indent($depth + 1),
            '</thead>',
            "\n",
            $this->indent($depth + 1),
            '<tbody>',
        );
        $this->accept($stats, 'tableRows', $start, $start + 2);
        $i = $start + 2;
        $rows = 0;
        while (isset($lines[$i]) && str_starts_with(ltrim($lines[$i]), '|')) {
            $row = $this->cells($lines[$i]);
            if ($row === null || count($row) !== count($heads)) {
                return null;
            }
            if (in_array('^', $row, true) || in_array('<', $row, true)) {
                return null;
            }
            $this->output->push("\n", $this->indent($depth + 2), '<tr>');
            $rendered = $renderRow($row, 'td');
            if ($rendered === null) {
                return null;
            }
            $this->output->push('</tr>');
            $rows++;
            $this->accept($stats, 'tableRows', $i, $i + 1);
            $i++;
        }
        if ($rows === 0) {
            return null;
        }
        $this->output->push("\n" . $this->indent($depth + 1) . '</tbody>' . "\n" . $this->indent($depth) . '</table>');

        return ['next' => $i];
    }

    /**
     * @return list<string>|null
     */
    private function cells(string $line): ?array
    {
        $trimmed = trim($line);
        if (!str_starts_with($trimmed, '|') || !str_ends_with($trimmed, '|') || str_contains($trimmed, '\\|')) {
            return null;
        }

        return array_map('trim', explode('|', substr($trimmed, 1, -1)));
    }

    private function alignment(string $cell): string|false|null
    {
        $left = str_starts_with($cell, ':');
        $right = str_ends_with($cell, ':');
        $core = trim($cell, ': ');
        if (strlen($core) < 3 || preg_match('/^-+$/', $core) !== 1) {
            return false;
        }

        return $left && $right ? 'center' : ($right ? 'right' : ($left ? 'left' : null));
    }

    /**
     * @return array{number: int, text: string}|null
     */
    private function decimalListItem(string $line): ?array
    {
        if (preg_match('/^(\d+)\. ([^ ].*)$/', $line, $match) !== 1) {
            return null;
        }
        $number = (int)$match[1];

        return $number > 0 ? ['number' => $number, 'text' => $match[2]] : null;
    }

    private function inlineComplex(string $text): bool
    {
        return preg_match('/[{}^\\\\<>_~!@$=#\'\"]|--|\.\.\.|\/\*|\*\/|``|\+\-|\(c\)|\(r\)|\(tm\)/', $text) === 1
            || substr_count($text, ':') >= 2;
    }

    private function blockish(string $text): bool
    {
        return preg_match('/^(?:\s|#|\* |\+ |- |>|\||\{|:::|```|~~~|\.{1,9} |[A-Za-z0-9]+[.)] |---+|\*\*\*+)$/', $text) === 1
            || preg_match('/^(?:\s|#|\* |\+ |- |>|\||\{|:::|```|~~~|\.{1,9} |[A-Za-z0-9]+[.)] )/', $text) === 1;
    }

    private function thematicBreak(string $line): bool
    {
        return preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/', $line) === 1;
    }

    /**
     * @return array{char: string, len: int}|null
     */
    private function fenceOpen(string $line): ?array
    {
        if (preg_match('/^(`{3,}|~{3,})/', $line, $match) !== 1) {
            return null;
        }

        return ['char' => $match[1][0], 'len' => strlen($match[1])];
    }

    /**
     * @param string $line
     * @param array{char: string, len: int} $fence
     */
    private function isFenceClose(string $line, array $fence): bool
    {
        return preg_match('/^' . preg_quote($fence['char'], '/') . '{' . $fence['len'] . ',}\s*$/', $line) === 1;
    }

    private function safeUrl(string $url): bool
    {
        return preg_match('/^(?:https?:|mailto:|\/|#|\.\/|\.\.\/)/i', $url) === 1;
    }

    private function nextHeadingNumber(int $level, int $minLevel): ?string
    {
        if ($level < $minLevel) {
            return null;
        }
        $depth = count($this->numberLevels);
        while ($depth > 0 && $this->numberLevels[$depth - 1] > $level) {
            array_pop($this->numberLevels);
            array_pop($this->numbers);
            $depth--;
        }
        if ($depth > 0 && $this->numberLevels[$depth - 1] === $level) {
            $number = array_pop($this->numbers);
            $this->numbers[] = ($number ?? 0) + 1;
        } else {
            $this->numberLevels[] = $level;
            $this->numbers[] = 1;
        }

        return implode('.', $this->numbers);
    }

    private function externalLinkAttributes(string $href): string
    {
        $external = $this->events['externalLinks'];
        if ($external === null || preg_match('#^https?://#i', $href) !== 1) {
            return '';
        }
        $host = parse_url($href, PHP_URL_HOST);
        if (!is_string($host)) {
            return '';
        }
        foreach ($external['internalHosts'] as $internalHost) {
            if (strtolower($internalHost) === strtolower($host)) {
                return '';
            }
        }
        $rel = $external['rel'];
        if ($external['nofollow'] && !str_contains($rel, 'nofollow')) {
            $rel .= ' nofollow';
        }

        $target = $external['target'] === ''
            ? ''
            : ' target="' . $this->escapeAttribute($external['target']) . '"';

        return $target . ' rel="' . $this->escapeAttribute(trim($rel)) . '"';
    }

    private function escape(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        return str_replace("\u{00A0}", '&nbsp;', $escaped);
    }

    private function escapeAttribute(string $text): string
    {
        return StringUtil::escapeHtml($text);
    }

    private function indent(int $depth): string
    {
        return str_repeat('  ', $depth);
    }
}
