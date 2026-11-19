# Carve 0.2 for PHP: library guide

carve-php (`markup-carve/carve-php`) implements Carve 0.2 under the namespace `MarkupCarve\Carve`. Its one entry point is the static class `MarkupCarve\Carve\Carve`; every class it names in a
signature is listed in the [API reference](api.md). Everything else under `MarkupCarve\Carve` is internal. Hosts
moving from 0.1 start with the [migration guide](migration.md).

PHP 8.2 or later. HTML import needs `ext-dom`.

Each example below runs in the test suite (`tests/PublicApi/DocsExamplesTest.php`).

## Render and parse

`render()` takes Carve source or a tree. Options are one array, validated against the option table of the
specification; an unknown name or a bad value throws `OptionError`.

```php
use MarkupCarve\Carve\Carve;

$html = Carve::render("# Title\n\nSome *strong* text.\n", ['sections' => false]);
assert($html === "<h1 id=\"Title\">Title</h1>\n<p>Some <strong>strong</strong> text.</p>");

$tree = Carve::parse("# Title\n");
assert($tree->type === 'document');
assert(Carve::render($tree, ['sections' => false]) === '<h1 id="Title">Title</h1>');
```

## Diagnostics

The `...WithDiagnostics` calls return the result and a list of `Diagnostic` entries (`code`, `severity`, `channel`,
`message`, `path`, byte offsets, `file`).

```php
use MarkupCarve\Carve\Carve;

$result = Carve::renderWithDiagnostics('{{ part.crv }}');
assert($result->codes() === ['include-no-resolver', 'include-unresolved']);

$findings = Carve::lint("{#a}\nx\n\n{#a}\ny\n");
assert($findings[0]->code === 'duplicate-id');
```

## Errors

Every error a call throws implements `MarkupCarve\Carve\CarveException`: `OptionError`, `InputError`,
`RenderError`, `Writer\CarveWriteError`, `Json\TreeError`, `Layout\LayoutError` and `Import\ImportInputError`.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\CarveException;
use MarkupCarve\Carve\OptionError;

try {
    Carve::render('a', ['profile' => 'huge']);
} catch (CarveException $e) {
    assert($e instanceof OptionError);
}
```

## Format, write and import

```php
use MarkupCarve\Carve\Carve;

assert(Carve::format("a  \nb\n") === "a\nb\n");
assert(Carve::write(Carve::parse("Some *x*\n")) === "Some *x*\n");
assert(Carve::importDocument('<p>Hi <em>there</em></p>', ['from' => 'html'])->source === "Hi /there/\n");
```

## Tree JSON

`encodeTree()` gives the bare tree, `encodeEnvelope()` the envelope for storage, and `decodeTree()` reads either one
back, refusing a tree the reader checks refuse with `Json\TreeError`.

```php
use MarkupCarve\Carve\Carve;

$json = Carve::encodeEnvelope(Carve::parse("a\n"));
$tree = Carve::decodeTree($json);
Carve::validateTree($tree);
assert(Carve::render($tree) === '<p>a</p>');
```

## Includes

Pass a resolver in the `includes` option: `Include\FileResolver::forRoot()` reads files under an absolute root
(C2-N-012), or implement `Include\IncludeResolver` to read from anywhere else. Without a resolver an include stays
unexpanded and is reported.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Include\IncludeResolver;

$resolver = new class implements IncludeResolver {
    public function resolve(array $request): array
    {
        return ['text' => "Included *text*.\n", 'id' => 'db:' . $request['path']];
    }
};
assert(Carve::render('{{ intro.crv }}', ['includes' => $resolver]) === '<p>Included <strong>text</strong>.</p>');
assert(Carve::includeDependencies('{{ intro.crv }}', ['includes' => $resolver])[0]['file'] === 'db:intro.crv');
```

## Output writers

