<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Diagnostics;

/**
 * One entry of the parse or render-loss channel (C2-A-173). The range is in UTF-8 bytes of the file `file` names, or of the
 * top-level document when `file` is null (C2-N-063).
 */
final class Diagnostic
{
    /**
     * @param string $code
     * @param string $severity
     * @param string $channel
     * @param string $message
     * @param string|null $path RFC 6901 pointer of the node the entry is about; the empty pointer is the document.
     * @param int $startOffset -1 when there is no range.
     * @param int $endOffset
     * @param string|null $file
     */
    public function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly string $channel,
        public readonly string $message,
        public readonly ?string $path = null,
        public readonly int $startOffset = -1,
        public readonly int $endOffset = -1,
        public readonly ?string $file = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['code' => $this->code, 'severity' => $this->severity, 'channel' => $this->channel, 'message' => $this->message];
        if ($this->path !== null) {
            $out['path'] = $this->path;
        }
        if ($this->startOffset >= 0) {
            $out['range'] = ['startOffset' => $this->startOffset, 'endOffset' => $this->endOffset];
        }
        if ($this->file !== null) {
            $out['file'] = $this->file;
        }

        return $out;
    }
}
