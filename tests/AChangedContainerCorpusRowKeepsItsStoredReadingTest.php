<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AChangedContainerCorpusRowKeepsItsStoredReadingTest extends TestCase
{
    public function testTheThreeRegressedRowsMatchTheCorpus(): void
    {
        foreach (['558-a-container-boundary-is-read-at-the-container-s-own-column-3', '566-an-opener-past-a-hosted-container-s-column-opens-there', '566-an-opener-past-a-hosted-container-s-column-opens-there-4'] as $slug) {
            $base = __DIR__ . '/spec/tests/corpus/' . $slug;
            self::assertSame(trim(file_get_contents($base . '.html')), trim((new CarveConverter())->convert(file_get_contents($base . '.crv'))));
        }
    }
}
