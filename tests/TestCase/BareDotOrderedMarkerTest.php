<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use PHPUnit\Framework\TestCase;

class BareDotOrderedMarkerTest extends TestCase
{
    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testBareDotRendersAsDecimalOrderedList(): void
    {
        $this->assertSame(
            "<ol>\n  <li>first</li>\n  <li>second</li>\n</ol>\n",
            $this->html(". first\n. second\n"),
        );
    }

    public function testBareDotMixesWithExplicitDecimalDot(): void
    {
        $this->assertSame(
            "<ol>\n  <li>a</li>\n  <li>b</li>\n</ol>\n",
            $this->html(". a\n2. b\n"),
        );
        $this->assertSame(
            "<ol>\n  <li>a</li>\n  <li>b</li>\n</ol>\n",
            $this->html("1. a\n. b\n"),
        );
    }

    public function testDifferentOrderedDelimiterStartsSiblingList(): void
    {
        $this->assertSame(
            "<ol>\n  <li>a</li>\n</ol>\n<ol>\n  <li>b</li>\n</ol>\n",
            $this->html(". a\n1) b\n"),
        );
        $this->assertCount(2, (new CarveConverter())->parse(". a\n1) b\n")->getChildren());
    }

    public function testContentIsRequired(): void
    {
        $this->assertInstanceOf(Paragraph::class, (new CarveConverter())->parse(".\n")->getChildren()[0]);
        $this->assertInstanceOf(Paragraph::class, (new CarveConverter())->parse(".   \n")->getChildren()[0]);
        $this->assertInstanceOf(Paragraph::class, (new CarveConverter())->parse(".x\n")->getChildren()[0]);
        $this->assertInstanceOf(Paragraph::class, (new CarveConverter())->parse(".. text\n")->getChildren()[0]);

        // The `.   ` line stays paragraph text and loses its trailing run: a
        // `whitespace` run at the end of a CONTENT LINE is dropped, on every
        // line and not just a paragraph's last (markup-carve/carve#926). What
        // this case is about - a bare marker not opening a list - is unchanged,
        // and the four `assertInstanceOf` rows above say so directly.
        $this->assertSame("<p>.\n.\n.x\n.. text</p>\n", $this->html(".\n.   \n.x\n.. text\n"));
    }

    public function testBareDotDoesNotInterruptParagraph(): void
    {
        $this->assertSame("<p>text\n. a</p>\n", $this->html("text\n. a\n"));
    }

    public function testAttributesAttachToTheListItem(): void
    {
        $this->assertSame(
            "<ol>\n  <li id=\"x\" class=\"k\">text</li>\n</ol>\n",
            $this->html(".{#x .k} text\n"),
        );
        $this->assertSame("<p>.{k=v}text</p>\n", $this->html(".{k=v}text\n"));
    }

    public function testBareMarkerIsRuntimeOnlyAndSetByTheOpener(): void
    {
        $bare = (new CarveConverter())->parse(". a\n2. b\n")->getChildren()[0];
        $explicit = (new CarveConverter())->parse("1. a\n. b\n")->getChildren()[0];

        $this->assertInstanceOf(ListBlock::class, $bare);
        $this->assertInstanceOf(ListBlock::class, $explicit);
        $this->assertTrue($bare->hasBareMarker());
        $this->assertFalse($explicit->hasBareMarker());
    }

    public function testSerializedAstCarriesBareMarker(): void
    {
        // It used to be hidden from the wire, on the grounds that `ordered`
        // carried it. `ordered` says the list is ordered; it says nothing about
        // whether the author wrote `.` with no number, so a bare-dot list came
        // back as `1.` after a round trip - the authored form PART 11 §6
        // preserves. carve-js and carve-rs both publish the field
        // (carve-php#711).
        $encoded = (new AstCodec())->encode((new CarveConverter())->parse(". a\n"));

        $this->assertSame('list', $encoded['children'][0]['type']);
        $this->assertTrue($encoded['children'][0]['bareMarker']);
    }

    public function testSerializedAstValidatesAgainstPublishedSchema(): void
    {
        // `tests/spec`, which is where the submodule is - this said
        // `dirname(__DIR__, 2)`, one level too far up, so it resolved to
        // `<root>/spec/...` and the test skipped on every machine and in every CI
        // run since it was written (carve-php#870).
        //
        // ASSERTED, not skipped: every CI job checks out with
        // `submodules: recursive`, so a missing schema means a broken checkout
        // rather than an environment without the spec.
        $schemaPath = dirname(__DIR__) . '/spec/resources/ast-schema.json';
        $this->assertFileExists(
            $schemaPath,
            'the spec submodule is missing - run `git submodule update --init --recursive`',
        );

        $json = json_encode((new AstCodec())->encode((new CarveConverter())->parse(". a\n")), JSON_THROW_ON_ERROR);
        $pythonScript = <<<'PY'
import json, sys
try:
    import jsonschema
except ImportError:
    print("SKIP: jsonschema not installed")
    sys.exit(0)
schema = json.load(open(sys.argv[1]))
doc = json.loads(sys.stdin.read())
try:
    jsonschema.validate(instance=doc, schema=schema)
    print("VALID")
except jsonschema.ValidationError as e:
    print("INVALID: " + e.message)
PY;
        $scriptPath = tempnam(sys_get_temp_dir(), 'carve-bare-dot-schema-');
        $this->assertNotFalse($scriptPath);
        file_put_contents($scriptPath, $pythonScript);

        try {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open(['python3', $scriptPath, $schemaPath], $descriptors, $pipes);
            if (!is_resource($process)) {
                $this->markTestSkipped('python3 is not available to validate the schema');
            }

            fwrite($pipes[0], $json);
            fclose($pipes[0]);
            $result = trim((string)stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            if (str_starts_with($result, 'SKIP')) {
                $this->markTestSkipped($result);
            }
            $this->assertSame('VALID', $result);
        } finally {
            unlink($scriptPath);
        }
    }

    public function testWriterUsesTheOpeningSpelling(): void
    {
        $this->assertSame(". a\n. b\n", CarveConverter::toCarve(". a\n. b\n"));
        $this->assertSame("1. a\n2. b\n", CarveConverter::toCarve("1. a\n2. b\n"));
        $this->assertSame(". a\n. b\n", CarveConverter::toCarve(". a\n2. b\n"));
    }
}
