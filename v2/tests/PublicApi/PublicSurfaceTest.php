<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\PublicApi;

use MarkupCarve\Carve2\Carve2;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PublicSurfaceTest extends TestCase
{
    /**
     * The calls of `Carve2`; a change here is an API change and needs a migration note.
     */
    public function testTheEntryCallsArePinned(): void
    {
        $methods = PublicSurface::methods(new ReflectionClass(Carve2::class));
        $names = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $methods);
        sort($names);
        $this->assertSame([
            'decodeTree', 'encodeEnvelope', 'encodeTree', 'format', 'formatWithDiagnostics', 'importDocument', 'importHtml',
            'includeDependencies', 'lint', 'parse', 'parseWithDiagnostics', 'parseWithLayout', 'render', 'renderWithDiagnostics',
            'toHtml', 'toJson', 'validateLayout', 'validateTree', 'write', 'writeTo', 'writeWithDiagnostics', 'writers',
        ], $names);
    }

    public function testEveryCallOfToolingIsOffered(): void
    {
        $cli = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/data/cli.json'), true);
        $this->assertIsArray($cli);
        $missing = [];
        foreach ($cli['library'] ?? [] as $call) {
            $name = is_array($call) ? ($call['name'] ?? null) : null;
            if (is_string($name) && !method_exists(Carve2::class, $name)) {
                $missing[] = $name;
            }
        }
        // migrateTree (C2-A-178) has no PHP implementation yet.
        $this->assertSame(['migrateTree'], $missing);
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
            file_get_contents(dirname(__DIR__, 3) . '/docs/v2/api.md'),
            'run php v2/scripts/api-reference.php',
        );
    }
}
