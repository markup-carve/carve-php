<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use JsonException;
use stdClass;

/**
 * C2-A-219: every number of a tree JSON text is a finite binary64 value whose shortest spelling denotes the number the
 * text gives. One scan of the text over the tokens that can lose value; the path is looked up only for a refusal.
 */
final class TreeNumbers
{
    /**
     * A JSON string is skipped; a number matches when it has a fraction, an exponent or sixteen or more digits. Shorter
     * integers are binary64 values as they stand.
     *
     * @var string
     */
    private const CANDIDATE = '/"(?:[^"\\\\]++|\\\\.)*+"(*SKIP)(*FAIL)'
        . '|(?<![\d.eE+\-])-?(?:\d++(?:\.\d++(?:[eE][+-]?+\d++)?+|[eE][+-]?+\d++)|\d{16,}+)/';

    /**
     * Refuses the first number of `$json`, valid JSON text, that does not keep its value as binary64.
     *
     * @param string $json
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     *
     * @return void
     */
    public static function check(string $json): void
    {
        $matches = [];
        if (preg_match_all(self::CANDIDATE, $json, $matches, PREG_OFFSET_CAPTURE) === false) {
            $matches = [self::scan($json)];
        }
        foreach ($matches[0] as [$token, $offset]) {
            if (!self::keeps($token)) {
                throw new TreeError('number', self::pathOf($json, $offset, strlen($token)));
            }
        }
    }

    /**
     * Whether a JSON number token reads as a finite binary64 value whose shortest spelling denotes the same number.
     */
    public static function keeps(string $token): bool
    {
        $value = (float)$token;
        if (!is_finite($value)) {
            return false;
        }

        return self::decimal($token) === self::decimal(self::shortest($value));
    }

    /**
     * The shortest digits that read back as `$value`, the nearest of that length (as RFC 8785 picks them), apart from
     * `serialize_precision`.
     */
    private static function shortest(float $value): string
    {
        for ($digits = 0; $digits < 17; $digits++) {
            $text = sprintf('%.' . $digits . 'e', $value);
            if ((float)$text === $value) {
                return $text;
            }
        }

        return sprintf('%.16e', $value);
    }

    /**
     * A number spelling as `digits e exponent` with no leading or trailing zeros; zero has no sign.
     */
    private static function decimal(string $number): string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $number, $m) !== 1) {
            return 'x' . $number;
        }
        $fraction = $m[3] ?? '';
        $digits = ltrim($m[2] . $fraction, '0');
        if ($digits === '') {
            return '0';
        }
        $trimmed = rtrim($digits, '0');
        $exponent = (int)($m[4] ?? '0') - strlen($fraction) + strlen($digits) - strlen($trimmed);

        return $m[1] . $trimmed . 'e' . $exponent;
    }

    /**
     * The candidate tokens of `check()` without PCRE, for a text the pattern could not run on.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private static function scan(string $json): array
    {
        $out = [];
        $length = strlen($json);
        $i = strcspn($json, '"-0123456789');
        while ($i < $length) {
            if ($json[$i] === '"') {
                $i++;
                while ($i < $length) {
                    $i += strcspn($json, '"\\', $i);
                    if ($i >= $length || $json[$i] === '"') {
                        break;
                    }
                    $i += 2;
                }
                $i++;
            } else {
                $size = strspn($json, '-0123456789.eE+', $i);
                $token = substr($json, $i, $size);
                if (strcspn($token, '.eE') < $size || strlen(ltrim($token, '-')) >= 16) {
                    $out[] = [$token, $i];
                }
                $i += $size;
            }
            $i += strcspn($json, '"-0123456789', $i);
        }

        return $out;
    }

    /**
     * The JSON pointer of the token at `$offset`: the token is replaced by a string no other value holds, and that string
     * is found in the decoded text.
     */
    private static function pathOf(string $json, int $offset, int $length): string
    {
        do {
            $mark = 'carve-number-' . bin2hex(random_bytes(8));
        } while (str_contains($json, $mark));
        try {
            $marked = substr_replace($json, '"' . $mark . '"', $offset, $length);
            $value = json_decode($marked, false, TreeReader::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }

        return self::find($value, $mark, '') ?? '';
    }

    private static function find(mixed $value, string $mark, string $path): ?string
    {
        if ($value === $mark) {
            return $path;
        }
        if (!is_array($value) && !$value instanceof stdClass) {
            return null;
        }
        foreach ((array)$value as $key => $item) {
            $found = self::find($item, $mark, $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key));
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
