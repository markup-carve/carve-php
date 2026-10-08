<?php
$root = $argv[1];
spl_autoload_register(function ($class) use ($root) {
    $prefix = 'MarkupCarve\\Carve\\';
    if (str_starts_with($class, $prefix)) require $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
});
$shape = $argv[2]; $api = $argv[4] ?? 'carve';
foreach (explode(',', $argv[3]) as $n) {
    $html = file_get_contents("/tmp/carve-footnote-fixtures/$shape-$n.html");
    $call = static function () use ($html, $api) {
        try {
            $converter = new MarkupCarve\Carve\Converter\HtmlToCarve(importAdapter: 'word');
            return $api === 'ast' ? $converter->convertToAstWithReport($html) : $converter->convertWithReport($html);
        } catch (Throwable $e) { return ['error' => get_class($e), 'message' => $e->getMessage()]; }
    };
    $call(); $samples = []; $hashes = [];
    for ($i=0; $i<3; $i++) {
        gc_collect_cycles(); $start=hrtime(true); $out=$call(); $samples[]=(hrtime(true)-$start)/1e6;
        $hashes[]=hash('sha256', json_encode($out));
    }
    echo json_encode(['n'=>(int)$n, 'bytes'=>strlen($html), 'api'=>$api, 'samples'=>$samples, 'hashes'=>$hashes]), "\n";
}
