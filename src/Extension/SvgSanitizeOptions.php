<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

/**
 * Provisional. Options for `SvgSanitizer::sanitize()`, the same as carve-js `SanitizeSvgOptions`. Every flag defaults to off.
 */
final class SvgSanitizeOptions
{
    /**
     * @param bool $allowStyle Keep the `style` attribute when its value cannot fetch or execute. The `<style>` element
     *   is always dropped.
     * @param bool $allowLinks Keep `<a>` and its external `href` (safe schemes only).
     * @param bool $allowAnimation Keep the SMIL elements `animate`, `animateTransform`, `animateMotion`, `set` and
     *   `mpath`.
     * @param bool $allowExternalImages Keep `<image>` and its external `href` (safe schemes only; `data:` stays
     *   refused).
     * @param array{deny?: array<string>, allow?: array<string>}|null $urlPolicy The scheme policy of
     *   C2-H-128; null is the default deny list.
     */
    public function __construct(
        public bool $allowStyle = false,
        public bool $allowLinks = false,
        public bool $allowAnimation = false,
        public bool $allowExternalImages = false,
        public ?array $urlPolicy = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options The constructor's parameter names as keys; unknown keys are ignored.
     *
     * @return self
     */
    public static function fromArray(array $options): self
    {
        $policy = $options['urlPolicy'] ?? null;

        /** @var array{deny?: array<string>, allow?: array<string>}|null $policy */
        return new self(
            allowStyle: (bool)($options['allowStyle'] ?? false),
            allowLinks: (bool)($options['allowLinks'] ?? false),
            allowAnimation: (bool)($options['allowAnimation'] ?? false),
            allowExternalImages: (bool)($options['allowExternalImages'] ?? false),
            urlPolicy: is_array($policy) ? $policy : null,
        );
    }
}
