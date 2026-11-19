<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use Closure;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\Standard;
use MarkupCarve\Carve\Generated\Tables;

/**
 * Validated options of options.json (C2-H-137). Every option of the table is accepted, also the ones
 * that only the formatter or the linter read; an unknown name or a bad value throws OptionError.
 */
final class Options
{
    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    public string $headingIds = 'keep-case';

    public string $mode = 'interactive';

    public string $profile = 'full';

    public bool $rawHtml = false;

    /**
     * @var array<\MarkupCarve\Carve\Extension\ExtensionDefinition> C2-X-002, C2-X-033.
     */
    public array $extensions = [];

    /**
     * @var array<string, \Closure(\MarkupCarve\Carve\Node, \MarkupCarve\Carve\Extension\RenderHost): string> The
     *   registered renderers by name and version (C2-X-033).
     */
    public array $rendered = [];

    /**
     * @var array<string, \Closure|string|array{standard: array{mode: string}}>
     */
    private array $rendererOptions = [];

    public bool $roundtrip = false;

    /**
     * C2-H-075: the semantic span keys from inside out, with what a registered extension sets.
     *
     * @var array<array{0: string, 1: string|null}>
     */
    public array $semanticKeys = self::SEMANTIC_KEYS;

    /**
     * The quote glyphs of a registered extension, or null for those of C2-H-078.
     *
     * @var array{0: string, 1: string, 2: string, 3: string}|null
     */
    public ?array $quotes = null;

    /**
     * @var array<array{0: string, 1: string|null}>
     */
    public const SEMANTIC_KEYS = [['abbr', 'title'], ['time', 'datetime'], ['kbd', null]];

    public bool $sections = true;

    public bool $smartTypography = true;

    public bool $sourceLines = false;

    public bool $strictLosses = false;

    /**
     * @var array<string, string>
     */
    public array $labels = [];

    /**
     * @var array<string, string>
     */
    public array $symbols = [];

    /**
     * Deny list or allow list of lowercased schemes (C2-H-128).
     *
     * @var array<string, true>
     */
    public array $schemes = [];

    public bool $allowList = false;

    public ?string $mentionUrl = null;

    public ?string $tagUrl = null;

    public ?Closure $resolveMention = null;

    public ?Closure $resolveTag = null;

    public mixed $socialContext = null;

    public bool $socialHasContext = false;

    public ?Closure $includeResolver = null;

    public ?string $includeFile = null;

    public int $includeDepth = Tables::INCLUDE_DEPTH;

    public int $includeBytes = Tables::INCLUDE_BYTES;

    public int $includeDiagnostics = Tables::INCLUDE_DIAGNOSTICS;

    public string $softBreak = 'newline';

    /**
     * C2-H-204: the allowed render-loss codes.
     *
     * @var array<string, true>
     */
    public array $allowLosses = [];

    public int $maxLosses = 100;

    /**
     * C2-H-206: host HTML for a code block, or null to keep the default.
     *
     * @var \Closure(\MarkupCarve\Carve\Node, string): mixed|null
     */
    public ?Closure $codeBlockRenderer = null;

