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

        $this->assertSame("<p>.\n.   \n.x\n.. text</p>\n", $this->html(".\n.   \n.x\n.. text\n"));
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
        // PART 12 §3 lists `bareMarker` among the AUTHOR-CHOICE fields, and the
        // published schema defines it as `"const": true` with the reason: the
        // bare dot is the one authored marker distinction with no other field
        // to hold it, so without this the form decodes as `1. a`.
        //
        // This asserted the field was ABSENT until #711 - it pinned the loss
        // rather than the rule, and the round trip failed on two corpus
        // documents because of it.
        $encoded = (new AstCodec())->encode((new CarveConverter())->parse(". a\n"));

        $this->assertSame('list', $encoded['children'][0]['type']);
        $this->assertTrue($encoded['children'][0]['bareMarker'] ?? null);
    }

    public function testANumberedListCarriesNoBareMarker(): void
    {
        // Absent means the author numbered it, so the field must not appear
        // for `1. a` - it is a marker of authored choice, not a default.
        $encoded = (new AstCodec())->encode((new CarveConverter())->parse("1. a\n"));

        $this->assertArrayNotHasKey('bareMarker', $encoded['children'][0]);
    }

    public function testSerializedAstValidatesAgainstPublishedSchema(): void
    {
        $schemaPath = dirname(__DIR__, 2) . '/spec/resources/ast-schema.json';
        if (!is_file($schemaPath)) {
            $this->markTestSkipped('the published AST schema fixture is not available');
        }

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
