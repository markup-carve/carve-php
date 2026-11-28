<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use InvalidArgumentException;
use MarkupCarve\Carve\Node;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * The configuration names of the extensions a host can switch on, as the framework adapters accept them.
 *
 * Options use snake_case and bind by name to the factory's camelCase parameter: `css_class` is `$cssClass`.
 */
enum Name: string
{
    /**
     * The factory behind each name that takes its options by parameter name.
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    private const FACTORIES = [
        'abc' => [FencedRender::class, 'abc'],
        'autolink' => [Navigation::class, 'autolink'],
        'chart' => [FencedRender::class, 'chart'],
        'citations' => [Citations::class, 'bundle'],
        'code_callouts' => [CodeCallouts::class, 'bundle'],
        'code_group' => [CodeGroup::class, 'bundle'],
        'color_swatch' => [ColorSwatch::class, 'bundle'],
        'd2' => [FencedRender::class, 'd2'],
        'default_attributes' => [Transforms::class, 'defaultAttributes'],
        'details' => [Details::class, 'bundle'],
        'external_links' => [Navigation::class, 'externalLinks'],
        'fenced_render' => [FencedRender::class, 'bundle'],
        'glossary' => [Glossary::class, 'bundle'],
        'graphviz' => [FencedRender::class, 'graphviz'],
        'heading_numbers' => [Navigation::class, 'headingNumbers'],
        'heading_permalinks' => [Navigation::class, 'headingPermalinks'],
        'heading_reference' => [Navigation::class, 'headingReference'],
        'index' => [IndexTerms::class, 'bundle'],
        'index_terms' => [IndexTerms::class, 'bundle'],
        'inline_footnotes' => [Transforms::class, 'inlineFootnotesToParentheses'],
        'link_policy' => [Navigation::class, 'linkPolicy'],
        'list_table' => [ListTable::class, 'bundle'],
        'math_block' => [MathBlock::class, 'bundle'],
        'mermaid' => [FencedRender::class, 'mermaid'],
        'semantic_span' => [SemanticSpan::class, 'bundle'],
        'smart_quotes' => [SmartQuotes::class, 'bundle'],
        'spoiler' => [Spoiler::class, 'bundle'],
        'tab_normalize' => [Transforms::class, 'tabNormalize'],
        'table_of_contents' => [Navigation::class, 'tableOfContents'],
        'toc_placement' => [Navigation::class, 'tocPlacement'],
        'vega_lite' => [FencedRender::class, 'vegaLite'],
        'wavedrom' => [FencedRender::class, 'wavedrom'],
    ];

    case Abc = 'abc';
    case Autolink = 'autolink';
    case Chart = 'chart';
    case Citations = 'citations';
    case CodeCallouts = 'code_callouts';
    case CodeGroup = 'code_group';
    case ColorSwatch = 'color_swatch';
    case D2 = 'd2';
    case DefaultAttributes = 'default_attributes';
    case Details = 'details';
    case ExternalLinks = 'external_links';
    case FencedRender = 'fenced_render';
    case Glossary = 'glossary';
    case Graphviz = 'graphviz';
    case HeadingNumbers = 'heading_numbers';
    case HeadingPermalinks = 'heading_permalinks';
    case HeadingReference = 'heading_reference';
    case ImgFence = 'img_fence';
    case Index = 'index';
    case IndexTerms = 'index_terms';
    case InlineFootnotes = 'inline_footnotes';
    case LinkPolicy = 'link_policy';
    case ListTable = 'list_table';
    case MathBlock = 'math_block';
    case Mermaid = 'mermaid';
    case Plantuml = 'plantuml';
    case SemanticSpan = 'semantic_span';
    case SmartQuotes = 'smart_quotes';
    case Spoiler = 'spoiler';
    case TabNormalize = 'tab_normalize';
    case TableOfContents = 'table_of_contents';
    case Tabs = 'tabs';
    case TocPlacement = 'toc_placement';
    case VegaLite = 'vega_lite';
    case Wavedrom = 'wavedrom';
    case Wikilinks = 'wikilinks';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $name): string => $name->value, self::cases());
    }

    /**
     * Builds the extensions this name stands for.
     *
     * @param array<mixed, mixed> $options Configuration options without the `type` key.
     *
     * @return list<\MarkupCarve\Carve\Extension\ExtensionDefinition|\MarkupCarve\Carve\Extension\ExtensionProvider>
     */
    public function create(array $options = []): array
    {
        return match ($this) {
            self::Tabs => $this->tabs($options),
            self::Plantuml => $this->plantuml($options),
            self::ImgFence => $this->imgFence($options),
            self::Wikilinks => $this->wikilinks($options),
            default => [$this->bind($options)],
        };
    }

