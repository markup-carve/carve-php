<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

use MarkupCarve\Carve\Extension\DocumentTransform;
use MarkupCarve\Carve\Json\TreeDecoder;

/**
 * Adapt the conformance corpus's host registrations to the PHP callback API.
 */
final class ClauseHost
{
    /**
     * @param array<string, mixed> $case
     *
     * @return array<\MarkupCarve\Carve\Extension\DocumentTransform>
     */
    public static function documentTransforms(array $case): array
    {
        $transforms = [];
        foreach ($case['host']['documentTransforms'] ?? [] as $registration) {
            $transforms[] = new DocumentTransform($registration['name'], static function ($document) use ($registration) {
                $index = (int)substr($registration['after'], strrpos($registration['after'], '/') + 1) + 1;
                if (isset($registration['setValue'])) {
                    $node = $document;
                    foreach (array_chunk(array_slice(explode('/', $registration['after']), 1), 2) as [$field, $at]) {
                        $node = $field === 'children' ? $node->children[(int)$at] : $node->f[$field][(int)$at];
                    }
                    $node->f['value'] = $registration['setValue'];

                    return $document;
                }
                if (isset($registration['setLevel'])) {
                    $document->children[$index - 1]->f['level'] = $registration['setLevel'];

                    return $document;
                }
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
