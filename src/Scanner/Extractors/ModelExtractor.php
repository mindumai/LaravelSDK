<?php

declare(strict_types=1);

namespace Mindum\Laravel\Scanner\Extractors;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Emits manifest entries for Eloquent models. Per decision D1:
 *   - Emit 5 CRUD operations per model (list, find, create, update, delete).
 *   - Writes (create, update, delete) are default_disabled: true.
 *   - If the model uses SoftDeletes, also emit restore_<model> (default_disabled).
 *   - If the model uses Searchable (Scout), also emit search_<model>.
 *   - forceDelete is skipped entirely (too dangerous).
 */
class ModelExtractor
{
    private NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder;
    }

    /**
     * @param  array<Node>  $ast
     * @return array<int, array<string, mixed>>
     */
    public function extract(string $filePath, array $ast, string $appRoot): array
    {
        // Primary detection signal: file lives under a Models directory.
        $normalized = str_replace('\\', '/', $filePath);
        if (! preg_match('#/Models(/|$)#', $normalized)) {
            return [];
        }

        $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        if (! $class || $class->isAbstract()) {
            return [];
        }

        $namespace = $this->extractNamespace($ast);
        $uses = $this->extractUseMap($ast);

        $className = $class->name->toString();
        $fqcn = $namespace !== null ? "{$namespace}\\{$className}" : $className;

        $fillable = $this->extractStringArrayProperty($class, 'fillable');
        $castsMap = $this->extractAssocStringProperty($class, 'casts');
        $tableName = $this->extractScalarProperty($class, 'table') ?? $this->guessTable($className);
        $primaryKey = $this->extractScalarProperty($class, 'primaryKey') ?? 'id';

        $traitFqcns = $this->extractTraitFqcns($class, $uses, $namespace);
        $shortTraits = array_map(static fn ($t) => substr($t, (int) strrpos($t, '\\') + 1), $traitFqcns);
        $softDeletes = in_array('SoftDeletes', $shortTraits, true);
        $searchable = in_array('Searchable', $shortTraits, true);
        $uuidKey = in_array('HasUuids', $shortTraits, true);

        $searchableFields = $searchable
            ? $this->extractSearchableFields($class, $fillable)
            : [];

        $line = $class->getStartLine();
        $relFile = $this->relativePath($filePath, $appRoot);

        $common = [
            'source' => [
                'class' => $fqcn,
                'file' => "{$relFile}:{$line}",
            ],
            'description_hints' => [
                'method_docblock' => null,
                'class_docblock' => $this->docblockSummary($class),
                'namespace_domain' => "Models / {$className}",
            ],
            'permissions_hints' => [],
            'kind_data_common' => [
                'table' => $tableName,
                'primary_key' => [
                    'column' => $primaryKey,
                    'type' => $uuidKey ? 'uuid' : 'integer',
                ],
                'soft_deletes' => $softDeletes,
                'fillable' => $fillable,
                'casts' => $castsMap,
                'traits' => $shortTraits,
                'searchable_fields' => $searchableFields,
            ],
        ];

        $singular = $this->toSingularSnake($className);
        $plural = $this->toPluralSnake($className);

        $entries = [
            $this->makeEntry('list', $plural, $common, 'read', false, $this->listInputFields(), 'list'),
            $this->makeEntry('find', $singular, $common, 'read', false, $this->findInputFields($uuidKey), 'find'),
            $this->makeEntry('create', $singular, $common, 'write', true, $this->createInputFields($fillable, $castsMap), 'create'),
            $this->makeEntry('update', $singular, $common, 'write', true, $this->updateInputFields($uuidKey, $fillable, $castsMap), 'update'),
            $this->makeEntry('delete', $singular, $common, 'delete', true, $this->idOnlyInputFields($uuidKey), 'delete'),
        ];

        if ($softDeletes) {
            $entries[] = $this->makeEntry('restore', $singular, $common, 'write', true, $this->idOnlyInputFields($uuidKey), 'restore');
        }

        if ($searchable) {
            $entries[] = $this->makeEntry('search', $plural, $common, 'read', false, $this->searchInputFields(), 'search');
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $common
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function makeEntry(string $op, string $subject, array $common, string $likelyType, bool $defaultDisabled, array $fields, string $verb): array
    {
        $id = "{$op}_{$subject}";

        $kindData = $common['kind_data_common'];
        $kindData['operation'] = $op;

        $entry = [
            'kind' => 'model_crud',
            'id' => $id,
            'source' => $common['source'] + ['entry_method' => "{$op}()", 'returns' => $this->returnTypeFor($op, $common['source']['class'])],
            'description_hints' => $common['description_hints'],
            'input' => [
                'shape' => 'typed_params',
                'schema_source' => 'fillable',
                'fields' => $fields,
            ],
            'permissions_hints' => $common['permissions_hints'],
            'operation_hints' => [
                'likely_type' => $likelyType,
                'verb' => $verb,
            ],
            'kind_data' => $kindData,
        ];

        if ($defaultDisabled) {
            $entry['default_disabled'] = true;
        }

        return $entry;
    }

    private function returnTypeFor(string $op, string $class): string
    {
        return match ($op) {
            'list', 'search' => "Collection<{$class}>",
            'find', 'create', 'update', 'restore' => $class,
            'delete' => 'bool',
            default => 'mixed',
        };
    }

    // ──────────────────── input field builders ────────────────────

    /** @return array<int, array<string, mixed>> */
    private function listInputFields(): array
    {
        return [
            ['name' => 'page',            'rules' => 'nullable|integer|min:1',            'required' => false],
            ['name' => 'per_page',        'rules' => 'nullable|integer|min:1|max:100',    'required' => false],
            ['name' => 'order_by',        'rules' => 'nullable|string',                   'required' => false],
            ['name' => 'order_direction', 'rules' => 'nullable|in:asc,desc',              'required' => false],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function findInputFields(bool $uuid): array
    {
        return [
            ['name' => 'id', 'rules' => $uuid ? 'required|uuid' : 'required|integer', 'required' => true],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function idOnlyInputFields(bool $uuid): array
    {
        return $this->findInputFields($uuid);
    }

    /**
     * @param  array<int, string>  $fillable
     * @param  array<string, string>  $casts
     * @return array<int, array<string, mixed>>
     */
    private function createInputFields(array $fillable, array $casts): array
    {
        $fields = [];
        foreach ($fillable as $name) {
            $fields[] = [
                'name' => $name,
                'rules' => null,
                'type_hint' => $casts[$name] ?? null,
                'required' => false,
            ];
        }

        return $fields;
    }

    /**
     * @param  array<int, string>  $fillable
     * @param  array<string, string>  $casts
     * @return array<int, array<string, mixed>>
     */
    private function updateInputFields(bool $uuid, array $fillable, array $casts): array
    {
        return [
            ...$this->findInputFields($uuid),
            ...$this->createInputFields($fillable, $casts),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function searchInputFields(): array
    {
        return [
            ['name' => 'query',    'rules' => 'required|string|min:1', 'required' => true],
            ['name' => 'page',     'rules' => 'nullable|integer|min:1', 'required' => false],
            ['name' => 'per_page', 'rules' => 'nullable|integer|min:1|max:100', 'required' => false],
        ];
    }

    // ──────────────────── AST property extraction ────────────────────

    /** @return array<int, string> */
    private function extractStringArrayProperty(Node\Stmt\Class_ $class, string $propName): array
    {
        $prop = $this->findProperty($class, $propName);
        if ($prop === null) {
            return [];
        }
        $default = $prop->default ?? null;
        if (! $default instanceof Node\Expr\Array_) {
            return [];
        }
        $out = [];
        foreach ($default->items as $item) {
            if ($item instanceof Node\Expr\ArrayItem && $item->value instanceof Node\Scalar\String_) {
                $out[] = $item->value->value;
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private function extractAssocStringProperty(Node\Stmt\Class_ $class, string $propName): array
    {
        $prop = $this->findProperty($class, $propName);
        if ($prop === null) {
            return [];
        }
        $default = $prop->default ?? null;
        if (! $default instanceof Node\Expr\Array_) {
            return [];
        }
        $out = [];
        foreach ($default->items as $item) {
            if ($item instanceof Node\Expr\ArrayItem
                && $item->key instanceof Node\Scalar\String_
                && $item->value instanceof Node\Scalar\String_
            ) {
                $out[$item->key->value] = $item->value->value;
            }
        }

        return $out;
    }

    private function extractScalarProperty(Node\Stmt\Class_ $class, string $propName): ?string
    {
        $prop = $this->findProperty($class, $propName);
        if ($prop === null) {
            return null;
        }
        $default = $prop->default ?? null;
        if ($default instanceof Node\Scalar\String_) {
            return $default->value;
        }

        return null;
    }

    private function findProperty(Node\Stmt\Class_ $class, string $propName): ?Node\PropertyItem
    {
        $properties = $this->finder->findInstanceOf($class, Node\Stmt\Property::class);
        foreach ($properties as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() === $propName) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $uses
     * @return array<int, string>
     */
    private function extractTraitFqcns(Node\Stmt\Class_ $class, array $uses, ?string $namespace): array
    {
        $fqcns = [];
        $traitUses = $this->finder->findInstanceOf($class, Node\Stmt\TraitUse::class);
        foreach ($traitUses as $traitUse) {
            foreach ($traitUse->traits as $traitName) {
                $fqcns[] = $this->resolveName($traitName, $uses, $namespace);
            }
        }

        return $fqcns;
    }

    /**
     * @param  array<int, string>  $fallbackFields
     * @return array<int, string>
     */
    private function extractSearchableFields(Node\Stmt\Class_ $class, array $fallbackFields): array
    {
        $method = $this->findMethod($class, 'toSearchableArray');
        if ($method === null) {
            return [];
        }

        // Prefer #[SearchUsingFullText] attribute if present (Monica's pattern).
        foreach ($method->attrGroups ?? [] as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() === 'SearchUsingFullText' && isset($attr->args[0])) {
                    $arg = $attr->args[0]->value;
                    if ($arg instanceof Node\Expr\Array_) {
                        return $this->flattenStringArray($arg);
                    }
                }
            }
        }

        // Fall back to parsing the returned array keys in toSearchableArray().
        $return = $this->finder->findFirstInstanceOf($method->stmts ?? [], Node\Stmt\Return_::class);
        if ($return?->expr instanceof Node\Expr\Array_) {
            $keys = [];
            foreach ($return->expr->items as $item) {
                if ($item instanceof Node\Expr\ArrayItem && $item->key instanceof Node\Scalar\String_) {
                    $keys[] = $item->key->value;
                }
            }
            if ($keys !== []) {
                return $keys;
            }
        }

        return $fallbackFields;
    }

    /** @return array<int, string> */
    private function flattenStringArray(Node\Expr\Array_ $array): array
    {
        $out = [];
        foreach ($array->items as $item) {
            if ($item instanceof Node\Expr\ArrayItem && $item->value instanceof Node\Scalar\String_) {
                $out[] = $item->value->value;
            }
        }

        return $out;
    }

    private function findMethod(Node\Stmt\Class_ $class, string $name): ?Node\Stmt\ClassMethod
    {
        $methods = $this->finder->findInstanceOf($class, Node\Stmt\ClassMethod::class);
        foreach ($methods as $method) {
            if ($method->name->toLowerString() === strtolower($name)) {
                return $method;
            }
        }

        return null;
    }

    // ──────────────────── namespace / use resolution ────────────────────

    /** @param array<Node> $ast */
    private function extractNamespace(array $ast): ?string
    {
        $ns = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Namespace_::class);

        return $ns?->name?->toString();
    }

    /**
     * @param  array<Node>  $ast
     * @return array<string, string>
     */
    private function extractUseMap(array $ast): array
    {
        $uses = [];
        $useNodes = $this->finder->findInstanceOf($ast, Node\Stmt\Use_::class);
        foreach ($useNodes as $useNode) {
            foreach ($useNode->uses as $u) {
                $alias = $u->alias?->toString() ?? $u->name->getLast();
                $uses[$alias] = $u->name->toString();
            }
        }

        return $uses;
    }

    /** @param array<string, string> $uses */
    private function resolveName(Node\Name $name, array $uses, ?string $namespace): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }
        $first = $name->getFirst();
        if (isset($uses[$first])) {
            $rest = array_slice($name->getParts(), 1);

            return $rest === [] ? $uses[$first] : $uses[$first].'\\'.implode('\\', $rest);
        }

        return $namespace !== null ? "{$namespace}\\{$name->toString()}" : $name->toString();
    }

    private function docblockSummary(Node $node): ?string
    {
        $doc = $node->getDocComment()?->getText();
        if (! $doc) {
            return null;
        }
        $lines = preg_split('/\R/', $doc) ?: [];
        foreach ($lines as $line) {
            $clean = trim(preg_replace('#^\s*/?\*+/?#', '', $line) ?? '');
            if ($clean === '' || str_starts_with($clean, '@')) {
                continue;
            }

            return $clean;
        }

        return null;
    }

    // ──────────────────── naming helpers ────────────────────

    private function toSingularSnake(string $className): string
    {
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $className) ?? $className;
        $snake = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $snake) ?? $snake;

        return strtolower($snake);
    }

    private function toPluralSnake(string $className): string
    {
        $singular = $this->toSingularSnake($className);
        // Minimal English pluralization — good enough for prototype.
        if (preg_match('/[sxz]$|[cs]h$/', $singular)) {
            return $singular.'es';
        }
        if (preg_match('/[^aeiou]y$/', $singular)) {
            return substr($singular, 0, -1).'ies';
        }

        return $singular.'s';
    }

    private function guessTable(string $className): string
    {
        return $this->toPluralSnake($className);
    }

    private function relativePath(string $file, string $appRoot): string
    {
        $normalized = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $appRoot), '/');
        if (str_starts_with($normalized, $root.'/')) {
            return substr($normalized, strlen($root) + 1);
        }

        return $normalized;
    }
}