    /**
     * @param array<mixed, mixed> $options
     *
     * @throws \InvalidArgumentException
     */
    private function bind(array $options): ExtensionDefinition|ExtensionProvider
    {
        [$class, $method] = self::FACTORIES[$this->value] ?? throw new InvalidArgumentException(
            sprintf('Carve extension "%s" has no single factory.', $this->value),
        );
        $factory = $class::$method(...);
        $parameters = [];
        foreach ((new ReflectionFunction($factory))->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }
        $arguments = [];
        foreach ($options as $key => $value) {
            $name = is_string($key) ? lcfirst(str_replace('_', '', ucwords($key, '_'))) : '';
            $this->checkOption($key, $value, $parameters[$name] ?? null);
            $arguments[$name] = $value;
        }
        foreach ($parameters as $name => $parameter) {
            if (!$parameter->isOptional() && !array_key_exists($name, $arguments)) {
                throw new InvalidArgumentException(sprintf(
                    'Carve extension "%s" needs the option "%s".',
                    $this->value,
                    strtolower((string)preg_replace('/[A-Z]/', '_$0', $name)),
                ));
            }
        }
        $extension = $factory(...$arguments);
        if (!$extension instanceof ExtensionDefinition && !$extension instanceof ExtensionProvider) {
            throw new InvalidArgumentException(sprintf('Carve extension "%s" returned no definition.', $this->value));
        }

        return $extension;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function checkOption(string|int $key, mixed $value, ?ReflectionParameter $parameter): void
    {
        $kind = $parameter?->getType();
        $typeNames = match (true) {
            $kind instanceof ReflectionNamedType => [$kind->getName()],
            $kind instanceof ReflectionUnionType => array_map(
                static fn (ReflectionType $member): string => $member instanceof ReflectionNamedType
                    ? $member->getName() : 'mixed',
                $kind->getTypes(),
            ),
            default => ['mixed'],
        };
        // A closure option is code, which a configuration file cannot hold.
        if ($parameter === null || in_array(Closure::class, $typeNames, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown Carve extension option "%s" for "%s".',
                (string)$key,
                $this->value,
            ));
        }
        if ($value === null && $kind !== null && !$kind->allowsNull()) {
            throw new InvalidArgumentException(sprintf('Carve extension option "%s" must not be null.', $key));
        }
        $matching = array_filter($typeNames, static fn (string $typeName): bool => self::matches($value, $typeName));
        if ($value !== null && $matching === []) {
            throw new InvalidArgumentException(sprintf(
                'Carve extension option "%s" for "%s" must be %s.',
                $key,
                $this->value,
                (string)$kind,
            ));
        }
    }

    private static function matches(mixed $value, string $type): bool
    {
        return match ($type) {
            'array' => is_array($value),
            'bool' => is_bool($value),
            'int' => is_int($value),
            'string' => is_string($value),
            'null' => $value === null,
            'mixed' => true,
            default => false,
        };
    }

    /**
     * @param array<mixed, mixed> $options
     * @param list<string> $accepted
     *
     * @throws \InvalidArgumentException
     */
    private static function acceptKeys(array $options, array $accepted): void
    {
        foreach (array_keys($options) as $key) {
            if (!is_string($key) || !in_array($key, $accepted, true)) {
                throw new InvalidArgumentException(sprintf('Unknown Carve extension option "%s".', (string)$key));
            }
        }
    }

