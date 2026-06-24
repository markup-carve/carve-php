<?php

declare(strict_types=1);

namespace Carve\Renderer;

use InvalidArgumentException;

/**
 * The render mode a render carries (a render option, not document syntax).
 *
 * - `interactive` (default): online HTML; extensions render their interactive
 *   form (live tabs, mermaid via a client script, KaTeX).
 * - `static`: HTML for a medium that cannot interact or run client scripts
 *   (print, PDF source, archival HTML). The Markdown, plain-text and ANSI
 *   renderers are inherently static.
 *
 * `print`, `email` and similar names are reserved for future named presets;
 * an unknown value MUST be rejected rather than guessed. Omitting the mode
 * means `interactive`, so existing callers are unaffected.
 *
 * See `docs/extensions.md` §2.5 and `docs/graceful-degradation.md`.
 */
final class RenderMode
{
    /**
     * Online HTML: extensions render their interactive form. The default.
     *
     * @var string
     */
    public const INTERACTIVE = 'interactive';

    /**
     * HTML for a non-interactive, no-client-script medium (print, PDF, archive).
     *
     * @var string
     */
    public const STATIC = 'static';

    /**
     * All accepted mode values.
     *
     * @var array<string>
     */
    public const ALL = [self::INTERACTIVE, self::STATIC];

    /**
     * Validate a mode value, returning it normalized.
     *
     * @param string $mode The requested mode.
     *
     * @throws \InvalidArgumentException If the mode is not a known value.
     *
     * @return string The validated mode.
     */
    public static function validate(string $mode): string
    {
        if (!in_array($mode, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown render mode "%s". Expected one of: %s.',
                $mode,
                implode(', ', self::ALL),
            ));
        }

        return $mode;
    }
}
