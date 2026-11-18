<?php

declare(strict_types=1);

// Run by consumer-smoke.sh inside a scratch project: one call of every public entry of Carve2.

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\CarveException;
use MarkupCarve\Carve2\Extension\Tabs;
use MarkupCarve\Carve2\Include\FileResolver;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\OptionError;
use MarkupCarve\Carve2\Output\OutputWriter;

require __DIR__ . '/vendor/autoload.php';

function check(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL $what\n");
        exit(1);
    }
    echo "ok $what\n";
}

$dir = sys_get_temp_dir() . '/carve2-smoke-' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/part.crv', "Part.\n");
$includes = ['includes' => FileResolver::forRoot($dir)];

$tree = Carve2::parse("# T\n\n{{ part.crv }}\n", $includes);
check($tree->type === 'document', 'parse');
check(Carve2::parseWithDiagnostics("{#a}\nx\n\n{#a}\ny\n")->codes() === ['duplicate-id'], 'parseWithDiagnostics');
$layout = Carve2::parseWithLayout("- a\n");
check(is_array(Carve2::validateLayout((string)json_encode($layout->layout))), 'parseWithLayout, validateLayout');
check(str_contains(Carve2::render($tree), '<p>Part.</p>'), 'render tree');
check(Carve2::render("a\n") === '<p>a</p>', 'render source');
check(Carve2::toHtml("a\n") === '<p>a</p>', 'toHtml');
check(Carve2::renderWithDiagnostics('{{ x.crv }}')->codes() === ['include-no-resolver', 'include-unresolved'], 'renderWithDiagnostics');
check(str_starts_with(Carve2::toJson("a\n"), '{"type":"document"'), 'toJson');
check(Carve2::lint("{#a}\nx\n\n{#a}\ny\n")[0]->code === 'duplicate-id', 'lint');
check(Carve2::includeDependencies($tree)[0]['read'] === true, 'includeDependencies');
check(Carve2::format("a  \nb\n") === "a\nb\n", 'format');
check(Carve2::formatWithDiagnostics("a\n")->diagnostics === [], 'formatWithDiagnostics');
check(Carve2::write(Carve2::parse("x\n")) === "x\n", 'write');
check(Carve2::writeWithDiagnostics(Carve2::parse("x\n"))->source === "x\n", 'writeWithDiagnostics');
check(Carve2::importDocument('<p>Hi</p>')->source === "Hi\n", 'importDocument');
check(Carve2::importHtml('<p>Hi</p>')->source === "Hi\n", 'importHtml');
$decoded = Carve2::decodeTree(Carve2::encodeEnvelope(Carve2::parse("a\n")));
check(Carve2::encodeTree($decoded) === Carve2::encodeTree(Carve2::decodeTree(Carve2::encodeTree($decoded))), 'encodeTree, encodeEnvelope, decodeTree');
Carve2::validateTree($decoded);
check(true, 'validateTree');
$registry = Carve2::writers()->register(new class implements OutputWriter {
    public function target(): string
    {
        return 'stub';
    }

    public function write(Node $tree, array $options = []): string
    {
        return $tree->type;
    }
});
check(Carve2::writeTo('stub', $decoded, [], $registry) === 'document', 'writers, writeTo');
check(str_contains(Carve2::render(":::: tabs\n::: tab \"A\"\nx\n:::\n::::\n", ['extensions' => Tabs::extensions(true)]), 'type="radio"'), 'extensions');
try {
    Carve2::render('a', ['nope' => 1]);
    check(false, 'option error');
} catch (CarveException $e) {
    check($e instanceof OptionError, 'option error');
}
