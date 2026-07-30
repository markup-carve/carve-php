<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Node;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;
use SplFileInfo;

/**
 * Encodes the AST as plain arrays (JSON) and reads it back.
 *
 * The reason this exists: every non-HTML integration currently has to pivot
 * through rendered HTML and re-parse it, because the AST is only reachable as
 * PHP objects. An interchange encoding turns that into a direct hop - editors
 * (ProseMirror/Tiptap), linters, structural diffing and cross-implementation
 * conformance all want the tree, not markup.
 *
 * ## Shape
 *
 * A document is
 *
 *     {"ast": 1, "type": "document", "children": [...]}
 *
 * and every node is
 *
 *     {"type": "heading", "level": 2, "attrs": {"id": "x"}, "children": [...]}
 *
 * - `type` is the node's own `getType()` value - the same snake_case vocabulary
 *   `Profile` uses for allow/deny lists, so the names are already public.
 * - `attrs` and `children` are omitted when empty, keeping small documents small.
 * - Everything else is the node's declared state, keyed by property name.
 *
 * Field names are derived by reflection over properties declared on the node
 * class itself (the base class bookkeeping - parent, children, attributes,
 * attributeOrder - is excluded). That means a new node type is encodable the day
 * it is added, with no table to forget. The consequence is that renaming a
 * property is a wire-format change, which is what AstCodecSchemaTest pins: the
 * full type-to-fields map is a golden file (tests/fixtures/ast-schema.json), so
 * any rename shows up as a diff to approve rather than a silent break for
 * consumers.
 */
class AstCodec
{
    /**
     * Encoding version. Bump when the shape changes in a way consumers must
     * notice; the spec version of the content itself is a separate concern.
     *
     * @var int
     */
    public const VERSION = 1;

    /**
     * Base-class state that is either structural (children) or not part of the
     * tree's identity (parent, and the attribute bookkeeping handled by attrs).
     *
     * @var array<string>
     */
    private const BASE_PROPERTIES = ['parent', 'children', 'attributes', 'attributeOrder'];

    /**
     * @var array<string, class-string<\MarkupCarve\Carve\Node\Node>>|null
     */
    private static ?array $classMap = null;

    /**
     * Node classes registered by consuming applications, which is how an app's
     * own node types become encodable without patching this package.
     *
     * @var array<string, class-string<\MarkupCarve\Carve\Node\Node>>
     */
    private static array $registered = [];

    /**
     * Per-property default cache, keyed by class and property name.
     *
     * @var array<string, array{has: bool, value: mixed}>
     */
    private static array $defaults = [];

    /**
     * Teach the codec a node class defined outside this package.
     *
     * @param class-string<\MarkupCarve\Carve\Node\Node> $class
     */
    public static function register(string $class): void
    {
        $reflection = new ReflectionClass($class);
        /** @var \MarkupCarve\Carve\Node\Node $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        self::$registered[$instance->getType()] = $class;
        self::$classMap = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function encode(Document $document): array
    {
        $encoded = $this->encodeNode($document);
        $encoded['ast'] = self::VERSION;

        return $encoded;
    }

    public function encodeJson(Document $document, int $flags = 0): string
    {
        return (string)json_encode($this->encode($document), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException When the payload is not a document this version can read.
     */
    public function decode(array $data): Document
    {
        $version = $data['ast'] ?? null;
        if ($version !== null && $version !== self::VERSION) {
            throw new RuntimeException(sprintf(
                'Unsupported AST encoding version: %s',
                is_scalar($version) ? (string)$version : get_debug_type($version),
            ));
        }

        $node = $this->decodeNode($data);
        if (!$node instanceof Document) {
            throw new RuntimeException('The payload root must be a document node');
        }

        return $node;
    }

    public function decodeJson(string $json): Document
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->decode($data);
    }

