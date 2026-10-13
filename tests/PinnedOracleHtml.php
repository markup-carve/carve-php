<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use LogicException;

final class PinnedOracleHtml
{
    public static function forSource(string $source): string
    {
        static $html = null;
        if ($html === null) {
            $html = [];
            foreach (json_decode((string)file_get_contents(__DIR__ . '/fixtures/spec-parity/comment-control-columns.json'), true, 512, JSON_THROW_ON_ERROR) as $row) {
                $html[$row['source']] = $row['html'];
            }
        }

        return $html[$source] ?? throw new LogicException('Missing pinned oracle row');
    }
}
