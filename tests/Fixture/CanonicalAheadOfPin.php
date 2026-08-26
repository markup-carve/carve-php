<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Fixture;

use function array_key_exists;

/**
 * Canonical fixtures implemented by this engine ahead of its spec pin.
 */
final class CanonicalAheadOfPin
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            '227-a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace'
                => ":: term\n: [r]: /u\n\nsee [t][r]\n",
            '227-a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace-2'
                => ":: term\n: [^f]: x\n\nsee[^f]\n",
            '279-a-boundary-line-inside-an-open-fence-does-not-end-the-container-3'
                => ":: t\n: d\n\n  ```\n  a\n\n  b\n  ```\n",
            '407-one-consumed-boolean-spells-the-looseness-no-blank-line-can-2'
                => "{loose}\n:: Term\n: Definition.\n",
        ];
    }

    public static function declares(string $slug): bool
    {
        return array_key_exists($slug, self::all());
    }

    public static function get(string $slug): string
    {
        return self::all()[$slug];
    }
}