    /**
     * The encodable fields per node type, and which of them a payload must
     * carry, for documentation and drift tests.
     *
     * A field is required when the node has no default for it - neither a
     * declared property default nor a constructor parameter default - so there
     * is nothing to fall back on when it is omitted.
     *
     * @return array<string, array{fields: array<string>, required: array<string>}>
     */
    public static function schema(): array
    {
        $schema = [];
        foreach (self::classMap() as $type => $class) {
            $reflection = new ReflectionClass($class);
            $fields = [];
            $required = [];
            foreach (self::stateProperties($reflection) as $property) {
                $fields[] = $property->getName();
                if (!self::defaultFor($reflection, $property)['has']) {
                    $required[] = $property->getName();
                }
            }
            $schema[$type] = ['fields' => $fields, 'required' => $required];
        }
        ksort($schema);

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeNode(Node $node): array
    {
        $encoded = ['type' => $node->getType()];

        $reflection = new ReflectionClass($node);
        foreach (self::stateProperties($reflection) as $property) {
            $value = $property->isInitialized($node) ? $property->getValue($node) : null;
            $default = self::defaultFor($reflection, $property);

            // Omit a field only when it holds the node's own default. Omitting
            // every null/[]/false instead would lose information wherever the
            // default is not falsy: a loose list (tight = false, default true)
            // encoded without `tight` and decoded back as tight.
            if ($default['has'] && $value === $default['value']) {
                continue;
            }

            $encoded[$property->getName()] = $this->encodeValue($value);
        }

        $attributes = $node->getAttributes();
        if ($attributes !== []) {
            $encoded['attrs'] = $attributes;
        }

        $children = $node->getChildren();
        if ($children !== []) {
            $encoded['children'] = array_map(fn (Node $child): array => $this->encodeNode($child), $children);
        }

        return $encoded;
    }

    /**
     * Node-valued state (a div's header nodes, a table caption) is encoded the
     * same way as children, so nothing in the tree needs a second format.
     */
    private function encodeValue(mixed $value): mixed
    {
        if ($value instanceof Node) {
            return $this->encodeNode($value);
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->encodeValue($item), $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException When the node type is unknown.
     */
    private function decodeNode(array $data): Node
    {
        $type = $data['type'] ?? null;
        if (!is_string($type)) {
            throw new RuntimeException('Every node needs a string type');
        }

        $class = self::classMap()[$type] ?? null;
        if ($class === null) {
            throw new RuntimeException(sprintf(
                'Unknown node type: %s. Application node types must be registered with %s::register().',
                $type,
                self::class,
            ));
        }

        $reflection = new ReflectionClass($class);
        /** @var \MarkupCarve\Carve\Node\Node $node */
        $node = $reflection->newInstanceWithoutConstructor();

        foreach (self::stateProperties($reflection) as $property) {
            $name = $property->getName();
            if (!array_key_exists($name, $data)) {
                // Omission means "the default". The constructor was bypassed, so
                // a typed property without a declared default would otherwise stay
                // uninitialized and throw on first read - initialize it here, or
                // reject the node when it has no sensible default to fall back on.
                $this->initializeDefault($node, $property, $type);

                continue;
            }
            $property->setValue($node, $this->decodeValue($data[$name], $property));
        }

        /** @var array<string, string> $attrs */
        $attrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
        foreach ($attrs as $key => $value) {
            $node->setAttribute((string)$key, $value);
        }

        /** @var array<int, array<string, mixed>> $children */
        $children = is_array($data['children'] ?? null) ? $data['children'] : [];
        foreach ($children as $child) {
            $node->appendChild($this->decodeNode($child));
        }

        return $node;
    }

    /**
     * Give an omitted property the value the constructor would have given it.
     *
     * A non-nullable property with no declared default has no such value, so it
     * is required: omitting it used to yield a zero. That produced nonsense
     * rather than an error - a heading without a level rendered as `<h0>` - so
     * those are rejected instead. The encoder never omits them (it only omits
     * null, [] and false), so this only ever fires on hand-written or foreign
     * trees, which is exactly where it is wanted.
     *
     * @throws \RuntimeException When a required field is missing.
     */
    private function initializeDefault(Node $node, ReflectionProperty $property, string $nodeType): void
    {
        $default = self::defaultFor(new ReflectionClass($node), $property);

        if (!$default['has']) {
            throw new RuntimeException(sprintf(
                'Node "%s" is missing the required field "%s"',
                $nodeType,
                $property->getName(),
            ));
        }

        $property->setValue($node, $default['value']);
    }

    /**
     * The value a node gives a property when nobody sets it: the declared
     * property default, else the matching constructor parameter default. When
     * there is neither, the field is required - inventing a scalar zero there
     * produced nonsense (a heading without a level rendered as `<h0>`).
     *
     * @param \ReflectionClass<\MarkupCarve\Carve\Node\Node> $reflection
     * @param \ReflectionProperty $property
     *
     * @return array{has: bool, value: mixed}
     */
    private static function defaultFor(ReflectionClass $reflection, ReflectionProperty $property): array
    {
        $cacheKey = $reflection->getName() . '::' . $property->getName();
        if (array_key_exists($cacheKey, self::$defaults)) {
            return self::$defaults[$cacheKey];
        }

        $default = ['has' => false, 'value' => null];

        if ($property->hasDefaultValue()) {
            $default = ['has' => true, 'value' => $property->getDefaultValue()];
        } else {
            $constructor = $reflection->getConstructor();
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                if ($parameter->getName() === $property->getName() && $parameter->isDefaultValueAvailable()) {
                    $default = ['has' => true, 'value' => $parameter->getDefaultValue()];

                    break;
                }
            }
        }

        self::$defaults[$cacheKey] = $default;

        return $default;
    }

    private function decodeValue(mixed $value, ReflectionProperty $property): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['type']) && is_string($value['type']) && $this->expectsNode($property)) {
            /** @var array<string, mixed> $value */
            return $this->decodeNode($value);
        }

