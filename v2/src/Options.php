<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

use Closure;
use MarkupCarve\Carve2\Extension\ExtensionDefinition;
use MarkupCarve\Carve2\Generated\Tables;

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
     * @var array<\MarkupCarve\Carve2\Extension\ExtensionDefinition> C2-X-002, C2-X-033.
     */
    public array $extensions = [];

    /**
     * @var array<string, \Closure(\MarkupCarve\Carve2\Node, \MarkupCarve\Carve2\Extension\RenderHost): string> The
     *   registered renderers by name and version (C2-X-033).
     */
    public array $rendered = [];

    public bool $roundtrip = false;

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

    /**
     * @param array<string, mixed> $options
     * @param array<string>|null $stages
     *
     * @throws \MarkupCarve\Carve2\OptionError
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
     * @throws \MarkupCarve\Carve2\OptionError
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
            'renderers' => self::renderers($value),
            'urlPolicy' => $this->setUrlPolicy($value),
            'social' => $this->setSocial($value),
            'extensions' => $this->setExtensions($value),
            'platforms' => self::platforms($value),
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
            default => $this->profile = $value,
        };
    }

    /**
     * @param string $name
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve2\OptionError
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
     * @throws \MarkupCarve\Carve2\OptionError
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
     * C2-H-168: 0.2.0 has no build-time renderer to call, so a nonempty map is refused.
     *
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve2\OptionError
     *
     * @return void
     */
    private static function renderers(mixed $value): void
    {
        if (!is_array($value)) {
            throw new OptionError('option renderers: not a map');
        }
        if ($value !== []) {
            throw new OptionError('option renderers: invalid renderer ' . (string)array_key_first($value));
        }
    }

    /**
     * C2-H-128, C2-H-137: a deny list or an allow list, never both; an empty object is the default.
     *
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve2\OptionError
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
     * @throws \MarkupCarve\Carve2\OptionError
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
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve2\OptionError
     *
     * @return void
     */
    private function setExtensions(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new OptionError('option extensions: not a list');
        }
        $names = [];
        $claims = [];
        foreach ($value as $item) {
            if (!$item instanceof ExtensionDefinition) {
                throw new OptionError('option extensions: an invalid extension registration');
            }
            if (isset($names[$item->name])) {
                throw new OptionError('option extensions: duplicate name ' . $item->name);
            }
            $names[$item->name] = true;
            // C2-X-014: two enabled matchers cannot claim one core node.
            $claim = $item->matcher === null ? null : $item->matcher['form'] . ':' . $item->matcher['name'];
            if ($claim !== null && isset($claims[$claim])) {
                throw new OptionError('option extensions: duplicate claim ' . $claim);
            }
            $claims[(string)$claim] = true;
        }
        $this->extensions = $value;
        foreach ($value as $item) {
            if ($item->renderer !== null) {
                $this->rendered[$item->name . "\0" . $item->version] = $item->renderer;
            }
        }
    }

    /**
     * @param mixed $value
     *
     * @throws \MarkupCarve\Carve2\OptionError
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
     * @throws \MarkupCarve\Carve2\OptionError
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
            if (($key === 'depth' || $key === 'bytes') && is_int($item) && $item >= 0) {
                $limit = $key === 'depth' ? Tables::INCLUDE_DEPTH : Tables::INCLUDE_BYTES;
                if ($item <= $limit) {
                    $key === 'depth' ? $this->includeDepth = $item : $this->includeBytes = $item;

                    continue;
                }
            }

            throw new OptionError('option includes: invalid field ' . $key);
        }
    }
}