`Carve::writers()` returns a registry with the `html` and `carve` targets. A host adds its own `OutputWriter`. The
0.1 Markdown, plain text and ANSI writers are coming to 0.2 as the targets `markdown`, `text` and `ansi` on this same
interface, so code written against `writeTo()` will not change when they arrive.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Output\OutputWriter;

$registry = Carve::writers()->register(new class implements OutputWriter {
    public function target(): string
    {
        return 'count';
    }

    public function write(Node $tree, array $options = []): string
    {
        return (string)count($tree->children ?? []);
    }
});
$tree = Carve::parse("a\n\nb\n");
assert(Carve::writeTo('html', $tree, [], $registry) === "<p>a</p>\n<p>b</p>");
assert(Carve::writeTo('count', $tree, [], $registry) === '2');
```

## Host helpers

`Carve::plainText()` gives the plain text of a node or a node list of a tree (C2-H-143), the same text an extension
renderer gets from `RenderHost::$plainText`, so a host can read element text before rendering. `Carve::tableGrid()`
expands a `table` node for an output without rowspan and colspan: per row its group and, per column, the cell that
occupies the slot (null where no cell reaches). `Carve::safeCodeFence()` gives the shortest fence, three at least,
that no line of the content can close.

```php
use MarkupCarve\Carve\Carve;

$tree = Carve::parse("Ask :product[#SKU-1] now.\n\n| a | < | b |\n| c | d | ^ |\n");
assert(Carve::plainText($tree->children[0]) === 'Ask #SKU-1 now.');
$grid = Carve::tableGrid($tree->children[1]);
assert($grid[0]['cells'][0] === $grid[0]['cells'][1] && $grid[1]['cells'][2] === $grid[0]['cells'][2]);
assert(Carve::safeCodeFence("```\ncode\n```\n") === '````');
```

## Extensions (provisional)

The extension host follows `extensions.md` of the specification and may change in 0.2.x. An extension is an
`ExtensionDefinition`: a qualified name, a version, its slots, a matcher (`div`, `fence` or `inline`) with a transform
that turns the claimed core node into an extension node, and an optional renderer, which is trusted host code. A
package ships its definitions through `ExtensionProvider` and the host passes it in the `extensions` option. The
same option goes to `parse`, `render`, `lint`, `format`, `write` and `decodeTree`.

```php
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Claim;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\ExtensionProvider;
use MarkupCarve\Carve\Extension\RenderHost;
use MarkupCarve\Carve\Node;

$embed = new class implements ExtensionProvider {
    public function definitions(): array
    {
        return [new ExtensionDefinition(
            'media.embed',
            '1',
            ['caption'],
            matcher: ['form' => 'fence', 'name' => 'embed'],
            transform: static function (Claim $claim): Node {
                $node = new Node('block_extension', []);
                $node->f = [
                    'name' => 'media.embed',
                    'version' => '1',
                    'slots' => ['caption' => []],
                    'props' => ['url' => trim($claim->source['body'] ?? '')],
                    'fallback' => [$claim->copyNode()],
                ];

                return $node;
            },
            renderer: static fn (Node $node, RenderHost $host): string
                => '<iframe src="' . RenderHost::escapeAttribute((string)($node->f['props']['url'] ?? '')) . '"></iframe>',
        )];
    }
};
$source = "```embed\nhttps://example.com/v/1\n```\n";
assert(Carve::render($source, ['extensions' => [$embed]]) === '<iframe src="https://example.com/v/1"></iframe>');
assert(Carve::format($source, ['extensions' => [$embed]]) === $source);
```

Not yet in the PHP engine: renderers for inline extensions (an inline extension renders its fallback), the
build-time renderer channel for `mode: static` and document transforms (rulings D3 and D4 of the 0.2 specification).
They will be added to `ExtensionDefinition` and the option map without changing the calls above.

## Command line

The package installs `carve` (`vendor/bin/carve`), the program of tooling.md. See the [README](../README.md) for its
commands and flags.