        return array_map(function (mixed $item) use ($property): mixed {
            if (is_array($item) && isset($item['type']) && is_string($item['type'])) {
                /** @var array<string, mixed> $item */
                return $this->decodeNode($item);
            }

            return $this->decodeValue($item, $property);
        }, $value);
    }

    private function expectsNode(ReflectionProperty $property): bool
    {
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }

        return is_a($type->getName(), Node::class, true);
    }

    /**
     * Properties that carry the node's own state, parent-class bookkeeping aside.
     *
     * @param \ReflectionClass<\MarkupCarve\Carve\Node\Node> $reflection
     *
     * @return array<\ReflectionProperty>
     */
    private static function stateProperties(ReflectionClass $reflection): array
    {
        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic() || in_array($property->getName(), self::BASE_PROPERTIES, true)) {
                continue;
            }
            $properties[] = $property;
        }

        return $properties;
    }

    /**
     * Type name to node class, discovered from the node directories so a new
     * node type is encodable without registering it anywhere.
     *
     * @return array<string, class-string<\MarkupCarve\Carve\Node\Node>>
     */
    private static function classMap(): array
    {
        if (self::$classMap !== null) {
            return self::$classMap;
        }

        $map = [];
        $root = dirname(__DIR__);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            // Node classes are not confined to Node/: extensions ship their own
            // (Extension\Frontmatter), which the corpus round-trip caught.
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'MarkupCarve\\Carve\\' . str_replace('/', '\\', $relative);
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(Node::class)) {
                continue;
            }

            /** @var \MarkupCarve\Carve\Node\Node $instance */
            $instance = $reflection->newInstanceWithoutConstructor();
            /** @var class-string<\MarkupCarve\Carve\Node\Node> $nodeClass */
            $nodeClass = $class;
            $map[$instance->getType()] = $nodeClass;
        }

        self::$classMap = $map + self::$registered;

        return self::$classMap;
    }
}