    /**
     * @param array<string, mixed> $options
     * @param array<string>|null $stages
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return self
     */
    public static function from(array $options, ?array $stages = null): self
    {
        $o = new self();
        foreach (Tables::OPTIONS as $name => $spec) {
            $o->values[$name] = $spec['default'];
        }
        foreach (Tables::OPTIONS['labels']['default'] as $k => $v) {
            $o->labels[$k] = $v;
        }
        $o->setUrlPolicy([]);
        foreach ($options as $name => $value) {
            $reads = Tables::OPTIONS[$name]['readBy'] ?? null;
            if ($stages !== null && $reads !== null && array_intersect((array)$reads, $stages) === []) {
                throw new OptionError('option ' . $name . ' is not read by this call');
            }
            $o->set((string)$name, $value);
        }
        // Choose the channel after all options are read, independent of map order.
        foreach ($o->extensions as $extension) {
            $renderer = $o->rendererOptions[$extension->name] ?? null;
            if ($renderer === 'standard' || is_array($renderer)) {
                $tabsMode = is_array($renderer) ? $renderer['standard']['mode'] : 'css';
                $renderer = Standard::renderer($extension->name, $o->mode, $tabsMode);
            }
            $renderer ??= $o->mode === 'static' ? $extension->buildTimeRenderer : $extension->renderer;
            if ($renderer instanceof Closure) {
                $o->rendered[$extension->name . "\0" . $extension->version] = $renderer;
            }
        }

        return $o;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    public function label(string $key): string
    {
        return $this->labels[$key] ?? '';
    }

    /**
     * @param string $name
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return void
     */
    private function set(string $name, mixed $value): void
    {
        if (!isset(Tables::OPTIONS[$name])) {
            throw new OptionError('unknown option ' . $name);
        }
        $spec = Tables::OPTIONS[$name];
        if ($spec['type'] === 'boolean') {
            if (!is_bool($value)) {
                throw new OptionError('option ' . $name . ': not a boolean');
            }
            $this->values[$name] = $value;
            $this->setBoolean($name, $value);

            return;
        }
        if ($spec['type'] === 'enum') {
            if (!is_string($value) || !in_array($value, (array)$spec['values'], true)) {
                throw new OptionError('option ' . $name . ': bad value');
            }
            $this->values[$name] = $value;
            $this->setEnum($name, $value);

            return;
        }
        $this->values[$name] = $value;
        match ($name) {
            'labels' => $this->labels = array_merge($this->labels, self::stringMap($name, $value)),
            'symbols' => $this->symbols = self::stringMap($name, $value),
            'rules' => self::rules($value),
            'renderers' => $this->setRenderers($value),
            'urlPolicy' => $this->setUrlPolicy($value),
            'social' => $this->setSocial($value),
            'extensions' => $this->setExtensions($value),
            'platforms' => self::platforms($value),
            default => $this->setRenderValue($name, $value),
        };
    }

    /**
     * @param string $name
     * @param mixed $value
     *
     * @return void
     */
    private function setRenderValue(string $name, mixed $value): void
    {
        match ($name) {
            'allowLosses' => $this->setAllowLosses($value),
            'maxLosses' => $this->setMaxLosses($value),
            'codeBlockRenderer' => $this->setCodeBlockRenderer($value),
            default => $this->includes($value),
        };
    }

    /**
     * @param string $name
     * @param bool $value
     *
     * @return void
     */
    private function setBoolean(string $name, bool $value): void
    {
        match ($name) {
            'rawHtml' => $this->rawHtml = $value,
            'roundtrip' => $this->roundtrip = $value,
            'sections' => $this->sections = $value,
            'smartTypography' => $this->smartTypography = $value,
            'sourceLines' => $this->sourceLines = $value,
            'strictLosses' => $this->strictLosses = $value,
            default => null,
        };
    }

    /**
     * @param string $name
     * @param string $value
     *
     * @return void
     */
    private function setEnum(string $name, string $value): void
    {
        match ($name) {
            'headingIds' => $this->headingIds = $value,
            'mode' => $this->mode = $value,
            'softBreak' => $this->softBreak = $value,
            default => $this->profile = $value,
        };
    }

    /**
     * @param string $name
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return array<string, string>
     */
    private static function stringMap(string $name, mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new OptionError('option ' . $name . ': not a map');
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (!is_string($v)) {
                throw new OptionError('option ' . $name . ': not a map of strings');
            }
            $out[(string)$k] = $v;
        }

        return $out;
    }

    /**
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return void
     */
    private static function rules(mixed $value): void
    {
        if (!is_array($value)) {
            throw new OptionError('option rules: not a map');
        }
        foreach ($value as $v) {
            if (!is_bool($v) && $v !== 'on' && $v !== 'off') {
                throw new OptionError('option rules: a rule is on or off');
            }
        }
    }

