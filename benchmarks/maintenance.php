<?php

declare(strict_types=1);

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;

if (!isset($argv[1])) {
    fwrite(STDERR, "Usage: php maintenance.php /absolute/path/to/worktree\n");
    exit(2);
}
require $argv[1] . '/vendor/autoload.php';

$results = [];
$cases = ['verse_definitions', 'paragraphs', 'html_table'];
if (getenv('CARVE_BENCH_RELEASE') !== false) {
    $cases[] = 'mixed_document';
}
foreach ([128, 512, 1024] as $n) {
    foreach ($cases as $name) {
        $mixed = '';
        if ($name === 'mixed_document') {
            for ($section = 0; $section < $n; $section++) {
                $mixed .= "# Section {$section}\n\nA paragraph with *strong*, /emphasis/, [reference][ref] and `code`.\n\n- first\n- second\n\n| key | value |\n| --- | --- |\n| item | count |\n\n";
            }
            $mixed .= "[ref]: /target\n";
        }
        $source = match ($name) {
            'verse_definitions' => "> ::: |\n" . str_repeat("> [r]: /hidden\n", $n) . "> :::\n\n[t][r]\n",
            'mixed_document' => $mixed,
            'paragraphs' => str_repeat("plain paragraph\n\n", $n),
            default => '<table>' . str_repeat('<tr><td><blockquote cite="u"><p>q</p></blockquote></td></tr>', $n) . '</table>',
        };
        $run = static fn () => $name === 'html_table'
            ? (new HtmlToCarve(listTableForBlockCells: true))->convertWithReport($source)
            : (new CarveConverter())->convert($source);
        $run();
        $run();
        $samples = [];
        for ($i = 0; $i < 7; $i++) {
            $start = hrtime(true);
            $out = $run();
            $samples[] = (hrtime(true) - $start) / 1e6;
        }
        sort($samples);
        $results[] = [
            'name' => $name,
            'n' => $n,
            'bytes' => strlen($source),
            'median_ms' => $samples[3],
            'min_ms' => $samples[0],
            'samples_ms' => $samples,
            'hash' => hash('sha256', serialize($out)),
        ];
    }
}
echo json_encode($results, JSON_PRETTY_PRINT), "\n";
