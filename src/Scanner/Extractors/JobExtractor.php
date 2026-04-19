<?php

declare(strict_types=1);

namespace Mindum\Laravel\Scanner\Extractors;

use Mindum\Laravel\Scanner\SymbolIndex;
use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

/**
 * Emits manifest entries for dispatchable jobs.
 *
 * Per decision D3:
 *  - A job qualifies only if handle() has a non-void, non-null return type hint.
 *  - Jobs implementing ShouldQueue are skipped (fire-and-forget — not useful
 *    synchronously). Conservative — a later DuplicateLinker can override if
 *    there is evidence of dispatchSync usage.
 *  - Input schema comes from the constructor. If the concrete job has no
 *    constructor, we resolve the parent class via SymbolIndex and pull
 *    its constructor params (Akaunting's Abstracts\Job pattern).
 */
class JobExtractor
{
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
        $normalized = str_replace('\\', '/', $filePath);
        if (! preg_match('#/Jobs/#', $normalized)) {
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

        // D3 guard #1: skip ShouldQueue jobs.
        $implementsList = array_map(
            fn (Node\Name $name) => $this->resolveName($name, $uses, $namespace),
            $class->implements,
        );
        $implementsShort = array_map(
            static fn (string $f) => substr($f, (int) strrpos($f, '\\') + 1),
            $implementsList,
        );
        if (in_array('ShouldQueue', $implementsShort, true)) {
            return [];
        }

        // D3 guard #2: handle() must have a non-void return type.
        $handle = $this->findPublicMethod($class, 'handle');
        if ($handle === null) {
            return [];
        }
        $returnType = $this->stringifyReturnType($handle, $namespace, $uses);
        if ($this->isVoidish($returnType)) {
            return [];
        }

        // Input: constructor params (local first, parent via SymbolIndex as fallback).
        [$schemaSource, $fields, $inheritedFrom] = $this->resolveConstructorInput($class, $uses, $namespace);

        $extendsFqcn = $class->extends !== null
            ? $this->resolveName($class->extends, $uses, $namespace)
            : null;

        $line = $class->getStartLine();
        $relFile = $this->relativePath($filePath, $appRoot);

        $entry = [
            'kind' => 'job',
            'id' => $this->deriveToolId($className),
            'source' => [
                'class' => $fqcn,
                'file' => "{$relFile}:{$line}",
                'entry_method' => 'handle',
                'returns' => $returnType,
            ],
            'description_hints' => [
                'method_docblock' => $this->docblockSummary($handle),
                'class_docblock' => $this->docblockSummary($class),
                'namespace_domain' => $this->namespaceDomain($namespace),
            ],
            'input' => [
                'shape' => 'typed_params',
                'schema_source' => $schemaSource,
                'fields' => $fields,
            ],
            'permissions_hints' => [],
            'operation_hints' => $this->deriveOperationHints($className),
            'kind_data' => [
                'extends' => $extendsFqcn,
                'implements' => $implementsList,
                'constructor_inherited_from' => $inheritedFrom,
                'dispatch_mode_hint' => 'sync_required',
            ],
        ];

        return [$entry];
    }

    /**
     * @param  array<string, string>  $uses
     * @return array{0: string, 1: array<int, array<string, mixed>>, 2: string|null}
     */
    private function resolveConstructorInput(Node\Stmt\Class_ $class, array $uses, ?string $namespace): array
    {
        $ctor = $this->findMethod($class, '__construct');
        if ($ctor !== null) {
            return ['job_constructor', $this->paramsToFields($ctor->params, $uses, $namespace), null];
        }

        // Fall back to parent class constructor via SymbolIndex.
        if ($class->extends === null) {
            return ['incomplete', [], null];
        }
        $parentFqcn = $this->resolveName($class->extends, $uses, $namespace);
        $parentFile = $this->index->findFile($parentFqcn);
        if ($parentFile === null) {
            // Parent is outside the scanned codebase (framework class) — signal it.
            return ['inherited_constructor', [], $parentFqcn];
        }

        $parentAst = $this->parseFile($parentFile);
        if ($parentAst === null) {
            return ['inherited_constructor', [], $parentFqcn];
        }
        $parentClass = $this->finder->findFirstInstanceOf($parentAst, Node\Stmt\Class_::class);
        if ($parentClass === null) {
            return ['inherited_constructor', [], $parentFqcn];
        }

        $parentUses = $this->extractUseMap($parentAst);
        $parentNamespace = $this->extractNamespace($parentAst);

        $parentCtor = $this->findMethod($parentClass, '__construct');
        if ($parentCtor === null) {
            return ['inherited_constructor', [], $parentFqcn];
        }

        return [
            'inherited_constructor',
            $this->paramsToFields($parentCtor->params, $parentUses, $parentNamespace),
            $parentFqcn,
        ];
    }

    /**
     * @param  array<Node\Param>  $params
     * @param  array<string, string>  $uses
     * @return array<int, array<string, mixed>>
     */
    private function paramsToFields(array $params, array $uses, ?string $namespace): array
    {
        $fields = [];
        foreach ($params as $param) {
            if (! $param->var instanceof Node\Expr\Variable || ! is_string($param->var->name)) {
                continue;
            }
            $typeHint = $param->type !== null ? $this->stringifyType($param->type, $namespace, $uses) : null;
            $required = $param->default === null && ($param->type === null || ! $param->type instanceof Node\NullableType);

            $fields[] = [
                'name' => $param->var->name,
                'type_hint' => $typeHint,
                'rules' => null,
                'required' => $required,
            ];
        }

        return $fields;
    }

    private function isVoidish(string $type): bool
    {
        $normalized = strtolower(ltrim($type, '?'));

        return in_array($normalized, ['void', 'null', 'mixed', ''], true);
    }

    // ──────────────────── shared helpers ────────────────────

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

    private function findPublicMethod(Node\Stmt\Class_ $class, string $name): ?Node\Stmt\ClassMethod
    {
        $m = $this->findMethod($class, $name);

        return $m?->isPublic() ? $m : null;
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

    /** @param array<string, string> $uses */
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

    private function namespaceDomain(?string $namespace): ?string
    {
        if ($namespace === null) {
            return null;
        }
        $parts = array_values(array_filter(
            explode('\\', $namespace),
            fn ($p) => ! in_array($p, ['App', 'Jobs', 'Webkul'], true),
        ));

        return $parts === [] ? $namespace : implode(' / ', $parts);
    }

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
        $prefix = '';
        if (preg_match('/^([A-Z][a-z]+)/', $className, $m) === 1) {
            $prefix = strtolower($m[1]);
        }

        $readPrefixes = ['get', 'find', 'fetch', 'show', 'list', 'search'];
        $deletePrefixes = ['destroy', 'delete', 'remove'];

        if (in_array($prefix, $readPrefixes, true)) {
            return ['likely_type' => 'read', 'verb' => $prefix];
        }
        if (in_array($prefix, $deletePrefixes, true)) {
            return ['likely_type' => 'delete', 'verb' => $prefix];
        }

        return ['likely_type' => 'write', 'verb' => $prefix];
    }

    /**
     * @return array<Node>|null
     */
    private function parseFile(string $file): ?array
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            return null;
        }
        try {
            return $this->parser->parse($code) ?? [];
        } catch (ParserError) {
            return null;
        }
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
