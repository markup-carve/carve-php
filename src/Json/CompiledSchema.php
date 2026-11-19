<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use stdClass;

/**
 * The keywords of one schema object, read once for `SchemaValidator`.
 *
 * @internal
 */
final class CompiledSchema
{
    public ?string $ref;

    public mixed $type;

    public string $typeError;

    public bool $hasConst;

    public mixed $const;

    public string $constError;

    /**
     * @var array<mixed>|null
     */
    public ?array $enum;

    /**
     * @var array<\stdClass|bool>
     */
    public array $allOf;

    /**
     * @var array<\stdClass|bool>|null
     */
    public ?array $anyOf;

    /**
     * @var array<\stdClass|bool>|null
     */
    public ?array $oneOf;

    /**
     * The dispatch of `anyOf` and `oneOf` by node type, set on first use; false before.
     *
     * @var array<string, \stdClass>|false|null
     */
    public array|false|null $anyOfByType = false;

    /**
     * @var array<string, \stdClass>|false|null
     */
    public array|false|null $oneOfByType = false;

    public stdClass|bool|null $not;

    public bool $hasIf;

    public stdClass|bool $if;

    public stdClass|bool $then;

    public stdClass|bool $else;

    public int|float|null $minLength;

    public ?string $pattern;

    public string $regex;

    public int|float|null $minimum;

    public int|float|null $maximum;

    public int|float|null $exclusiveMinimum;

    /**
     * Whether any object keyword is present; without one the object checks pass.
     */
    public bool $objectRules;

    /**
     * @var array<string>
     */
    public array $required;

    public int|float|null $minProperties;

    public int|float|null $maxProperties;

    /**
     * @var array<string, array<string>>
     */
    public array $dependentRequired;

    public stdClass|bool|null $propertyNames;

    /**
     * @var array<array{string, \stdClass|bool}>
     */
    public array $patternProperties = [];

    public stdClass|bool|null $additional;

    /**
     * Whether any array keyword is present; without one the array checks pass.
     */
    public bool $arrayRules;

    public int|float|null $minItems;

    public int|float|null $maxItems;

    /**
     * `properties`, `prefixItems` and `items` compiled up front, for the loops over members and items.
     *
     * @var array<string, \MarkupCarve\Carve\Json\CompiledSchema|bool>
     */
    public array $propertyChecks = [];

    /**
     * @var array<\MarkupCarve\Carve\Json\CompiledSchema|bool>
     */
    public array $prefixChecks = [];

    public CompiledSchema|bool $itemsCheck;

    public bool $uniqueItems;

    /**
     * @param \stdClass $schema
     */
    public function __construct(stdClass $schema)
    {
        $this->ref = isset($schema->{'$ref'}) ? (string)$schema->{'$ref'} : null;
        $this->type = $schema->type ?? null;
        $this->typeError = $this->type === null ? '' : 'type ' . json_encode($this->type);
        $this->hasConst = property_exists($schema, 'const');
        $this->const = $this->hasConst ? $schema->const : null;
        $this->constError = $this->hasConst ? 'const ' . json_encode($this->const) : '';
        $this->enum = isset($schema->enum) ? (array)$schema->enum : null;
        $this->allOf = $schema->allOf ?? [];
        $this->anyOf = $schema->anyOf ?? null;
        $this->oneOf = $schema->oneOf ?? null;
        $this->not = $schema->not ?? null;
        $this->hasIf = isset($schema->if);
        $this->if = $schema->if ?? true;
        $this->then = $schema->then ?? true;
        $this->else = $schema->else ?? true;
        $this->scalarKeywords($schema);
        $this->objectKeywords($schema);
        $this->arrayKeywords($schema);
    }

    private function scalarKeywords(stdClass $schema): void
    {
        $this->minLength = $schema->minLength ?? null;
        $this->pattern = isset($schema->pattern) ? (string)$schema->pattern : null;
        $this->regex = $this->pattern === null ? '' : SchemaValidator::regex($this->pattern);
        $this->minimum = $schema->minimum ?? null;
        $this->maximum = $schema->maximum ?? null;
        $this->exclusiveMinimum = $schema->exclusiveMinimum ?? null;
    }

    private function objectKeywords(stdClass $schema): void
    {
        $this->required = $schema->required ?? [];
        $this->minProperties = $schema->minProperties ?? null;
        $this->maxProperties = $schema->maxProperties ?? null;
        $this->dependentRequired = (array)($schema->dependentRequired ?? []);
        $this->propertyNames = $schema->propertyNames ?? null;
        foreach ((array)($schema->properties ?? []) as $key => $sub) {
            $this->propertyChecks[(string)$key] = self::of($sub);
        }
        foreach ((array)($schema->patternProperties ?? []) as $key => $sub) {
            $this->patternProperties[] = [SchemaValidator::regex((string)$key), $sub];
        }
        $this->additional = $schema->additionalProperties ?? null;
        $this->objectRules = $this->required !== [] || $this->minProperties !== null || $this->maxProperties !== null
            || $this->dependentRequired !== [] || $this->propertyNames !== null || isset($schema->properties)
            || $this->patternProperties !== [] || $this->additional !== null;
    }

    private function arrayKeywords(stdClass $schema): void
    {
        $this->minItems = $schema->minItems ?? null;
        $this->maxItems = $schema->maxItems ?? null;
        $prefix = $schema->prefixItems ?? [];
        foreach ($prefix as $i => $sub) {
            $this->prefixChecks[$i] = self::of($sub);
        }
        $items = $schema->items ?? true;
        $this->itemsCheck = self::of($items);
        $this->uniqueItems = !empty($schema->uniqueItems);
        $this->arrayRules = $this->minItems !== null || $this->maxItems !== null || $prefix !== [] || $items !== true
            || $this->uniqueItems;
    }

    /**
     * @param mixed $schema
     *
     * @return \MarkupCarve\Carve\Json\CompiledSchema|bool
     */
    private static function of(mixed $schema): self|bool
    {
        return $schema instanceof stdClass ? new self($schema) : (bool)$schema;
    }
}
