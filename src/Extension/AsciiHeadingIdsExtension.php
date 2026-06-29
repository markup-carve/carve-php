<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AsciiTransliterator;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

/**
 * Fold auto-generated heading ids to ASCII (opt-in)
 *
 * By default Carve heading ids are CASE-PRESERVING and keep non-ASCII
 * characters verbatim (`# Über uns` -> `Über-uns`), per carve spec #73.
 * Cross-references (`</#id>`, `[Heading][]`) resolve case-insensitively,
 * so a lowercase reference still finds a case-preserved id.
 *
 * Add this extension when you need share-safe ASCII fragment ids - e.g.
 * URLs passed through auto-linkers that truncate or mis-encode non-ASCII.
 * It transliterates the slug to ASCII (`# Über uns` -> `Uber-uns`). It does
 * NOT lowercase: case is kept (combine with the lowercase option for a fully
 * lowercase ASCII slug). Unmapped scripts (CJK, Arabic, Greek) still pass
 * through unchanged; attach an explicit `{#id}` for those.
 *
 * The same transform is applied to the parse-time tracker so implicit
 * `[Heading][]` references resolve to the folded ids.
 *
 * Example:
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new AsciiHeadingIdsExtension());
 * ```
 */
class AsciiHeadingIdsExtension implements ExtensionInterface
{
    protected AsciiTransliterator $transliterator;

    public function __construct(?AsciiTransliterator $transliterator = null)
    {
        $this->transliterator = $transliterator ?? new AsciiTransliterator();
    }

    public function register(CarveConverter $converter): void
    {
        $transliterator = $this->transliterator;
        $transform = static fn (string $slug): string => $transliterator->transliterate($slug);

        // Parse-time tracker (implicit [Heading][] references).
        $converter->getParser()->setHeadingIdTransformer($transform);

        // Render-time tracker (the ids emitted in HTML). Only meaningful
        // with HtmlRenderer; silently skip otherwise.
        if (!$converter->getRenderer() instanceof HtmlRenderer) {
            return;
        }

        $converter->getHeadingIdTracker()->setIdTransformer($transform);
    }
}
