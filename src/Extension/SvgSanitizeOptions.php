<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

/**
 * Options for {@see SvgSanitizer::sanitize()}.
 *
 * Each flag gates a small set of constructs that are safe only in some
 * contexts. All default OFF, so the out-of-the-box posture is the strict
 * presentational allowlist. Mirrors carve-js `SanitizeSvgOptions`.
 */
final class SvgSanitizeOptions
{
    /**
     * @param bool $allowStyle Keep the `style` **attribute** (value scrubbed of
     *   `url()`/`expression()`/…). The `<style>` *element* is always dropped
     *   regardless — its selectors can reach the whole page and its text can
     *   carry `@import`/`url()`.
     * @param bool $allowLinks Keep `<a>` elements and external `href`/`xlink:href`
     *   (safe schemes only).
     * @param bool $allowAnimation Keep SMIL animation elements (`<animate>`,
     *   `<set>`, …).
     * @param bool $allowExternalImages Keep `<image>` and its external raster
     *   `href` (safe schemes only; note `data:` is still rejected as a dangerous
     *   scheme).
     */
    public function __construct(
        public bool $allowStyle = false,
        public bool $allowLinks = false,
        public bool $allowAnimation = false,
        public bool $allowExternalImages = false,
    ) {
    }

    /**
     * Build options from an associative array (unknown keys ignored).
     *
     * @param array<string, bool> $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            allowStyle: (bool)($options['allowStyle'] ?? false),
            allowLinks: (bool)($options['allowLinks'] ?? false),
            allowAnimation: (bool)($options['allowAnimation'] ?? false),
            allowExternalImages: (bool)($options['allowExternalImages'] ?? false),
        );
    }
}
