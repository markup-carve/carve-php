<?php

declare(strict_types=1);

/**
 * Turns the spec data files into PHP constants. Every value in Tables.php comes from here;
 * nothing in the engine copies a value from a data file by hand.
 */
final class TableGenerator
{
    /**
     * @param string $dir
     *
     * @return string
     */
    public static function generate(string $dir): string
    {
        $load = static function (string $name) use ($dir): array {
            $data = json_decode((string)file_get_contents($dir . '/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
            assert(is_array($data));

            return $data;
        };

        $consts = [];

        self::unicodeTables($load, $consts);
        self::lineShapeTables($load, $consts);
        self::limitTables($load, $consts);
        self::taskTables($load, $consts);
        self::orderedTables($load, $consts);
        self::divTables($load, $consts);
        self::attributeTables($load, $consts);
        self::delimiterTables($load, $consts);
        self::atomTables($load, $consts);
        self::smartTables($load, $consts);
        self::slugTables($load, $consts);
        self::urlTables($load, $consts);
        self::optionTables($load, $consts);
        self::markTables($load, $consts);
        self::diagnosticTables($load, $consts);

        foreach (self::inlineTables($load) as $name => $row) {
            $consts[$name] = $row;
        }

        return self::render($consts);
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function unicodeTables(Closure $load, array &$consts): void
    {
        $categories = [];
        foreach ($load('unicode-categories')['categories'] as $category) {
            $ranges = [];
            foreach ($category['ranges'] as $range) {
                $parts = explode('-', $range);
                $ranges[] = [hexdec($parts[0]), hexdec($parts[1] ?? $parts[0])];
            }
            $categories[$category['name']] = $ranges;
        }
        $word = array_merge($categories['L'], $categories['N']);
        usort($word, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $consts['UNICODE_WORD_RANGES'] = ['C2-I-127 Unicode 18.0.0 L and N', $word];
        $consts['UNICODE_NUMBER_RANGES'] = ['C2-I-127 Unicode 18.0.0 N', $categories['N']];
        $consts['UNICODE_MARK_RANGES'] = ['C2-I-127 Unicode 18.0.0 M', $categories['M']];
        $consts['UNICODE_FORMAT_RANGES'] = ['C2-I-127 Unicode 18.0.0 Cf', $categories['Cf']];
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function lineShapeTables(Closure $load, array &$consts): void
    {
        $shapes = $load('line-shapes');
        $first = [];
        foreach ($shapes['shapes'] as $shape) {
            foreach ($shape['first'] as $spec) {
                foreach (str_split(self::expand([$spec])) as $ch) {
                    $first[$ch][] = $shape['name'];
                }
            }
        }
        ksort($first, SORT_STRING);
        $consts['FIRST_CHARACTER_SHAPES'] = ['C2-B-001 line-shapes.json: shapes keyed by first character', $first];
        foreach ($shapes['refs'] as $ref) {
            if (isset($ref['chars'])) {
                $consts['CHARS_' . strtoupper($ref['name'])] = ['line-shapes.json ref ' . $ref['name'], self::expand($ref['chars'])];
            }
            if (isset($ref['roman_chars'])) {
                $consts['ROMAN_CHARS'] = ['C2-B-036 roman letters', self::expand($ref['roman_chars'])];
            }
            if (isset($ref['values'])) {
                $consts['DIV_SIGILS'] = ['C2-B-196 div sigils', implode('', $ref['values'])];
            }
        }
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function limitTables(Closure $load, array &$consts): void
    {
        $limits = [];
        foreach ($load('limits')['limits'] as $row) {
            $limits[$row['name']] = $row['value'];
        }
        $consts['BLOCK_DEPTH'] = ['C2-B-190 limits.json block_depth', $limits['block_depth']];
        $consts['INLINE_DEPTH'] = ['C2-I-118 limits.json inline_depth', $limits['inline_depth']];
        $consts['INCLUDE_DEPTH'] = ['C2-N-064 limits.json include_depth', $limits['include_depth']];
        $consts['INCLUDE_BYTES'] = ['C2-N-065 limits.json include_bytes', $limits['include_bytes']];
        $consts['LIST_END_BLANK_LINES'] = ['C2-B-052 limits.json', $limits['list_end_blank_lines']];
        $consts['TAB_STOP'] = ['C2-B-004 limits.json', $limits['tab_stop']];
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function taskTables(Closure $load, array &$consts): void
    {
        $tasks = [];
        foreach ($load('task-states')['states'] as $row) {
            $tasks[$row['char']] = $row['mark'];
        }
        $consts['TASK_MARKS'] = ['C2-B-046 C2-B-048 task-states.json: box character to mark', $tasks];
        $checked = [];
        $stateAttr = [];
        foreach ($load('task-states')['states'] as $row) {
            $checked[$row['mark']] = $row['checked'];
            $stateAttr[$row['mark']] = $row['state_attribute'];
        }
        $consts['TASK_CHECKED'] = ['C2-H-032 task-states.json', $checked];
        $consts['TASK_STATE_ATTRIBUTE'] = ['C2-H-033 task-states.json', $stateAttr];
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function orderedTables(Closure $load, array &$consts): void
    {
        $styles = [];
        foreach ($load('ordered-styles')['styles'] as $row) {
            $styles[$row['style']] = $row['html_type'];
        }
        $consts['ORDERED_HTML_TYPE'] = ['C2-H-030 ordered-styles.json', $styles];
        $delims = [];
        foreach ($load('ordered-styles')['delimiters'] as $row) {
            $delims[$row['char']] = $row['data_delim'];
        }
        $consts['ORDERED_DATA_DELIM'] = ['C2-H-031 ordered-styles.json', $delims];
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function divTables(Closure $load, array &$consts): void
    {
        $divs = $load('div-names');
        $kinds = [];
        foreach ($divs['names'] as $row) {
            $kinds[$row['name']] = $row['kind'];
        }
        $consts['DIV_NAME_KINDS'] = ['C2-B-127 div-names.json', $kinds];
        $sigils = [];
        foreach ($divs['sigils'] as $row) {
            $sigils[$row['sigil']] = [$row['name'], $row['class'] ?? null];
        }
        $consts['DIV_SIGIL_KINDS'] = ['C2-B-196 C2-B-128 div-names.json', $sigils];
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function attributeTables(Closure $load, array &$consts): void
    {
        $attr = $load('attribute-block');
        foreach ($attr['shapes'] as $row) {
            $shape = $row['shape'];
            $name = strtoupper($row['name']);
            if (isset($shape['first'])) {
                $consts['ATTR_' . $name . '_FIRST'] = ['C2-I-030 attribute-block.json', self::expand($shape['first'])];
                $consts['ATTR_' . $name . '_REST'] = ['C2-I-030 attribute-block.json', self::expand($shape['rest'])];
            } elseif (isset($shape['chars'])) {
                $consts['ATTR_' . $name . '_CHARS'] = ['C2-I-030 attribute-block.json', self::expand($shape['chars'])];
            } elseif (isset($shape['excluded'])) {
                $consts['ATTR_' . $name . '_EXCLUDED'] = ['C2-I-032 attribute-block.json', implode('', $shape['excluded'])];
            }
        }
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function delimiterTables(Closure $load, array &$consts): void
    {
        $delimiters = $load('inline-delimiters');
        $braced = [];
        $bare = [];
        $html = [];
        foreach ($delimiters['delimiters'] as $row) {
            if ($row['form'] === 'braced') {
                $braced[$row['mark']] = $row['node'];
            } else {
                $bare[$row['mark']] = $row['node'];
            }
            $html[$row['node']] = $row['html'];
        }
        ksort($braced, SORT_STRING);
        ksort($html, SORT_STRING);
        $consts['BRACED_MARKS'] = ['C2-I-055 inline-delimiters.json: mark to node', $braced];
        $consts['BARE_DELIMITERS'] = ['C2-I-046 inline-delimiters.json', $bare];
        $consts['DELIMITER_HTML'] = ['C2-H-072 inline-delimiters.json: node to element', $html];
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function atomTables(Closure $load, array &$consts): void
    {
        $shapes = $load('line-shapes');
        $atoms = $load('inline-atoms');
        foreach ($atoms['atoms'] as $row) {
            if ($row['name'] === 'autolink') {
                $consts['AUTOLINK_SCHEME_FIRST'] = ['C2-I-073', self::expand($row['url']['scheme']['first'])];
                $consts['AUTOLINK_SCHEME_REST'] = ['C2-I-073', self::expand($row['url']['scheme']['rest'])];
                $consts['AUTOLINK_URL_CHARS'] = ['C2-I-122', self::expand($row['url']['body']['chars'])];
                $consts['AUTOLINK_EMAIL_CHARS'] = ['C2-I-123', self::expand($row['email']['local']['chars'])];
                $consts['AUTOLINK_EMAIL_LAST'] = ['C2-I-123', self::expand($row['email']['last_label']['chars'])];
            }
            if ($row['name'] === 'raw_inline') {
                // C2-I-021: the name is a raw format name (C2-B-225); inline-atoms.json still lets `_` start it.
                $raw = array_values(array_filter($shapes['refs'], static fn (array $r): bool => $r['name'] === 'raw_format'))[0];
                $rawFirst = str_replace(str_split(self::expand($raw['not_first'])), '', self::expand($raw['chars']));
                $consts['RAW_NAME_FIRST'] = ['C2-I-021, C2-B-225 line-shapes.json raw_format', $rawFirst];
                $consts['RAW_NAME_REST'] = ['C2-I-021, C2-B-225 line-shapes.json raw_format', self::expand($raw['chars'])];
            }
        }
        foreach ($load('inline-sigils')['sigils'] as $row) {
            $n = strtoupper($row['name']);
            $s = $row['name_shape'];
            if (isset($s['first'])) {
                $consts['SIGIL_' . $n . '_FIRST'] = ['C2-I-092 to C2-I-094', self::expand($s['first'])];
                $consts['SIGIL_' . $n . '_REST'] = ['C2-I-092 to C2-I-094', self::expand($s['rest'])];
            } else {
                $consts['SIGIL_' . $n . '_CHARS'] = ['C2-I-092', self::expand($s['chars'])];
            }
        }
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function smartTables(Closure $load, array &$consts): void
    {
        $smart = $load('smart-punctuation');
        $runs = [];
        foreach ($smart['runs'] as $row) {
            if ($row['replaced']) {
                $runs[$row['source']] = [$row['kind'], $row['glyph']];
            }
        }
        $consts['SMART_RUNS'] = ['C2-I-114 C2-I-115 C2-I-117 smart-punctuation.json', $runs];
        $quotes = [];
        foreach ($smart['quotes'] as $row) {
            $quotes[$row['kind']] = $row['glyph'];
        }
        foreach ($smart['dashes'] as $row) {
            $quotes[$row['kind']] = $row['glyph'];
        }
        $consts['SMART_GLYPHS'] = ['C2-I-104 C2-I-111 smart-punctuation.json', $quotes];
        $sources = [];
        foreach ([...$smart['quotes'], ...$smart['dashes'], ...$smart['runs']] as $row) {
            if (isset($row['kind'])) {
                $sources[$row['kind']] = $row['source'];
            }
        }
        $consts['SMART_SOURCES'] = ['C2-A-098 smart-punctuation.json', $sources];

        $results = [];
        foreach ($load('ast-results')['nodes'] as $type => $fields) {
            foreach ($fields as $row) {
                $results[$type][$row['field']] = $row['when'] ?? '';
            }
        }
        $consts['AST_RESULTS'] = ['C2-A-162 ast-results.json', $results];
        $consts['SMART_QUOTE_OPENS_AFTER'] = ['C2-I-104', implode('', $smart['quote_opens_after']['chars'])];
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function slugTables(Closure $load, array &$consts): void
    {
        $slug = $load('heading-slug');
        $removed = [];
        foreach ($slug['removed'] as $row) {
            $removed[] = mb_chr((int)hexdec(substr($row['code_point'], 2)), 'UTF-8');
        }
        $consts['SLUG_REMOVED'] = ['C2-H-144 heading-slug.json', $removed];
        $reversed = [];
        foreach ($slug['reversed'] as $row) {
            $reversed[$row['glyph']] = $row['ascii'];
        }
        $consts['SLUG_REVERSED'] = ['C2-H-145 heading-slug.json', $reversed];
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function urlTables(Closure $load, array &$consts): void
    {
        $url = $load('url-policy');
        $ranges = [];
        foreach ($url['probe_removes'] as $row) {
            $ranges[] = [(int)hexdec(substr($row['from'], 2)), (int)hexdec(substr($row['to'], 2))];
        }
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $consts['URL_PROBE_REMOVES'] = ['C2-H-159 url-policy.json: code point ranges', $ranges];
        $dropped = ['name' => [], 'prefix' => []];
        foreach ($url['dropped_attributes'] as $row) {
            $dropped[$row['match']][] = $row['name'];
        }
        $consts['URL_DROPPED_ATTRIBUTES'] = ['C2-H-161 url-policy.json', $dropped];
        $lists = [];
        foreach ($url['url_list_attributes'] as $row) {
            $lists[$row['name']] = in_array('comma', $row['separators'], true);
        }
        $consts['URL_LIST_ATTRIBUTES'] = ['C2-H-161 url-policy.json: name to comma-separated', $lists];
        $consts['URL_STYLE_PATTERNS'] = ['C2-H-162 url-policy.json', array_column($url['style_patterns'], 'text')];
        $profiles = [];
        foreach ($url['profiles'] as $row) {
            $name = $row['name'];
            unset($row['name'], $row['clauses'], $row['note']);
            $profiles[$name] = $row;
        }
        $consts['PROFILES'] = ['C2-H-163 url-policy.json', $profiles];
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function optionTables(Closure $load, array &$consts): void
    {
        $options = [];
        foreach ($load('options')['options'] as $row) {
            $options[$row['name']] = [
                'kind' => $row['kind'],
                'readBy' => $row['read_by'],
                'type' => $row['type'],
                'values' => $row['values'] ?? null,
                'default' => $row['default'] ?? null,
            ];
        }
        $consts['OPTIONS'] = ['C2-H-137 options.json', $options];
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function markTables(Closure $load, array &$consts): void
    {
        $marks = $load('table-marks');
        $align = [];
        foreach ($marks['alignment_marks'] as $row) {
            $align[$row['mark']] = $row['alignment'];
        }
        $consts['ALIGNMENT_MARKS'] = ['C2-B-182 table-marks.json', $align];
    }

    /**
     * @param \Closure(string): array<string, mixed> $load
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return void
     */
    private static function diagnosticTables(Closure $load, array &$consts): void
    {
        $include = $load('include-options');
        $codes = [];
        $messages = [];
        foreach ($load('lint-rules')['rules'] as $rule) {
            if (isset($rule['channel']) && $rule['channel'] !== 'lint') {
                $codes[$rule['id']] = [$rule['severity'], $rule['channel'], $rule['messages'][0] ?? $rule['id']];
            }
            if (isset($rule['messages']) && $rule['messages'] !== []) {
                $messages[$rule['id']] = $rule['messages'];
            }
        }
        ksort($codes, SORT_STRING);
        ksort($messages, SORT_STRING);
        $consts['MESSAGES'] = ['C2-A-173 lint-rules.json: the messages of each code, slots unfilled', $messages];
        $consts['DIAGNOSTICS'] = ['C2-A-173 lint-rules.json: severity, channel and first message of each parse' .
            ' and render-loss code', $codes];
        $consts['INCLUDE_OPTIONS'] = ['C2-B-205 include-options.json', self::stripClauses($include['options'] ?? $include)];
    }

    /**
     * Tables of the inline pass, kept apart so the inline work has its own section.
     *
     * @param \Closure(string): array<string, mixed> $load
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    private static function inlineTables(\Closure $load): array
    {
        $smart = $load('smart-punctuation');
        $byFirst = [];
        foreach ($smart['runs'] as $row) {
            if ($row['replaced']) {
                $byFirst[$row['source'][0]][] = $row['source'];
            }
        }
        // C2-I-115: the longest match is taken.
        foreach ($byFirst as $first => $list) {
            usort($list, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
            $byFirst[$first] = $list;
        }
        ksort($byFirst, SORT_STRING);
        $triggers = implode('', array_keys($byFirst));
        foreach ($smart['quotes'] as $row) {
            if (!str_contains($triggers, $row['source'])) {
                $triggers .= $row['source'];
            }
        }
        foreach ($smart['dashes'] as $row) {
            if (!str_contains($triggers, $row['source'][0])) {
                $triggers .= $row['source'][0];
            }
        }

        return [
            'SMART_RUNS_BY_FIRST' => [
                'C2-I-114 C2-I-115 C2-I-117 smart-punctuation.json: first character to sources, longest first',
                $byFirst,
            ],
            'SMART_TRIGGERS' => ['C2-I-104 to C2-I-117 smart-punctuation.json: characters that can start a replacement', $triggers],
        ];
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function stripClauses(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        unset($value['clauses'], $value['note'], $value['description']);
        foreach ($value as $k => $v) {
            $value[$k] = self::stripClauses($v);
        }

        return $value;
    }

    /**
     * Expands a list of single characters and ranges such as `a-z` into a byte string for strspn.
     *
     * @param array<string> $specs
     *
     * @return string
     */
    private static function expand(array $specs): string
    {
        $out = '';
        foreach ($specs as $spec) {
            if (strlen($spec) === 3 && $spec[1] === '-') {
                $out .= implode('', range($spec[0], $spec[2]));
            } else {
                $out .= $spec;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array{0: string, 1: mixed}> $consts
     *
     * @return string
     */
    private static function render(array $consts): string
    {
        $out = "<?php\n\ndeclare(strict_types=1);\n\nnamespace MarkupCarve\\Carve\\Generated;\n\n";
        $out .= "/**\n * Generated by scripts/generate-tables.php from spec/data. Do not edit.\n */\nfinal class Tables\n{\n";
        foreach ($consts as $name => [$doc, $value]) {
            $out .= "    /** {$doc} */\n";
            $out .= '    public const ' . $name . ' = ' . self::export($value, 1) . ";\n\n";
        }

        return rtrim($out) . "\n}\n";
    }

    /**
     * @param mixed $value
     * @param int $depth
     *
     * @return string
     */
    private static function export(mixed $value, int $depth): string
    {
        if (is_string($value)) {
            return self::str($value);
        }
        if (!is_array($value)) {
            return var_export($value, true) === 'NULL' ? 'null' : var_export($value, true);
        }
        if ($value === []) {
            return '[]';
        }
        $pad = str_repeat('    ', $depth + 1);
        $list = array_is_list($value);
        $out = "[\n";
        foreach ($value as $k => $v) {
            $out .= $pad . ($list ? '' : (is_int($k) ? $k : self::str((string)$k)) . ' => ') . self::export($v, $depth + 1) . ",\n";
        }

        return $out . str_repeat('    ', $depth) . ']';
    }

    /**
     * @param string $s
     *
     * @return string
     */
    private static function str(string $s): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $s) === 1) {
            return '"' . addcslashes($s, "\0..\37\"\\\$\177") . '"';
        }

        return "'" . addcslashes($s, "'\\") . "'";
    }
}
