<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Support;

use MarkupCarve\Carve2\Extension\DocumentTransform;
use MarkupCarve\Carve2\Json\TreeDecoder;

/**
 * Adapt the conformance corpus's host registrations to the PHP callback API.
 */
final class ClauseHost
{
    /**
     * @param array<string, mixed> $case
     *
     * @return array<\MarkupCarve\Carve2\Extension\DocumentTransform>
     */
    public static function documentTransforms(array $case): array
    {
        $transforms = [];
        foreach ($case['host']['documentTransforms'] ?? [] as $registration) {
            $transforms[] = new DocumentTransform($registration['name'], static function ($document) use ($registration) {
                $index = (int)substr($registration['after'], strrpos($registration['after'], '/') + 1) + 1;
                $insert = $registration['insert'];
                unset($insert['slots']);
                $node = TreeDecoder::fromArray($insert);
                array_splice($document->children, $index, 0, [$node]);

                return $document;
            });
        }

        return $transforms;
    }
}
