<?php

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;

require $argv[1] . '/vendor/autoload.php';
$results = [];
foreach ([128, 512, 1024] as $n) {
    foreach (['verse_definitions', 'paragraphs', 'html_table'] as $name) {
        $source = match ($name) {
            'verse_definitions'=>"> ::: |\n" . str_repeat("> [r]: /hidden\n", $n) . "> :::\n\n[t][r]\n", 'paragraphs'=>str_repeat("plain paragraph\n\n", $n), default=>'<table>' . str_repeat('<tr><td><blockquote cite="u"><p>q</p></blockquote></td></tr>', $n) . '</table>'
        };
        $run = static fn () => $name === 'html_table' ? (new HtmlToCarve(listTableForBlockCells: true))->convertWithReport($source) : (new CarveConverter())->convert($source);
        $run();
        $run();
        $samples = [];for ($i = 0; $i < 7; $i++) {
            $start = hrtime(true);
            $out = $run();
            $samples[] = (hrtime(true) - $start) / 1e6;
        }sort($samples);
        $results[] = ['name' => $name, 'n' => $n, 'bytes' => strlen($source), 'median_ms' => $samples[3], 'min_ms' => $samples[0], 'hash' => hash('sha256', serialize($out))];
    }
}
echo json_encode($results, JSON_PRETTY_PRINT), "\n";
