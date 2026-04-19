<?php

declare(strict_types=1);

namespace Mindum\Laravel\Scanner\Extractors;

use Mindum\Laravel\Scanner\SymbolIndex;
use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

/**
 * Extracts manifest entries for classes matching the "action" kind — a class
 * that extends a base service and exposes an execute() entry point with
 * validation rules in a separate rules() method.
 *
 * Primary target: Monica's BaseService pattern. Should also work for other
 * apps that use the same convention (class with rules() + execute() pair).
 */
class ActionExtractor
{
    private const KNOWN_BASE_SERVICES = [
        'App\\Services\\BaseService',
        'App\\Abstracts\\BaseService',
    ];

    private NodeFinder $finder;

    public function __construct(
        private readonly SymbolIndex $index,
        private readonly Parser $parser,
    ) {
        $this->finder = new NodeFinder;
    }

    /**
     * @param  array<Node>  $ast
     * @return array<int, array<string, mixed>>
     */
    public function extract(string $filePath, array $ast, string $appRoot): array
    {
        $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        if (! $class || $class->isAbstract()) {
            return [];
        }

        $namespace = $this->extractNamespace($ast);
        $uses = $this->extractUseMap($ast);

        $extendsFqcn = $this->resolveExtends($class, $uses, $namespace);
        if (! $this->isActionClass($extendsFqcn)) {
            return [];
        }

        $executeMethod = $this->findPublicMethod($class, 'execute');
        if ($executeMethod === null) {
            return [];
        }

        $className = $class->name->toString();
        $fqcn = $namespace !== null ? "{$namespace}\\{$className}" : $className;

        $rulesFields = $this->extractRulesFields($class);
        $permissions = $this->extractPermissionsList($class);
        $returnType = $this->stringifyReturnType($executeMethod, $namespace, $uses);
        $operationHints = $this->deriveOperationHints($className);
        $toolId = $this->deriveToolId($className);
        $implementsList = array_map(
            fn (Node\Name $name) => $this->resolveName($name, $uses, $namespace),
            $class->implements,
        );

        $line = $class->getStartLine();
        $relFile = $this->relativePath($filePath, $appRoot);

        return [[
            'kind' => 'action',
            'id' => $toolId,
            'source' => [
                'class' => $fqcn,
                'file' => "{$relFile}:{$line}",
                'entry_method' => 'execute',
                'returns' => $returnType,
            ],
            'description_hints' => [
                'method_docblock' => $this->docblockSummary($executeMethod),
                'class_docblock' => $this->docblockSummary($class),
                'namespace_domain' => $this->namespaceDomain($namespace),
            ],
            'input' => [
                'shape' => 'array',
                'schema_source' => $rulesFields !== null ? 'rules_method' : 'method_params',
                'fields' => $rulesFields ?? [],
            ],
            'permissions_hints' => $permissions,
            'operation_hints' => $operationHints,
            'kind_data' => [
                'base_class' => $extendsFqcn,
                'implements' => $implementsList,
            ],
        ]];
    }

    /** @param array<Node> $ast */
    private function extractNamespace(array $ast): ?string
    {
        $ns = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Namespace_::class);

