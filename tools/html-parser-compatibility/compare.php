<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Tools\HtmlParserCompatibility;

use Composer\InstalledVersions;
use Dom\HTMLDocument;
use Dom\Node;
use DOMDocument;
use DOMNode;
use MarkupCarve\Carve\Converter\HtmlDomLoader;
use Masterminds\HTML5;
use MensBeam\HTML\Parser;
use RuntimeException;
use Throwable;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['autoload:', 'json']);
if (!is_array($options)) {
    throw new RuntimeException('Cannot read comparison options.');
}
if (isset($options['autoload'])) {
    if (!is_string($options['autoload']) || !is_file($options['autoload'])) {
        throw new RuntimeException('--autoload must name one existing Composer autoload file.');
    }
    require $options['autoload'];
}

/**
 * @return array{0: string, 1: list<array|string>}|string
 */
function tree(DOMNode|Node $node, bool &$templateContentUnavailable): array|string
{
    if ($node->nodeType === XML_TEXT_NODE) {
        return $node->nodeValue ?? '';
    }
    if ($node instanceof Node && ($node->localName ?? null) === 'template' && $node->namespaceURI === 'http://www.w3.org/1999/xhtml') {
        $templateContentUnavailable = true;
    }
    $children = [];
    foreach ($node->childNodes as $child) {
        $children[] = tree($child, $templateContentUnavailable);
    }

    return [$node->localName ?? $node->nodeName, $children];
}

$backends = ['legacy' => static fn (string $html): DOMDocument => HtmlDomLoader::load($html)];
$unavailable = [];
foreach (['masterminds' => HTML5::class, 'mensbeam' => Parser::class, 'native' => HTMLDocument::class] as $name => $class) {
    if (!class_exists($class)) {
        $unavailable[] = $name;

        continue;
    }
    $backends[$name] = match ($name) {
        'masterminds' => static fn (string $html): DOMDocument => (new HTML5())->loadHTML($html),
        'mensbeam' => static fn (string $html): DOMDocument => Parser::parse($html, 'UTF-8')->document,
        'native' => static fn (string $html): HTMLDocument => HTMLDocument::createFromString($html, LIBXML_NOERROR),
    };
}

$input = file_get_contents(__DIR__ . '/cases.json');
if ($input === false) {
    throw new RuntimeException('Cannot read the parser comparison cases.');
}
$cases = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
if (!is_array($cases) || $cases === []) {
    throw new RuntimeException('The parser comparison needs a nonempty case set.');
}
$libraries = [];
foreach (['masterminds/html5', 'mensbeam/html-parser'] as $package) {
    $libraries[$package] = InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;
}
$report = ['php' => PHP_VERSION, 'libxml' => LIBXML_DOTTED_VERSION, 'libraries' => $libraries, 'unavailable' => $unavailable, 'cases' => []];
foreach ($cases as $case) {
    if (!is_array($case) || !is_string($case['name'] ?? null) || !is_string($case['html'] ?? null) || !is_array($case['expected'] ?? null)) {
        throw new RuntimeException('Each comparison case needs a name, HTML string, and expected tree.');
    }
    $html = '<!DOCTYPE html><html><head></head><body><carve-import-root>' . $case['html'] . '</carve-import-root></body></html>';
    $row = ['name' => $case['name'], 'backends' => []];
    foreach ($backends as $name => $parse) {
        try {
            $document = $parse($html);
            $root = $document->getElementsByTagName('carve-import-root')->item(0);
            if ($root === null) {
                throw new RuntimeException('The parsed document has no comparison root.');
            }
            $templateContentUnavailable = false;
            $actual = tree($root, $templateContentUnavailable);
            $row['backends'][$name] = ['matches' => $templateContentUnavailable ? null : $actual === $case['expected'], 'actual' => $actual];
            if ($templateContentUnavailable) {
                $row['backends'][$name]['limitation'] = 'Native DOM does not expose template content through childNodes.';
            }
        } catch (Throwable $error) {
            $row['backends'][$name] = ['matches' => false, 'error' => $error->getMessage()];
        }
    }
    $report['cases'][] = $row;
}

if (isset($options['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
} else {
    echo 'PHP ', PHP_VERSION, ', libxml ', LIBXML_DOTTED_VERSION, "\n";
    foreach ($report['cases'] as $row) {
        echo $row['name'];
        foreach ($row['backends'] as $name => $result) {
            $status = isset($result['error']) ? 'ERROR: ' . $result['error'] : (isset($result['limitation']) ? 'ADAPTER-LIMIT' : ($result['matches'] ? 'match' : 'DIFF'));
            echo ' ', $name, '=', $status;
        }
        echo "\n";
    }
    if ($unavailable !== []) {
        echo 'Unavailable: ', implode(', ', $unavailable), "\n";
    }
}
