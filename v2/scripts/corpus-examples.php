<?php

declare(strict_types=1);

/**
 * The render examples of the pinned conformance corpus, in the shape the comparison scripts read.
 *
 * @return array<int, array{file: string, number: int, input: string, html: string, options: array<string, mixed>, flags: array<string>}>
 */
function corpusExamples(): array
{
    $path = dirname(__DIR__) . '/spec/conformance/examples.json';
    $out = [];
    foreach (json_decode((string)file_get_contents($path), true)['examples'] as $ex) {
        if ($ex['kind'] === 'render' && isset($ex['expected']['html'])) {
            $out[] = [
                'file' => $ex['document'], 'number' => $ex['number'], 'input' => $ex['input'],
                'html' => $ex['expected']['html'], 'options' => $ex['options'], 'flags' => [],
            ];
        }
    }

    return $out;
}