        return $ns?->name?->toString();
    }

    /**
     * Build an alias => FQCN map from `use` statements.
     *
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

    /**
     * @param  array<string, string>  $uses
     */
    private function resolveExtends(Node\Stmt\Class_ $class, array $uses, ?string $namespace): ?string
    {
        if ($class->extends === null) {
            return null;
        }

        return $this->resolveName($class->extends, $uses, $namespace);
    }

    /**
     * @param  array<string, string>  $uses
     */
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

    /**
     * Walk the inheritance chain via SymbolIndex. Returns true if any ancestor
     * is a recognized base service. Catches e.g. Monica's DestroyContact which
     * extends QueuableService extends BaseService.
     */
    private function isActionClass(?string $extendsFqcn): bool
    {
        if ($extendsFqcn === null) {
            return false;
        }

        $visited = [];
        $current = $extendsFqcn;

        while ($current !== null && ! isset($visited[$current])) {
            $visited[$current] = true;

            if ($this->matchesKnownBase($current)) {
                return true;
            }

            // Walk up: find parent's file, parse it, get its extends.
            $parentFile = $this->index->findFile($current);
            if ($parentFile === null) {
                return false;
            }
            $code = @file_get_contents($parentFile);
            if ($code === false) {
                return false;
            }
            try {
                $parentAst = $this->parser->parse($code);
            } catch (ParserError) {
                return false;
            }
            if ($parentAst === null) {
                return false;
            }

            $parentClass = $this->finder->findFirstInstanceOf($parentAst, Node\Stmt\Class_::class);
            if ($parentClass === null || $parentClass->extends === null) {
                return false;
            }
            $parentUses = $this->extractUseMap($parentAst);
            $parentNs = $this->extractNamespace($parentAst);
            $current = $this->resolveName($parentClass->extends, $parentUses, $parentNs);
        }

        return false;
    }

    private function matchesKnownBase(string $fqcn): bool
    {
        foreach (self::KNOWN_BASE_SERVICES as $base) {
            if ($fqcn === $base) {
                return true;
            }
        }

        return str_ends_with($fqcn, '\\BaseService');
    }

    private function findPublicMethod(Node\Stmt\Class_ $class, string $name): ?Node\Stmt\ClassMethod
    {
        $methods = $this->finder->findInstanceOf($class, Node\Stmt\ClassMethod::class);
        foreach ($methods as $method) {
            if ($method->name->toLowerString() === strtolower($name) && $method->isPublic()) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Parse the `rules()` method return array into an ordered list of fields.
     *
     * @return array<int, array{name: string, rules: string|null, required: bool}>|null
     *                                                                                  Null when there is no rules() method; empty array when rules() returns [].
     */
    private function extractRulesFields(Node\Stmt\Class_ $class): ?array
    {
        $method = $this->findMethod($class, 'rules');
        if ($method === null) {
            return null;
        }

        $array = $this->findReturnedArray($method);
        if ($array === null) {
            return [];
        }

        $fields = [];
        foreach ($array->items as $item) {
            if (! $item instanceof Node\Expr\ArrayItem || $item->key === null) {
                continue;
            }
            $name = $this->stringValue($item->key);
            $rules = $this->stringValue($item->value);
            if ($name === null) {
                continue;
            }
            $fields[] = [
                'name' => $name,
                'rules' => $rules,
                'required' => $rules !== null && preg_match('/(^|\|)required(\||$)/', $rules) === 1,
            ];
        }

        return $fields;
    }

    /**
     * Parse the `permissions()` method return array into an ordered list.
     *
     * @return array<int, string>
     */
    private function extractPermissionsList(Node\Stmt\Class_ $class): array
    {
        $method = $this->findMethod($class, 'permissions');
        if ($method === null) {
            return [];
        }

        $array = $this->findReturnedArray($method);
        if ($array === null) {
            return [];
        }

        $perms = [];
        foreach ($array->items as $item) {
            if (! $item instanceof Node\Expr\ArrayItem) {
                continue;
            }
            $val = $this->stringValue($item->value);
            if ($val !== null) {
                $perms[] = $val;
            }
        }

        return $perms;
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

    private function findReturnedArray(Node\Stmt\ClassMethod $method): ?Node\Expr\Array_
    {
        $return = $this->finder->findFirstInstanceOf($method->stmts ?? [], Node\Stmt\Return_::class);
        if ($return?->expr instanceof Node\Expr\Array_) {
            return $return->expr;
        }

        return null;
    }

    private function stringValue(Node $node): ?string
    {
        return $node instanceof Node\Scalar\String_ ? $node->value : null;
    }

    /**
     * @param  array<string, string>  $uses
     */
    private function stringifyReturnType(Node\Stmt\ClassMethod $method, ?string $namespace, array $uses): string
    {
        if ($method->returnType === null) {
            return 'mixed';
        }

        return $this->stringifyType($method->returnType, $namespace, $uses);
    }

    /**
     * @param  Node\Identifier|Node\Name|Node\NullableType|Node\UnionType|Node\IntersectionType  $type
     * @param  array<string, string>  $uses
     */
    private function stringifyType(Node $type, ?string $namespace, array $uses): string
    {
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\Name) {
            return $this->resolveName($type, $uses, $namespace);
        }
        if ($type instanceof Node\NullableType) {
            return '?'.$this->stringifyType($type->type, $namespace, $uses);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(
                fn ($t) => $this->stringifyType($t, $namespace, $uses),
                $type->types,
            ));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(
                fn ($t) => $this->stringifyType($t, $namespace, $uses),
                $type->types,
            ));
        }

        return 'mixed';
    }

    /**
     * Extract the first line of a docblock as a human summary.
     * Returns null if no docblock or no summary line.
     */
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

    /**
     * App\Domains\Contact\ManageContact\Services → "Contact / ManageContact"
     */
    private function namespaceDomain(?string $namespace): ?string
    {
        if ($namespace === null) {
            return null;
        }
        $parts = explode('\\', $namespace);
        if (count($parts) <= 2) {
            return implode(' / ', $parts);
        }
        // Strip leading "App" and trailing "Services" / "Http" markers; keep the middle.
        $parts = array_values(array_filter($parts, fn ($p) => ! in_array($p, ['App', 'Services', 'Http', 'Controllers', 'Jobs'], true)));

        return implode(' / ', $parts);
    }

    /**
     * CreateContact → create_contact
     */
    private function deriveToolId(string $className): string
    {
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $className) ?? $className;
        $snake = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $snake) ?? $snake;

        return strtolower($snake);
    }

    /**
     * @return array{likely_type: string, verb: string}
     */
    private function deriveOperationHints(string $className): array
    {
        $prefix = $this->leadingWord($className);
        $verb = strtolower($prefix);

        $readPrefixes = ['get', 'find', 'fetch', 'show', 'list', 'search', 'view'];
        $deletePrefixes = ['destroy', 'delete', 'remove'];

        if (in_array($verb, $readPrefixes, true)) {
            return ['likely_type' => 'read', 'verb' => $verb];
        }
        if (in_array($verb, $deletePrefixes, true)) {
            return ['likely_type' => 'delete', 'verb' => $verb];
        }

        return ['likely_type' => 'write', 'verb' => $verb];
    }

    private function leadingWord(string $className): string
    {
        if (preg_match('/^([A-Z][a-z]+)/', $className, $m) === 1) {
            return $m[1];
        }

        return $className;
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
