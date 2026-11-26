<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\PublicApi;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PublicSurfaceTest extends TestCase
{
    /**
     * The calls of `Carve`; a change here is an API change and needs a migration note.
     */
    public function testTheEntryCallsArePinned(): void
    {
        $methods = PublicSurface::methods(new ReflectionClass(Carve::class));
        $names = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $methods);
        sort($names);
        $this->assertSame([
            'applyPatch', 'createEditorSession', 'decodeTree', 'diffTree', 'encodeEnvelope', 'encodeTree', 'format',
            'formatWithDiagnostics',
            'headingId', 'importDocument', 'importHtml', 'includeDependencies', 'lint', 'migrateTree', 'parse', 'parseWithDiagnostics',
            'parseWithLayout', 'plainText', 'positionOf', 'render', 'renderWithDiagnostics', 'renderWithDocumentTransforms',
            'safeCodeFence', 'selectIncludeRegion', 'sourceFingerprint', 'supportsIncrementalParagraphReuse', 'tableGrid', 'toHtml',
            'toJson',
            'treeFingerprint', 'tryRenderHtmlStreaming', 'validateLayout',
            'validateTree', 'version', 'write', 'writeTo', 'writeWithDiagnostics', 'writers',
        ], $names);
    }

    public function testEveryCallOfToolingIsOffered(): void
    {
        $cli = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/data/cli.json'), true);
        $this->assertIsArray($cli);
        $missing = [];
        foreach ($cli['library'] ?? [] as $call) {
            $name = is_array($call) ? ($call['name'] ?? null) : null;
            if (is_string($name) && !method_exists(Carve::class, $name)) {
                $missing[] = $name;
            }
        }
        $this->assertSame([], $missing);
    }

    public function testPublicSignaturesNameOnlyPublicClasses(): void
    {
        $leaks = array_values(array_filter(
            PublicSurface::referencedClasses(),
            static fn (string $name): bool => !isset(PublicSurface::CLASSES[$name]) && !in_array($name, ['Closure', 'WeakMap'], true),
        ));
        $this->assertSame([], $leaks);
    }

    public function testTheReferenceIsCurrent(): void
    {
        $this->assertSame(
            PublicSurface::reference(),
            file_get_contents(dirname(__DIR__, 2) . '/docs/api.md'),
            'run php scripts/api-reference.php',
        );
    }
}