    /**
     * C2-H-204: every entry is a code of the render-loss channel.
     *
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return void
     */
    private function setAllowLosses(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new OptionError('option allowLosses: not a list');
        }
        $this->allowLosses = [];
        foreach ($value as $code) {
            if (!is_string($code) || (Tables::DIAGNOSTICS[$code][1] ?? null) !== 'render-loss') {
                throw new OptionError('option allowLosses: ' . json_encode($code) . ' is no render-loss code');
            }
            $this->allowLosses[$code] = true;
        }
    }

    /**
     * C2-H-205: a count.
     *
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return void
     */
    private function setMaxLosses(mixed $value): void
    {
        if (!is_int($value) || $value < 0) {
            throw new OptionError('option maxLosses: not a count');
        }
        $this->maxLosses = $value;
    }

    /**
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return void
     */
    private function setCodeBlockRenderer(mixed $value): void
    {
        if ($value !== null && !$value instanceof Closure) {
            throw new OptionError('option codeBlockRenderer: not a function');
        }
        $this->codeBlockRenderer = $value;
    }

    /**
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     */
    private function setRenderers(mixed $value): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new OptionError('option renderers: not a map');
        }
        $validated = [];
        foreach ($value as $name => $renderer) {
            if (!is_string($name)) {
                throw new OptionError('option renderers: invalid renderer name');
            }
            if ($renderer instanceof Closure) {
                $validated[$name] = $renderer;

                continue;
            }
            if ($renderer === 'standard' && Standard::renderer($name, $this->mode) !== null) {
                $validated[$name] = $renderer;

                continue;
            }
            if (
                $name !== 'carve.tabs' || !is_array($renderer) || array_keys($renderer) !== ['standard']
                || !is_array($renderer['standard']) || array_keys($renderer['standard']) !== ['mode']
                || !is_string($renderer['standard']['mode'])
                || !in_array($renderer['standard']['mode'], ['css', 'aria'], true)
            ) {
                throw new OptionError('option renderers: invalid renderer ' . (string)$name);
            }
            $validated[$name] = $renderer;
        }
        $this->rendererOptions = $validated;
    }

    /**
     * C2-H-128, C2-H-137: a deny list or an allow list, never both; an empty object is the default.
     *
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return void
     */
    private function setUrlPolicy(mixed $value): void
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new OptionError('option urlPolicy: not an object');
        }
        foreach (array_keys($value) as $key) {
            if ($key !== 'deny' && $key !== 'allow') {
                throw new OptionError('option urlPolicy: unknown field ' . $key);
            }
        }
        if (isset($value['deny'], $value['allow'])) {
            throw new OptionError('option urlPolicy: both a deny list and an allow list');
        }
        $this->allowList = isset($value['allow']);
        $list = $value['allow'] ?? $value['deny'] ?? null;
        if ($list === null) {
            $list = Tables::OPTIONS['urlPolicy']['default']['deny'];
        }
        if (!is_array($list) || !array_is_list($list)) {
            throw new OptionError('option urlPolicy: not a list of strings');
        }
        $this->schemes = [];
        foreach ($list as $scheme) {
            if (!is_string($scheme)) {
                throw new OptionError('option urlPolicy: not a list of strings');
            }
            $this->schemes[strtolower($scheme)] = true;
        }
    }

    /**
     * C2-H-126. The spec names no shape (G-render-1); this follows the reference engine:
     * `mentionUrl` and `tagUrl` templates with `{name}`, `resolveMention` and `resolveTag` closures.
     *
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return void
     */
    private function setSocial(mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new OptionError('option social: not an object');
        }
        foreach ($value as $key => $v) {
            if ($key === 'mentionUrl' || $key === 'tagUrl') {
                if (!is_string($v)) {
                    throw new OptionError('option social: ' . $key . ' is not a string');
                }
                if (preg_match_all('/\{[A-Za-z0-9_]+\}/', $v, $matches) > 0) {
                    foreach ($matches[0] as $placeholder) {
                        if ($placeholder !== '{name}') {
                            throw new OptionError('option social: unsupported placeholder ' . $placeholder);
                        }
                    }
                }
                $key === 'mentionUrl' ? $this->mentionUrl = $v : $this->tagUrl = $v;

                continue;
            }
            if ($key === 'context') {
                $this->socialContext = $v;
                $this->socialHasContext = true;

                continue;
            }
            if ($key === 'resolveMention' || $key === 'resolveTag') {
                if (!$v instanceof Closure) {
                    throw new OptionError('option social: ' . $key . ' is not a function');
                }
                $key === 'resolveMention' ? $this->resolveMention = $v : $this->resolveTag = $v;

                continue;
            }

            throw new OptionError('option social: unknown field ' . $key);
        }
    }

    /**
     * The provisional semantic keys a definition carries for the host: lowercase names, each once, the core three among
     * them.
     *
     * @throws \MarkupCarve\Carve\OptionError
     */
    private static function checkHostFields(ExtensionDefinition $item): void
    {
        if ($item->semanticKeys === null) {
            return;
        }
        $names = [];
        foreach ($item->semanticKeys as [$key, $target]) {
            if (
                preg_match('/^[a-z]+$/', $key) !== 1 || isset($names[$key])
                || ($target !== null && preg_match('/^[a-z-]+$/', $target) !== 1)
            ) {
                throw new OptionError('option extensions: invalid semantic keys');
            }
            $names[$key] = true;
        }
        if (!isset($names['abbr'], $names['time'], $names['kbd'])) {
            throw new OptionError('option extensions: invalid semantic keys');
        }
    }

    /**
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return void
     */
    private function setExtensions(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new OptionError('option extensions: not a list');
        }
        $expanded = [];
        $this->semanticKeys = self::SEMANTIC_KEYS;
        $this->quotes = null;
        foreach ($value as $item) {
            if (is_string($item)) {
                $standard = Standard::definitions($item);
                if ($standard === null) {
                    throw new OptionError('option extensions: an invalid extension registration');
                }
                array_push($expanded, ...$standard);
            } else {
                $expanded[] = $item;
            }
        }
        $names = [];
        $claims = [];
        foreach ($expanded as $item) {
            if (!$item instanceof ExtensionDefinition) {
                throw new OptionError('option extensions: an invalid extension registration');
            }
            if (isset($names[$item->name])) {
                throw new OptionError('option extensions: duplicate name ' . $item->name);
            }
            $names[$item->name] = true;
            self::checkHostFields($item);
            if ($item->semanticKeys !== null) {
                $this->semanticKeys = $item->semanticKeys;
            }
            if ($item->quotes !== null) {
                $this->quotes = $item->quotes;
            }
            // C2-X-014: two enabled matchers cannot claim one core node.
            $claim = $item->matcher === null ? null : $item->matcher['form'] . ':' . $item->matcher['name'];
            if ($claim !== null && isset($claims[$claim])) {
                throw new OptionError('option extensions: duplicate claim ' . $claim);
            }
            $claims[(string)$claim] = true;
        }
        $this->extensions = $expanded;
    }

    /**
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return void
     */
    private static function platforms(mixed $value): void
    {
        $allowed = Tables::OPTIONS['platforms']['values'];
        if (!is_array($value) || !array_is_list($value)) {
            throw new OptionError('option platforms: not a list');
        }
        foreach ($value as $item) {
            if (!in_array($item, (array)$allowed, true)) {
                throw new OptionError('option platforms: unknown platform');
            }
        }
    }

    /**
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve\OptionError
     *
     * @return void
     */
    private function includes(mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if ($value instanceof Closure) {
            $this->includeResolver = $value;

            return;
        }
        if (!is_array($value) || !($value['resolver'] ?? null) instanceof Closure) {
            throw new OptionError('option includes: invalid resolver');
        }
        $this->includeResolver = $value['resolver'];
        foreach ($value as $key => $item) {
            if ($key === 'resolver') {
                continue;
            }
            if ($key === 'file' && ($item === null || is_string($item))) {
                $this->includeFile = $item;

                continue;
            }
            if ($this->includeLimit((string)$key, $item)) {
                continue;
            }

            throw new OptionError('option includes: invalid field ' . $key);
        }
    }

    /**
     * C2-N-064, C2-N-065, C2-T-024: a host may lower a limit and may not raise it.
     *
     * @param string $key
     * @param mixed $item
     *
     * @return bool Whether `$key` is a limit and `$item` a value it takes.
     */
    private function includeLimit(string $key, mixed $item): bool
    {
        $limit = match ($key) {
            'depth' => Tables::INCLUDE_DEPTH,
            'bytes' => Tables::INCLUDE_BYTES,
            'maxDiagnostics' => Tables::INCLUDE_DIAGNOSTICS,
            default => null,
        };
        if ($limit === null || !is_int($item) || $item < 0 || $item > $limit) {
            return false;
        }
        match ($key) {
            'depth' => $this->includeDepth = $item,
            'bytes' => $this->includeBytes = $item,
            default => $this->includeDiagnostics = $item,
        };

        return true;
    }
}
