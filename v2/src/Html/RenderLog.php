<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Html;

/**
 * The render-loss entries of one render (C2-H-132, C2-A-173).
 */
final class RenderLog
{
    /**
     * @var array<array{code: string, severity: string, message: string, channel: string, file: string|null}>
     */
    public array $entries = [];

    /**
     * C2-H-052: raw HTML was escaped in this render.
     */
    public bool $escapedRaw = false;

    /**
     * An error that refused the whole render (C2-H-163 `max_length`), or null.
     */
    public ?string $error = null;

    /**
     * @param string $code
     * @param string $severity
     * @param string $message
     * @param string|null $file
     *
     * @return void
     */
    public function loss(string $code, string $severity, string $message, ?string $file = null): void
    {
        $this->entries[] = [

            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'channel' => 'render-loss',
            'file' => $file,
        ];
    }
}
