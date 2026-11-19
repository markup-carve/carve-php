<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\PublicApi;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;

/**
 * The classes a host may use. Everything else in `MarkupCarve\Carve` is internal and may change in any release.
 */
final class PublicSurface
{
    /**
     * @var array<string, string> Class to its role; `provisional` marks the extension host (extensions.md).
     */
    public const CLASSES = [
        'MarkupCarve\Carve\Carve' => 'entry',
        'MarkupCarve\Carve\Node' => 'tree',
        'MarkupCarve\Carve\ParseResult' => 'result',
        'MarkupCarve\Carve\RenderResult' => 'result',
        'MarkupCarve\Carve\LayoutResult' => 'result',
        'MarkupCarve\Carve\Writer\WriteResult' => 'result',
        'MarkupCarve\Carve\Import\ImportResult' => 'result',
        'MarkupCarve\Carve\Diagnostics\Diagnostic' => 'result',
        'MarkupCarve\Carve\CarveException' => 'error',
        'MarkupCarve\Carve\OptionError' => 'error',
        'MarkupCarve\Carve\InputError' => 'error',
        'MarkupCarve\Carve\RenderError' => 'error',
        'MarkupCarve\Carve\Writer\CarveWriteError' => 'error',
        'MarkupCarve\Carve\Json\TreeError' => 'error',
        'MarkupCarve\Carve\Layout\LayoutError' => 'error',
        'MarkupCarve\Carve\Import\ImportInputError' => 'error',
        'MarkupCarve\Carve\Include\IncludeResolver' => 'includes',
        'MarkupCarve\Carve\Include\FileResolver' => 'includes',
        'MarkupCarve\Carve\Output\OutputWriter' => 'output',
        'MarkupCarve\Carve\Output\WriterRegistry' => 'output',
        'MarkupCarve\Carve\Output\HtmlWriter' => 'output',
        'MarkupCarve\Carve\Output\CarveWriter' => 'output',
        'MarkupCarve\Carve\Extension\ExtensionProvider' => 'provisional',
        'MarkupCarve\Carve\Extension\ExtensionDefinition' => 'provisional',
        'MarkupCarve\Carve\Extension\Claim' => 'provisional',
        'MarkupCarve\Carve\Extension\RenderHost' => 'provisional',
        'MarkupCarve\Carve\Extension\Tabs' => 'provisional',
        'MarkupCarve\Carve\Extension\DocumentTransform' => 'provisional',
        'MarkupCarve\Carve\Extension\Bundle' => 'provisional',
        'MarkupCarve\Carve\Extension\FencedRender' => 'provisional',
        'MarkupCarve\Carve\Extension\MathBlock' => 'provisional',
        'MarkupCarve\Carve\Extension\CodeGroup' => 'provisional',
        'MarkupCarve\Carve\Extension\CodeCallouts' => 'provisional',
        'MarkupCarve\Carve\Extension\Details' => 'provisional',
        'MarkupCarve\Carve\Extension\Spoiler' => 'provisional',
        'MarkupCarve\Carve\Extension\ListTable' => 'provisional',
        'MarkupCarve\Carve\Extension\ColorSwatch' => 'provisional',
        'MarkupCarve\Carve\Extension\SemanticSpan' => 'provisional',
        'MarkupCarve\Carve\Extension\Wikilinks' => 'provisional',
        'MarkupCarve\Carve\Extension\SmartQuotes' => 'provisional',
        'MarkupCarve\Carve\Extension\Transforms' => 'provisional',
    ];

    /**
     * @var array<string, string>
     */
    private const SECTIONS = [
        'entry' => 'Entry',
        'tree' => 'Tree',
        'result' => 'Results',
        'error' => 'Errors',
        'includes' => 'Includes',
        'output' => 'Output writers',
        'provisional' => 'Extensions (provisional)',
    ];

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return array<\ReflectionMethod>
     */
    public static function methods(ReflectionClass $class): array
    {
        $methods = array_filter(
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class->getName()
                && !str_starts_with($m->getName(), '__'),
        );
        usort($methods, static fn (ReflectionMethod $a, ReflectionMethod $b): int => $a->getStartLine() <=> $b->getStartLine());

        return $methods;
    }

