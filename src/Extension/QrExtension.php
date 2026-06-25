<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Event\RenderEvent;
use Carve\Node\Block\CodeBlock;
use Closure;

/**
 * Tier-3 QR-code extension (app extension; off by default, never corpus-pinned).
 *
 * Claims fenced code blocks whose language is `qr` (a URL / plain text) or
 * `qr-<type>` (a hyphenated, grammar-valid single info token), e.g.:
 *
 *     ```qr
 *     https://example.com
 *     ```
 *
 *     ```qr-wifi
 *     ssid: HomeNet
 *     password: hunter2
 *     security: WPA
 *     ```
 *
 * The QR symbol only ever encodes a STRING; the `<type>` selects a *payload
 * convention* (vCard, WiFi, SMS, ...) that scanners recognize. This class owns
 * the pure payload builder ({@see self::buildPayload()}); the bytes -> image
 * step is an injected encoder (a `Closure(string $payload): string` returning
 * an `<svg>` / `<img>` fragment), so the core takes no QR-library dependency.
 * The host supplies a real encoder, e.g. wrapping `endroid/qr-code` or
 * `chillerlan/php-qrcode`.
 *
 * Output is `<figure class="{cssClass}">{encoder output}</figure>` -- the
 * encoder's `<svg>`/`<img>` is build-time and self-contained (no client script),
 * so no separate static-render path is needed.
 */
final class QrExtension implements ExtensionInterface
{
    /**
     * @param \Closure(string): string $encoder Maps a payload string to an
     *   `<svg>` / `<img>` HTML fragment (the encoder owns its own escaping).
     * @param string $cssClass Class on the wrapping `<figure>`.
     */
    public function __construct(
        protected Closure $encoder,
        protected string $cssClass = 'qr',
    ) {
    }

    public function register(CarveConverter $converter): void
    {
        $converter->on('render.code_block', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof CodeBlock) {
                return;
            }

            $type = self::typeFor($node->getLanguage());
            if ($type === null) {
                return;
            }

            $payload = self::buildPayload($type, $node->getContent());
            $svg = ($this->encoder)($payload);
            $event->setHtml('<figure class="' . htmlspecialchars($this->cssClass, ENT_QUOTES) . '">' . $svg . "</figure>\n");
        });
    }

    /**
     * The payload type for a fence language, or null when this is not a QR
     * block. `qr` -> `url`; `qr-<type>` -> `<type>`.
     */
    public static function typeFor(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }
        if ($language === 'qr') {
            return 'url';
        }
        if (str_starts_with($language, 'qr-') && strlen($language) > 3) {
            return substr($language, 3);
        }

        return null;
    }

    /**
     * Build a scanner-recognized payload string from a type and the block body.
     * Pure (no rendering) -- the testable heart of the extension.
     *
     * Simple types use the body verbatim (a URL, a phone number); structured
     * types parse `key: value` lines. WiFi / MeCard / vCard structural
     * characters (`\ ; , :` and `"` for WiFi) are backslash-escaped so a value
     * containing one cannot corrupt the payload.
     */
    public static function buildPayload(string $type, string $body): string
    {
        $fields = [];
        foreach (explode("\n", $body) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $fields[trim($key)] = trim($value);
            }
        }
        [$first, $last] = array_pad(explode(' ', $fields['name'] ?? '', 2), 2, '');

        return match (strtolower($type)) {
            'url', 'text' => trim($body),
            'tel' => 'tel:' . trim($body),
            'geo' => 'geo:' . trim($body),
            'sms' => 'SMSTO:' . ($fields['to'] ?? '') . ':' . ($fields['body'] ?? ''),
            'email' => 'mailto:' . ($fields['to'] ?? '')
                . '?subject=' . rawurlencode($fields['subject'] ?? '')
                . '&body=' . rawurlencode($fields['body'] ?? ''),
            'wifi' => sprintf(
                'WIFI:T:%s;S:%s;P:%s;H:%s;;',
                strtoupper($fields['security'] ?? 'WPA'),
                self::escape($fields['ssid'] ?? '', '\\;,:"'),
                self::escape($fields['password'] ?? '', '\\;,:"'),
                ($fields['hidden'] ?? '') === 'true' ? 'true' : 'false',
            ),
            'contact', 'mecard' => 'MECARD:N:' . self::escape($last, '\\;,:') . ',' . self::escape($first, '\\;,:') . ';'
                . (($fields['tel'] ?? '') !== '' ? 'TEL:' . self::escape($fields['tel'], '\\;,:') . ';' : '')
                . (($fields['email'] ?? '') !== '' ? 'EMAIL:' . self::escape($fields['email'], '\\;,:') . ';' : '')
                . (($fields['url'] ?? '') !== '' ? 'URL:' . self::escape($fields['url'], '\\;,:') . ';' : '')
                . ';',
            // vCard 3.0 escapes `\ ; ,` and folds newlines in values, so the
            // author writes plain values (`org: ACME, Inc`) and never escapes.
            'vcard' => implode("\r\n", array_filter([
                'BEGIN:VCARD',
                'VERSION:3.0',
                'N:' . self::escapeVcard($last) . ';' . self::escapeVcard($first) . ';;;',
                'FN:' . self::escapeVcard($fields['name'] ?? ''),
                ($fields['org'] ?? '') !== '' ? 'ORG:' . self::escapeVcard($fields['org']) : null,
                ($fields['tel'] ?? '') !== '' ? 'TEL:' . self::escapeVcard($fields['tel']) : null,
                ($fields['email'] ?? '') !== '' ? 'EMAIL:' . self::escapeVcard($fields['email']) : null,
                ($fields['url'] ?? '') !== '' ? 'URL:' . self::escapeVcard($fields['url']) : null,
                'END:VCARD',
            ], static fn (?string $line): bool => $line !== null)),
            default => trim($body),
        };
    }

    /**
     * Backslash-escape each of `$chars` in `$value`.
     */
    protected static function escape(string $value, string $chars): string
    {
        $pattern = '/([' . preg_quote($chars, '/') . '])/';

        return preg_replace($pattern, '\\\\$1', $value) ?? $value;
    }

    /**
     * vCard 3.0 value escaping: backslash, semicolon and comma are escaped,
     * and any newline folds to the literal `\n` sequence.
     */
    protected static function escapeVcard(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
            $value,
        );
    }
}
