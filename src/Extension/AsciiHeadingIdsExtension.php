<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Renderer\AsciiTransliterator;
use Carve\Renderer\HtmlRenderer;

/**
 * Fold auto-generated heading ids to ASCII (opt-in)
 *
 * By default Carve heading ids are lowercased but keep non-ASCII
 * characters verbatim (`# Über uns` -> `über-uns`), per carve spec #73.
 * That is the GitHub/SSG convention and keeps `</#id>` / `[Heading][]`
 * cross-references case-insensitive.
 *
 * Add this extension when you need share-safe ASCII fragment ids - e.g.
 * URLs passed through auto-linkers that truncate or mis-encode non-ASCII.
 * It transliterates the slug to ASCII before the final lowercase step
 * (`# Über uns` -> `uber-uns`). Unmapped scripts (CJK, Arabic, Greek)
 * still pass through unchanged; attach an explicit `{#id}` for those.
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