    /**
     * @param array<mixed, mixed> $options
     *
     * @throws \InvalidArgumentException
     *
     * @return list<\MarkupCarve\Carve\Extension\ExtensionDefinition>
     */
    private function tabs(array $options): array
    {
        self::acceptKeys($options, ['mode']);
        $mode = $options['mode'] ?? 'css';
        if (!in_array($mode, ['css', 'aria'], true)) {
            throw new InvalidArgumentException('Carve tabs mode must be css or aria.');
        }

        return array_values(Tabs::extensions(true, $mode));
    }

    /**
     * @param array<mixed, mixed> $options
     *
     * @throws \InvalidArgumentException
     *
     * @return list<\MarkupCarve\Carve\Extension\ExtensionDefinition>
     */
    private function imgFence(array $options): array
    {
        self::acceptKeys($options, ['language']);
        $languages = $options['language'] ?? ['img', 'image'];
        if (is_string($languages)) {
            $languages = [$languages];
        }
        if (!is_array($languages) || $languages === [] || array_filter($languages, 'is_string') !== $languages) {
            throw new InvalidArgumentException('img_fence language must be a fence name or a list of them.');
        }

        // ImgFence::definition() carries no renderer; the bundle only knows the default languages.
        return array_values(array_map(
            static fn (string $language): ExtensionDefinition => ImgFence::definition($language)
                ->withRenderer(ImgFence::render(...))
                ->withBuildTimeRenderer(ImgFence::render(...)),
            $languages,
        ));
    }

    /**
     * @param array<mixed, mixed> $options
     *
     * @throws \InvalidArgumentException
     *
     * @return list<\MarkupCarve\Carve\Extension\ExtensionProvider>
     */
    private function wikilinks(array $options): array
    {
        self::acceptKeys($options, ['url_template', 'css_class', 'new_window']);
        $template = $options['url_template'] ?? null;
        if ($template !== null && !is_string($template)) {
            throw new InvalidArgumentException('Wikilinks url_template must be a string.');
        }
        $class = $options['css_class'] ?? 'wikilink';
        $newWindow = $options['new_window'] ?? false;
        if (!is_string($class) || !is_bool($newWindow)) {
            throw new InvalidArgumentException('Wikilinks css_class must be a string and new_window a boolean.');
        }
        $url = $template === null ? null : static fn (string $page): string => str_replace(
            '{page}',
            Wikilinks::slug($page),
            $template,
        );

        return [Wikilinks::bundle($url, $class, $newWindow)];
    }

    /**
     * @param array<mixed, mixed> $options
     *
     * @throws \InvalidArgumentException
     *
     * @return list<\MarkupCarve\Carve\Extension\ExtensionDefinition>
     */
    private function plantuml(array $options): array
    {
        self::acceptKeys($options, ['css_class']);
        $class = $options['css_class'] ?? 'plantuml';
        if (!is_string($class) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $class)) {
            throw new InvalidArgumentException('Carve plantuml css_class must be one CSS class name.');
        }

        return [self::plantumlFence('plantuml', $class), self::plantumlFence('puml', $class)];
    }

    private static function plantumlFence(string $language, string $class): ExtensionDefinition
    {
        $name = 'carve.plantuml.' . $language;

        return new ExtensionDefinition(
            $name,
            '1',
            [],
            matcher: ['form' => 'fence', 'name' => $language],
            transform: static function (Claim $claim) use ($name): Node {
                $node = new Node('block_extension', []);
                $node->f = [
                    'name' => $name,
                    'version' => '1',
                    'slots' => [],
                    'props' => ['body' => $claim->source['body'] ?? ''],
                    'fallback' => [$claim->copyNode()],
                ];

                return $node;
            },
            renderer: static function (Node $node, RenderHost $host) use ($class): string {
                $props = $node->f['props'] ?? [];
                $body = is_array($props) ? ($props['body'] ?? '') : '';

                return '<pre class="' . RenderHost::escapeAttribute($class) . '">'
                    . RenderHost::escapeText(is_string($body) ? $body : '') . '</pre>';
            },
        );
    }
}
