<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use PHPUnit\Framework\TestCase;

/**
 * The drift check itself, on maps written to make each assertion fire.
 *
 * `scripts/check-schema-map.php` needs a carve-grammars checkout, so what CI
 * runs cannot be exercised here. What can is every comparison it decides on -
 * and it has to be, because the test this replaces was satisfied by
 * construction: `testEveryAstTypeHasAMappedOrUnmappedDecision` passes the moment
 * a local entry exists, whatever upstream says, so it FORCED the drift it was
 * supposed to catch (carve-php#2326). A gate nobody has watched fail is
 * decoration.
 */
final class SchemaMapProvenanceTest extends TestCase
{
    public function testAnEntryOnlyThisCopyHasIsAnUndeclaredDifference(): void
    {
        $ours = SchemaMapProvenance::decisions(['unmapped' => ['ruby' => 'no node upstream']]);
        $theirs = SchemaMapProvenance::decisions(['unmapped' => []]);

        $result = SchemaMapProvenance::compare($ours, $theirs, [], 'the pin');

        $this->assertSame(['ruby (here: unmapped, the pin: no decision)'], $result['undeclared']);
        $this->assertSame([], $result['used']);
    }

    public function testADeclaredDifferenceIsAcceptedAndCountsAsUsed(): void
    {
        $ours = SchemaMapProvenance::decisions(['unmapped' => ['ruby' => 'no node upstream']]);
        $theirs = SchemaMapProvenance::decisions(['unmapped' => []]);

        $result = SchemaMapProvenance::compare($ours, $theirs, ['ruby' => 'upstream names none yet'], 'the pin');

        $this->assertSame([], $result['undeclared']);
        $this->assertSame(['ruby'], $result['used']);
    }

    public function testAChangedProseMirrorNameIsADifference(): void
    {
        $ours = SchemaMapProvenance::decisions(['types' => ['div' => ['kind' => 'node', 'pm' => 'carveDiv']]]);
        $theirs = SchemaMapProvenance::decisions(['types' => ['div' => ['kind' => 'node', 'pm' => 'carveBlock']]]);

        $result = SchemaMapProvenance::compare($ours, $theirs, [], 'main');

        $this->assertSame(
            ['div (here: type|"node"|"carveDiv"|null, main: type|"node"|"carveBlock"|null)'],
            $result['undeclared'],
        );
    }

    public function testMovingATypeBetweenMappedAndUnmappedIsADifference(): void
    {
        $ours = SchemaMapProvenance::decisions(['unmapped' => ['abbreviation_def' => 'rides on doc attrs']]);
        $theirs = SchemaMapProvenance::decisions([
            'types' => ['abbreviation_def' => ['kind' => 'node', 'pm' => 'carveAbbreviationDefinition']],
        ]);

        $result = SchemaMapProvenance::compare($ours, $theirs, [], 'main');

        $this->assertCount(1, $result['undeclared']);
        $this->assertStringContainsString('carveAbbreviationDefinition', $result['undeclared'][0]);
    }

    public function testARenamedCarrierNodeIsADifference(): void
    {
        $ours = SchemaMapProvenance::decisions([
            'markCarrierNodes' => ['about' => 'prose', 'carveEmptyMark' => ['kind' => 'node']],
        ]);
        $theirs = SchemaMapProvenance::decisions([
            'markCarrierNodes' => ['about' => 'prose', 'carveVoidMark' => ['kind' => 'node']],
        ]);

        $result = SchemaMapProvenance::compare($ours, $theirs, [], 'main');

        $this->assertSame(
            [
                'carrier:carveEmptyMark (here: carrier|markCarrierNodes, main: no decision)',
                'carrier:carveVoidMark (here: no decision, main: carrier|markCarrierNodes)',
            ],
            $result['undeclared'],
        );
    }

    public function testASectionsOwnProseIsNotACarrierNode(): void
    {
        $decisions = SchemaMapProvenance::decisions([
            'preservationNodes' => ['about' => 'what this section is for', 'carveUnsupported' => ['kind' => 'node']],
        ]);

        $this->assertSame(['carrier:carveUnsupported' => 'carrier|preservationNodes'], $decisions);
    }

    public function testProseAndAttributeDocumentationAreNotDecisions(): void
    {
        $ours = ['types' => ['div' => ['kind' => 'node', 'pm' => 'carveDiv', 'notes' => 'ours', 'attrs' => ['id' => 'a']]]];
        $theirs = ['types' => ['div' => ['kind' => 'node', 'pm' => 'carveDiv', 'notes' => 'theirs', 'attrs' => ['id' => 'b']]]];

        $result = SchemaMapProvenance::compare(
            SchemaMapProvenance::decisions($ours),
            SchemaMapProvenance::decisions($theirs),
            [],
            'the pin',
        );

        $this->assertSame([], $result['undeclared'], 'prose is not gated: an upstream sentence is not this engine to act on');
        $this->assertSame(['types.div'], SchemaMapProvenance::prose($ours, $theirs), 'but it is reported');
    }

    public function testADeclarationThatNoLongerDiffersIsRefused(): void
    {
        $divergences = ['ruby' => 'upstream names none yet', 'small_caps' => 'upstream names none yet'];

        $this->assertSame(
            ['small_caps'],
            SchemaMapProvenance::staleDivergences($divergences, ['ruby']),
        );
    }

    public function testAnAbbreviatedCommitIsRefused(): void
    {
        $provenance = SchemaMapProvenance::provenance([
            '_provenance' => ['source' => 'markup-carve/carve-grammars tiptap/schema-map.json', 'commit' => '228f003'],
        ]);

        $this->assertSame(['commit_well_formed'], array_column($provenance['failures'], 'check'));
    }

    public function testAMapWithNoProvenanceBlockIsRefused(): void
    {
        $provenance = SchemaMapProvenance::provenance(['types' => []]);

        $this->assertSame(['provenance_present'], array_column($provenance['failures'], 'check'));
        $this->assertNull($provenance['commit']);
    }

    public function testASourceNamingNoSingleJsonPathIsRefused(): void
    {
        $this->assertNull(SchemaMapProvenance::sourcePath('markup-carve/carve-grammars'));
        $this->assertNull(SchemaMapProvenance::sourcePath('a/one.json and b/two.json'));
        $this->assertSame(
            'tiptap/schema-map.json',
            SchemaMapProvenance::sourcePath('markup-carve/carve-grammars tiptap/schema-map.json'),
        );
    }

    /**
     * The shipped map's own block, which needs no checkout to read.
     *
     * The comparisons above run on fixtures; this is the one assertion here that
     * the real file has to satisfy, so a refresh that abbreviates the commit or
     * renames the source fails in the suite rather than only in the CI job.
     */
    public function testTheVendoredMapCarriesAReadableProvenanceBlock(): void
    {
        $path = dirname(__DIR__, 3) . '/resources/prosemirror-schema-map.json';
        /** @var array<string, mixed> $map */
        $map = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $provenance = SchemaMapProvenance::provenance($map);

        $this->assertSame([], $provenance['failures']);
        $this->assertSame('tiptap/schema-map.json', $provenance['path']);
        $this->assertNotSame([], $provenance['divergences'], 'every difference from upstream is named here');
    }
}
