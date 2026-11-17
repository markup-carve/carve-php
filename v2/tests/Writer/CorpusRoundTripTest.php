<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Writer;

use MarkupCarve\Carve2\Test\Support\WriterGate;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-002, C2-W-003, C2-A-162: every Carve input of the corpus writes to a source that reads back as an equal tree,
 * and writing that tree again gives the same bytes.
 */
final class CorpusRoundTripTest extends TestCase
{
    public function testCorpusRoundTrip(): void
    {
        $failed = [];
        $inputs = WriterGate::corpusInputs(__DIR__ . '/../../spec/conformance');
        foreach ($inputs as $id => $input) {
            $why = WriterGate::check($input);
            if ($why !== null) {
                $failed[] = $id . ' (' . $why . ')';
            }
        }

        $this->assertGreaterThan(500, count($inputs));
        $this->assertSame([], $failed);
    }
}
