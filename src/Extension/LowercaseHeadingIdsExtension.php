<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

/**
 * Lowercase auto-generated heading ids (opt-in)
 *
 * By default Carve heading ids are CASE-PRESERVING (`# Getting Started` ->
 * `Getting-Started`), per carve spec #73. Add this extension for
 * GitHub/SSG-style lowercase anchors (`# Getting Started` -> `getting-started`).
 *
 * Lowercasing is applied PER CODE POINT, so no whole-string context mapping
 * (such as Greek final-sigma) applies and ids stay byte-portable across
 * implementations. It runs after the optional ASCII transliteration step, so
 * combining this with AsciiHeadingIdsExtension yields a fully lowercase ASCII
 * slug (`# Über uns` -> `uber-uns`).
 *
 * The same flag is applied to the parse-time tracker so implicit
 * `[Heading][]` references resolve to the lowercased ids.
 *
 * Example:
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new LowercaseHeadingIdsExtension());
 * ```
 */
class LowercaseHeadingIdsExtension implements ExtensionInterface
{
    public function register(CarveConverter $converter): void
    {
        // Parse-time tracker (implicit [Heading][] references).
        $converter->getParser()->setHeadingIdLowercase(true);

        // Render-time tracker (the ids emitted in HTML). Only meaningful
        // with HtmlRenderer; silently skip otherwise.
        if (!$converter->getRenderer() instanceof HtmlRenderer) {
            return;
        }

        $converter->getHeadingIdTracker()->setLowercase(true);
    }
}
