#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerates tests/fixtures/corpus-render-ledger.txt from the pinned corpus.
 *
 * Read the rewritten lines: a replaced line is a document whose markdown,
 * plain-text or ANSI output moved.
 *
 * Usage: php bin/render-ledger.php
 */

use MarkupCarve\Carve\Test\TestCase\CorpusRenderLedger;

require dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists(CorpusRenderLedger::class)) {
    fwrite(STDERR, "The test autoloader is missing: install with dev dependencies.\n");
    exit(1);
}

$rows = CorpusRenderLedger::render();
file_put_contents(CorpusRenderLedger::path(), CorpusRenderLedger::serialize($rows));

fwrite(STDOUT, count($rows) . ' documents written to ' . CorpusRenderLedger::path() . "\n");
