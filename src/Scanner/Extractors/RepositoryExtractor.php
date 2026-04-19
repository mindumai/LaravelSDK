<?php

declare(strict_types=1);

namespace Mindum\Laravel\Scanner\Extractors;

use Mindum\Laravel\Scanner\SymbolIndex;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

/**
 * Emits manifest entries for repository classes (Bagisto's Prettus L5 pattern).
 *
 * Per decision D2:
 *  - Emit 5 core inherited methods per concrete repository:
 *      find, paginate, create, update, delete
 *  - Plus every public method defined DIRECTLY on the concrete class
 *    (not inherited). These are where real business logic lives.
 *  - Skip the boilerplate model() method — it's configuration, not an operation.
 */
class RepositoryExtractor
{
    private const MAGIC_METHODS = [
        '__construct', '__destruct', '__call', '__callstatic', '__get', '__set',
        '__isset', '__unset', '__tostring', '__clone', '__debuginfo',
    ];

    /** Hardcoded shape of the 5 inherited core methods. */
    private const INHERITED_METHODS = [
        'find' => [
            'verb' => 'find',
            'likely_type' => 'read',
            'fields' => [
                ['name' => 'id', 'rules' => 'required', 'type_hint' => 'int|string', 'required' => true],
            ],
            'returns_mode' => 'model',
        ],
        'paginate' => [
            'verb' => 'list',
            'likely_type' => 'read',
            'fields' => [
                ['name' => 'per_page', 'rules' => 'nullable|integer|min:1|max:100', 'type_hint' => 'integer', 'required' => false],
            ],
            'returns_mode' => 'paginator',
        ],
        'create' => [
            'verb' => 'create',
            'likely_type' => 'write',
            'fields' => [
                ['name' => 'attributes', 'rules' => null, 'type_hint' => 'array', 'required' => true],
            ],
            'returns_mode' => 'model',
        ],
        'update' => [
            'verb' => 'update',
            'likely_type' => 'write',
            'fields' => [
                ['name' => 'id', 'rules' => 'required', 'type_hint' => 'int|string', 'required' => true],
                ['name' => 'attributes', 'rules' => null, 'type_hint' => 'array', 'required' => true],
            ],
            'returns_mode' => 'model',
        ],
        'delete' => [
            'verb' => 'delete',
            'likely_type' => 'delete',
            'fields' => [
                ['name' => 'id', 'rules' => 'required', 'type_hint' => 'int|string', 'required' => true],
            ],
            'returns_mode' => 'bool',
        ],
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
        // Detection: file lives under Repositories/ AND class extends something
        // whose name suggests a Repository base (common convention).
        $normalized = str_replace('\\', '/', $filePath);
        if (! preg_match('#/Repositories/#', $normalized)) {
            return [];
        }

        $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        if (! $class || $class->isAbstract()) {
            return [];
        }

        if ($class->extends === null) {
            return [];
        }

        $namespace = $this->extractNamespace($ast);
        $uses = $this->extractUseMap($ast);
        $extendsFqcn = $this->resolveName($class->extends, $uses, $namespace);

        if (! str_ends_with($extendsFqcn, 'Repository')
            && ! str_contains($extendsFqcn, 'Repository')
        ) {
            return [];
        }

        $className = $class->name->toString();
        $fqcn = $namespace !== null ? "{$namespace}\\{$className}" : $className;

        $modelContract = $this->extractModelContract($class);
        $primaryKey = $this->resolvePrimaryKey($modelContract);
        $subject = $this->stripRepositorySuffix($className);
        $subjectSnake = $this->toSnake($subject);

        $line = $class->getStartLine();
        $relFile = $this->relativePath($filePath, $appRoot);

        $common = [
            'class_fqcn' => $fqcn,
            'file_line' => "{$relFile}:{$line}",
            'class_docblock' => $this->docblockSummary($class),
            'namespace_domain' => $this->namespaceDomain($namespace),
            'model_contract' => $modelContract,
            'extends' => $extendsFqcn,
            'subject' => $subject,
            'subject_snake' => $subjectSnake,
            'subject_snake_plural' => $this->toPluralSnake($subject),
            'primary_key' => $primaryKey,
        ];

        $entries = [];

        // Collect names of concretely-defined public methods first so we can
        // skip the inherited version whenever the repo overrides it.
        $concreteMethods = [];
        $methods = $this->finder->findInstanceOf($class, Node\Stmt\ClassMethod::class);
        foreach ($methods as $method) {
            if (! $method->isPublic() || $method->isAbstract()) {
                continue;
            }
            $mname = strtolower($method->name->toString());
            if (in_array($mname, self::MAGIC_METHODS, true) || $mname === 'model') {
                continue;
            }
            $concreteMethods[$mname] = $method;
        }

        // Inherited core methods (decision D2) — skipped when concretely overridden.
        foreach (self::INHERITED_METHODS as $methodName => $spec) {
            if (isset($concreteMethods[$methodName])) {
                continue;
            }
            $entries[] = $this->buildInheritedEntry($methodName, $spec, $common);
        }

        // Concrete methods (includes overrides of inherited ones — real logic lives here).
        foreach ($concreteMethods as $method) {
            $entries[] = $this->buildConcreteEntry($method, $common, $namespace, $uses);
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $common
     * @return array<string, mixed>
     */
    private function buildInheritedEntry(string $method, array $spec, array $common): array
    {
        // Pluralize for list-like verbs, keep singular for everything else.
        $subjectKey = in_array($spec['verb'], ['list', 'search'], true)
            ? $common['subject_snake_plural']
            : $common['subject_snake'];
        $id = $spec['verb'].'_'.$subjectKey;
        $returns = match ($spec['returns_mode']) {
            'model' => $common['model_contract'] ?? $common['class_fqcn'],
            'bool' => 'bool',
            'paginator' => 'Illuminate\\Pagination\\LengthAwarePaginator',
            default => 'mixed',
        };

        $entry = [
            'kind' => 'repository_method',
            'id' => $id,
            'source' => [
                'class' => $common['class_fqcn'],
                'file' => $common['file_line'],
                'entry_method' => "{$method}()",
                'returns' => $returns,
            ],
            'description_hints' => [
                'method_docblock' => null,
                'class_docblock' => $common['class_docblock'],
                'namespace_domain' => $common['namespace_domain'],
            ],
            'input' => [
                'shape' => 'typed_params',
                'schema_source' => 'inherited_from_base',
                'fields' => $spec['fields'],
            ],
            'permissions_hints' => [],
            'operation_hints' => [
                'likely_type' => $spec['likely_type'],
                'verb' => $spec['verb'],
            ],
            'kind_data' => [
                'base_repository' => $common['extends'],
                'model_contract' => $common['model_contract'],
                'primary_key' => $common['primary_key'],
                'origin' => 'inherited_core',
            ],
        ];

        if ($spec['likely_type'] !== 'read') {
            $entry['default_disabled'] = true;
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $common
     * @param  array<string, string>  $uses
     * @return array<string, mixed>
     */
    private function buildConcreteEntry(Node\Stmt\ClassMethod $method, array $common, ?string $namespace, array $uses): array
    {
        $methodName = $method->name->toString();
        $snakeMethod = $this->toSnake($methodName);
        $returnType = $this->stringifyReturnType($method, $namespace, $uses);
        $hints = $this->deriveOperationHints($methodName);
        $fields = $this->paramsToFields($method->params, $uses, $namespace);

        $entry = [
            'kind' => 'repository_method',
            'id' => $snakeMethod.'_'.$common['subject_snake'],
            'source' => [
                'class' => $common['class_fqcn'],
                'file' => $common['file_line'],
                'entry_method' => $methodName,
                'returns' => $returnType,
            ],
            'description_hints' => [
                'method_docblock' => $this->docblockSummary($method),
                'class_docblock' => $common['class_docblock'],
                'namespace_domain' => $common['namespace_domain'],
            ],
            'input' => [
                'shape' => 'typed_params',
                'schema_source' => 'method_params',
                'fields' => $fields,
            ],
            'permissions_hints' => [],
            'operation_hints' => $hints,
            'kind_data' => [
                'base_repository' => $common['extends'],
                'model_contract' => $common['model_contract'],
                'primary_key' => $common['primary_key'],
                'origin' => 'concrete_method',
            ],
        ];

        if ($hints['likely_type'] !== 'read') {
            $entry['default_disabled'] = true;
        }

        return $entry;
    }

    /**
     * Resolve the underlying model's primary key column and type.
     *
     * Strategy: look up the model_contract in SymbolIndex. If the file contains
     * a class (not just an interface) with $primaryKey, use it. Check for
     * HasUuids trait to determine type. Fall back to ['id', 'integer'] for
     * contracts/interfaces or anything we can't resolve.
     *
     * @return array{column: string, type: string}
     */
    private function resolvePrimaryKey(?string $modelContract): array
    {
        $default = ['column' => 'id', 'type' => 'integer'];
        if ($modelContract === null) {
            return $default;
        }

        // Also try the Models\ sibling of a Contracts\ interface (Bagisto pattern).
        $candidates = [$modelContract];
        if (str_contains($modelContract, '\\Contracts\\')) {
            $candidates[] = str_replace('\\Contracts\\', '\\Models\\', $modelContract);
        }

        foreach ($candidates as $fqcn) {
            $file = $this->index->findFile($fqcn);
            if ($file === null) {
                continue;
            }
            $code = @file_get_contents($file);
            if ($code === false) {
                continue;
            }
            try {
                $ast = $this->parser->parse($code);
            } catch (Error) {
                continue;
            }
            if ($ast === null) {
                continue;
            }
            $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
            if ($class === null) {
                continue; // Interface or similar — try next candidate.
            }

            $column = 'id';
            $properties = $this->finder->findInstanceOf($class, Node\Stmt\Property::class);
            foreach ($properties as $property) {
                foreach ($property->props as $item) {
                    if ($item->name->toString() === 'primaryKey'
                        && $item->default instanceof Node\Scalar\String_
                    ) {
                        $column = $item->default->value;
                        break 2;
                    }
                }
            }

            $type = 'integer';
            $uses = $this->extractUseMap($ast);
            $ns = $this->extractNamespace($ast);
            foreach ($this->finder->findInstanceOf($class, Node\Stmt\TraitUse::class) as $traitUse) {
                foreach ($traitUse->traits as $traitName) {
                    $traitFqcn = $this->resolveName($traitName, $uses, $ns);
                    if (str_ends_with($traitFqcn, '\\HasUuids')) {
                        $type = 'uuid';
                        break 2;
                    }
                }
            }

            return ['column' => $column, 'type' => $type];
        }

        return $default;
    }

    /**
     * Pull the contract string out of:
     *   public function model(): string { return 'Webkul\Checkout\Contracts\Cart'; }
     */
    private function extractModelContract(Node\Stmt\Class_ $class): ?string
    {
        $method = $this->findMethod($class, 'model');
        if ($method === null) {
            return null;
        }
        $return = $this->finder->findFirstInstanceOf($method->stmts ?? [], Node\Stmt\Return_::class);
        if ($return?->expr instanceof Node\Scalar\String_) {
            return $return->expr->value;
        }
        if ($return?->expr instanceof Node\Expr\ClassConstFetch
            && $return->expr->class instanceof Node\Name
            && $return->expr->name instanceof Node\Identifier
            && strtolower($return->expr->name->toString()) === 'class'
        ) {
            return $return->expr->class->toString();
        }

        return null;
    }

    private function stripRepositorySuffix(string $className): string
    {
        if (str_ends_with($className, 'Repository')) {
            return substr($className, 0, -strlen('Repository'));
        }

        return $className;
    }

    private function toSnake(string $name): string
    {
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name) ?? $name;
        $s = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $s) ?? $s;

        return strtolower($s);
    }

    private function toPluralSnake(string $name): string
    {
        $singular = $this->toSnake($name);
        if (preg_match('/[sxz]$|[cs]h$/', $singular)) {
            return $singular.'es';
        }
        if (preg_match('/[^aeiou]y$/', $singular)) {
            return substr($singular, 0, -1).'ies';
        }

        return $singular.'s';
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
            $required = $param->default === null && ! ($param->type instanceof Node\NullableType);
            $fields[] = [
                'name' => $param->var->name,
                'type_hint' => $typeHint,
                'rules' => null,
                'required' => $required,
            ];
        }

        return $fields;
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
            fn ($p) => ! in_array($p, ['App', 'Webkul', 'Repositories'], true),
        ));

        return $parts === [] ? $namespace : implode(' / ', $parts);
    }

    /**
     * @return array{likely_type: string, verb: string}
     */
    private function deriveOperationHints(string $methodName): array
    {
        $prefix = '';
        if (preg_match('/^([a-z]+)/', $methodName, $m) === 1) {
            $prefix = strtolower($m[1]);
        }

        $readPrefixes = ['get', 'find', 'fetch', 'show', 'list', 'search', 'load'];
        $deletePrefixes = ['destroy', 'delete', 'remove'];

        if (in_array($prefix, $readPrefixes, true)) {
            return ['likely_type' => 'read', 'verb' => $prefix];
        }
        if (in_array($prefix, $deletePrefixes, true)) {
            return ['likely_type' => 'delete', 'verb' => $prefix];
        }

        return ['likely_type' => 'write', 'verb' => $prefix === '' ? 'invoke' : $prefix];
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