    public static function signature(ReflectionMethod $method): string
    {
        $params = array_map(self::param(...), $method->getParameters());
        $return = $method->getReturnType();

        $text = ($method->isStatic() ? 'static ' : '') . $method->getName() . '(' . implode(', ', $params) . ')'
            . ($return === null ? '' : ': ' . self::type($return));

        // Reflection prints `self` on PHP 8.2 and the class from 8.3 on; the reference names the class.
        return (string)preg_replace('~\bself\b~', $method->getDeclaringClass()->getShortName(), $text);
    }

    /**
     * Every class name a public signature names, for the check that nothing internal leaks.
     *
     * @return array<string>
     */
    public static function referencedClasses(): array
    {
        $out = [];
        foreach (array_keys(self::CLASSES) as $name) {
            foreach (self::methods(new ReflectionClass($name)) as $method) {
                $types = array_map(static fn (ReflectionParameter $p): ?ReflectionType => $p->getType(), $method->getParameters());
                $types[] = $method->getReturnType();
                foreach ($types as $type) {
                    foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $part) {
                        $builtin = !$part instanceof ReflectionNamedType || $part->isBuiltin();
                        if (!$builtin && !in_array($part->getName(), ['self', 'static'], true)) {
                            $out[$part->getName()] = true;
                        }
                    }
                }
            }
        }

        return array_keys($out);
    }

    public static function reference(): string
    {
        $out = "# Carve 0.2 PHP API reference\n\n"
            . "Generated by `php scripts/api-reference.php` from the public classes in\n"
            . "`tests/PublicApi/PublicSurface.php`; do not edit by hand. Every other class under `MarkupCarve\\Carve` is\n"
            . "internal. Classes under \"Extensions (provisional)\" follow the extension host of the specification, which may\n"
            . "change in 0.2.x. See [the guide](README.md) for examples and [the migration guide](migration.md) for 0.1 calls.\n";
        foreach (self::SECTIONS as $role => $title) {
            $out .= "\n## " . $title . "\n";
            foreach (self::CLASSES as $name => $classRole) {
                if ($classRole !== $role) {
                    continue;
                }
                $class = new ReflectionClass($name);
                $kind = $class->isInterface() ? 'interface' : 'class';
                $out .= "\n### `" . $name . "`\n\n" . rtrim(ucfirst($kind) . '. ' . self::summary($class->getDocComment())) . "\n";
                $methods = self::methods($class);
                $props = array_filter(
                    $class->getProperties(ReflectionProperty::IS_PUBLIC),
                    static fn (ReflectionProperty $p): bool => $p->getDeclaringClass()->getName() === $name && !$p->isStatic(),
                );
                if ($props !== [] && $role !== 'tree' && $role !== 'provisional') {
                    $out .= "\nFields: " . implode(', ', array_map(
                        static fn (ReflectionProperty $p): string => '`'
                            . ($p->getType() === null ? '' : self::type($p->getType()) . ' ') . '$' . $p->getName() . '`',
                        $props,
                    )) . ".\n";
                }
                if ($methods === []) {
                    continue;
                }
                $out .= "\n";
                foreach ($methods as $method) {
                    $summary = self::summary($method->getDocComment());
                    $out .= '- `' . self::signature($method) . '`' . ($summary === '' ? '' : ': ' . $summary) . "\n";
                }
            }
        }

        return $out;
    }

    private static function param(ReflectionParameter $p): string
    {
        $type = $p->getType();
        $text = ($type === null ? '' : self::type($type) . ' ') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
        if ($p->isDefaultValueAvailable()) {
            $default = $p->getDefaultValue();
            $text .= ' = ' . match (true) {
                $default === null => 'null',
                $default === [] => '[]',
                is_bool($default) => $default ? 'true' : 'false',
                default => var_export($default, true),
            };
        }

        return $text;
    }

    private static function type(ReflectionType $type): string
    {
        $text = (string)$type;

        return (string)preg_replace('~(?:[A-Za-z0-9_]+\\\\)+([A-Za-z0-9_]+)~', '$1', $text);
    }

    private static function summary(string|false $doc): string
    {
        if ($doc === false) {
            return '';
        }
        $lines = [];
        foreach (explode("\n", $doc) as $line) {
            $line = trim(preg_replace('~^\s*/?\*+/?~', '', $line) ?? '');
            if ($line === '' && $lines !== []) {
                break;
            }
            if ($line === '' || str_starts_with($line, '@')) {
                if (str_starts_with($line, '@')) {
                    break;
                }

                continue;
            }
            $lines[] = $line;
        }

        return implode(' ', $lines);
    }
}
