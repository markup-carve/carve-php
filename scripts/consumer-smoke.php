<?php

declare(strict_types=1);

// Run by consumer-smoke.sh inside a scratch project: one call of every public entry of Carve.

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\CarveException;
use MarkupCarve\Carve\Extension\Tabs;
use MarkupCarve\Carve\Include\FileResolver;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Output\OutputWriter;

require __DIR__ . '/vendor/autoload.php';

function check(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL $what\n");
        exit(1);
    }
    echo "ok $what\n";
}

$dir = sys_get_temp_dir() . '/carve-smoke-' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/part.crv', "Part.\n");
$includes = ['includes' => FileResolver::forRoot($dir)];

$tree = Carve::parse("# T\n\n{{ part.crv }}\n", $includes);
check($tree->type === 'document', 'parse');
check(Carve::parseWithDiagnostics("{#a}\nx\n\n{#a}\ny\n")->codes() === ['duplicate-id'], 'parseWithDiagnostics');
$layout = Carve::parseWithLayout("- a\n");
check(is_array(Carve::validateLayout((string)json_encode($layout->layout))), 'parseWithLayout, validateLayout');
check(str_contains(Carve::render($tree), '<p>Part.</p>'), 'render tree');
check(Carve::render("a\n") === '<p>a</p>', 'render source');
check(Carve::toHtml("a\n") === '<p>a</p>', 'toHtml');
check(Carve::renderWithDiagnostics('{{ x.crv }}')->codes() === ['include-no-resolver', 'include-unresolved'], 'renderWithDiagnostics');
check(str_starts_with(Carve::toJson("a\n"), '{"type":"document"'), 'toJson');
check(Carve::lint("{#a}\nx\n\n{#a}\ny\n")[0]->code === 'duplicate-id', 'lint');
check(Carve::includeDependencies($tree)[0]['read'] === true, 'includeDependencies');
check(Carve::format("a  \nb\n") === "a\nb\n", 'format');
check(Carve::formatWithDiagnostics("a\n")->diagnostics === [], 'formatWithDiagnostics');
check(Carve::write(Carve::parse("x\n")) === "x\n", 'write');
check(Carve::writeWithDiagnostics(Carve::parse("x\n"))->source === "x\n", 'writeWithDiagnostics');
check(Carve::importDocument('<p>Hi</p>')->source === "Hi\n", 'importDocument');
check(Carve::importHtml('<p>Hi</p>')->source === "Hi\n", 'importHtml');
$decoded = Carve::decodeTree(Carve::encodeEnvelope(Carve::parse("a\n")));
check(Carve::encodeTree($decoded) === Carve::encodeTree(Carve::decodeTree(Carve::encodeTree($decoded))), 'encodeTree, encodeEnvelope, decodeTree');
Carve::validateTree($decoded);
check(true, 'validateTree');
$registry = Carve::writers()->register(new class implements OutputWriter {
    public function target(): string
    {
        return 'stub';
    }

    public function write(Node $tree, array $options = []): string
    {
        return $tree->type;
    }
});
check(Carve::writeTo('stub', $decoded, [], $registry) === 'document', 'writers, writeTo');
check(str_contains(Carve::render(":::: tabs\n::: tab \"A\"\nx\n:::\n::::\n", ['extensions' => Tabs::extensions(true)]), 'type="radio"'), 'extensions');
try {
    Carve::render('a', ['nope' => 1]);
    check(false, 'option error');
} catch (CarveException $e) {
    check($e instanceof OptionError, 'option error');
}
