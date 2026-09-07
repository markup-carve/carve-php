<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use InvalidArgumentException;
use JsonSerializable;

final readonly class SourcePatch implements JsonSerializable
{
    /**
     * @param string $sourceFingerprint
     * @param int $sourceBytes
     * @param list<\MarkupCarve\Carve\SourceEdit> $edits
     * @param list<\MarkupCarve\Carve\SourceSuggestion> $unresolved
     * @param int $version
     */
    public function __construct(
        public string $sourceFingerprint,
        public int $sourceBytes,
        public array $edits,
        public array $unresolved = [],
        public int $version = 1,
    ) {
    }

    public static function fingerprint(string $source): string
    {
        return 'fnv1a64:' . hash('fnv1a64', $source);
    }

    public static function create(
        string $source,
        string $replacement,
        string $kind = 'refactor',
        string $code = 'replace-source',
    ): self {
        self::assertUtf8($source);
        self::assertUtf8($replacement);
        $start = 0;
        $beforeLength = strlen($source);
        $afterLength = strlen($replacement);
        while ($start < $beforeLength && $start < $afterLength && $source[$start] === $replacement[$start]) {
            $start++;
        }
        while ($start > 0 && (!self::isBoundary($source, $start) || !self::isBoundary($replacement, $start))) {
            $start--;
        }
        $oldEnd = $beforeLength;
        $newEnd = $afterLength;
        while ($oldEnd > $start && $newEnd > $start && $source[$oldEnd - 1] === $replacement[$newEnd - 1]) {
            $oldEnd--;
            $newEnd--;
        }
        while (!self::isBoundary($source, $oldEnd) || !self::isBoundary($replacement, $newEnd)) {
            $oldEnd++;
            $newEnd++;
        }
        $edits = $source === $replacement ? [] : [
            new SourceEdit(
                $start,
                $oldEnd,
                substr($replacement, $start, $newEnd - $start),
                $kind,
                $code,
            ),
        ];

        return new self(self::fingerprint($source), $beforeLength, $edits);
    }

    public function apply(string $source): string
    {
        self::assertUtf8($source);
        if ($this->version !== 1) {
            throw new InvalidArgumentException('Unsupported source patch version.');
        }
        if (
            strlen($source) !== $this->sourceBytes
            || self::fingerprint($source) !== $this->sourceFingerprint
        ) {
            throw new InvalidArgumentException('Source patch precondition does not match the source.');
        }
        $output = '';
        $cursor = 0;
        foreach ($this->edits as $edit) {
            if (
                $edit->start < $cursor
                || $edit->end < $edit->start
                || $edit->end > strlen($source)
                || !self::isBoundary($source, $edit->start)
                || !self::isBoundary($source, $edit->end)
                || !in_array($edit->kind, ['formatting', 'syntax-migration', 'quick-fix', 'refactor'], true)
                || $edit->code === ''
            ) {
                throw new InvalidArgumentException(
                    'Source patch edits must be sorted, non-overlapping UTF-8 byte ranges.',
                );
            }
            $output .= substr($source, $cursor, $edit->start - $cursor) . $edit->replacement;
            $cursor = $edit->end;
        }

        return $output . substr($source, $cursor);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'version' => $this->version,
            'sourceFingerprint' => $this->sourceFingerprint,
            'sourceBytes' => $this->sourceBytes,
            'edits' => $this->edits,
            'unresolved' => $this->unresolved,
        ];
    }

    private static function isBoundary(string $source, int $offset): bool
    {
        return $offset >= 0
            && $offset <= strlen($source)
            && ($offset === 0 || $offset === strlen($source) || (ord($source[$offset]) & 0xc0) !== 0x80);
    }

    private static function assertUtf8(string $source): void
    {
        if (preg_match('//u', $source) !== 1) {
            throw new InvalidArgumentException('Source patches require valid UTF-8.');
        }
    }
}
