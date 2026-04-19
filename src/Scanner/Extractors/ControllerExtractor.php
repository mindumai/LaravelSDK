<?php

declare(strict_types=1);

namespace Mindum\Laravel\Scanner\Extractors;

use Mindum\Laravel\Scanner\SymbolIndex;
use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;

/**
 * Extracts one manifest entry per public action method on a controller class.
 *
 * Validation strategy (decision D5):
 *  1) If a method parameter's type hint looks like a form request (lives under
 *     Http\Requests\ OR ends in Request/FormRequest), resolve the class's file
 *     via SymbolIndex and parse its rules() method.
 *  2) Otherwise, walk the method body for inline $request->validate([...])
 *     or $this->validate($request, [...]) calls and extract the array literal.
 *  3) If neither yields rules, schema_source = "incomplete" and Claude is
 *     told to do its best with the method signature.
 */
class ControllerExtractor
{
    private const MAGIC_METHODS = [
        '__construct', '__destruct', '__call', '__callstatic', '__get', '__set',
        '__isset', '__unset', '__tostring', '__clone', '__debuginfo',
        '__serialize', '__unserialize', '__set_state', '__sleep', '__wakeup',
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
        // Detection signal: file lives under a Controllers directory.
        $normalized = str_replace('\\', '/', $filePath);
        if (! preg_match('#/Http/Controllers/#', $normalized)) {
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

        $traits = array_map(
            fn (string $t) => substr($t, (int) strrpos($t, '\\') + 1),
            $this->extractTraitFqcns($class, $uses, $namespace),
        );

        $entries = [];
        $methods = $this->finder->findInstanceOf($class, Node\Stmt\ClassMethod::class);

        foreach ($methods as $method) {
            if (! $method->isPublic() || $method->isAbstract()) {
                continue;
            }
            $methodName = strtolower($method->name->toString());
            if (in_array($methodName, self::MAGIC_METHODS, true)) {
                continue;
            }

            $entry = $this->buildEntry($class, $method, $filePath, $appRoot, $fqcn, $namespace, $uses, $traits);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, string>  $uses
     * @param  array<int, string>  $traits
     * @return array<string, mixed>|null
     */
    private function buildEntry(
        Node\Stmt\Class_ $class,
        Node\Stmt\ClassMethod $method,
        string $filePath,
        string $appRoot,
        string $classFqcn,
        ?string $namespace,
        array $uses,
        array $traits,
    ): ?array {
        $methodName = $method->name->toString();
        $toolId = $this->deriveToolId($class->name->toString(), $methodName);

        // Look for a form request parameter; fall back to inline validate().
        [$schemaSource, $fields, $formRequestFqcn] = $this->resolveInput($method, $uses, $namespace);

        $returnType = $this->stringifyReturnType($method, $namespace, $uses);

        $dispatchedJobs = $this->detectDispatchedJobs($method, $uses, $namespace);

        $entry = [
            'kind' => 'controller_endpoint',
            'id' => $toolId,
            'source' => [
                'class' => $classFqcn,
                'file' => $this->relativePath($filePath, $appRoot).':'.$method->getStartLine(),
                'entry_method' => $methodName,
                'returns' => $returnType,
            ],
            'description_hints' => [
                'method_docblock' => $this->docblockSummary($method),
                'class_docblock' => $this->docblockSummary($class),
                'namespace_domain' => $this->namespaceDomain($namespace),
            ],
            'input' => [
                'shape' => 'typed_params',
                'schema_source' => $schemaSource,
                'fields' => $fields,
            ],
            'permissions_hints' => [],
            'operation_hints' => $this->deriveOperationHints($methodName),
            'kind_data' => [
                'form_request_class' => $formRequestFqcn,
                'traits_used' => $traits,
                'dispatches_jobs' => $dispatchedJobs,
            ],
        ];

        return $entry;
    }

    /**
     * Resolve the input schema. Returns [schema_source, fields, form_request_fqcn].
     *
     * @param  array<string, string>  $uses
     * @return array{0: string, 1: array<int, array<string, mixed>>, 2: string|null}
     */
    private function resolveInput(Node\Stmt\ClassMethod $method, array $uses, ?string $namespace): array
    {
        // Try form request parameter first.
        foreach ($method->params as $param) {
            if ($param->type === null) {
                continue;
            }
            $typeFqcn = $this->typeToFqcn($param->type, $uses, $namespace);
            if ($typeFqcn === null || ! $this->isFormRequestFqcn($typeFqcn)) {
                continue;
            }

            $fields = $this->extractRulesFromFormRequestFile($typeFqcn);
            if ($fields !== null) {
                return ['form_request', $fields, $typeFqcn];
            }

            // Found the form request but couldn't parse it — mark incomplete but keep the FQCN.
            return ['incomplete', [], $typeFqcn];
        }

        // Fall back to inline $request->validate([...]) or $this->validate($request, [...]).
        $inlineFields = $this->extractInlineValidateFields($method);
        if ($inlineFields !== null) {
            return ['inline_validate', $inlineFields, null];
        }

        return ['incomplete', [], null];
    }

    private function isFormRequestFqcn(string $fqcn): bool
    {
        if (str_contains($fqcn, '\\Http\\Requests\\')) {
            return true;
        }
        if (str_ends_with($fqcn, 'Request') || str_ends_with($fqcn, 'FormRequest')) {
            return true;
        }

        return false;
    }

    /**
     * Load the form request file (via SymbolIndex), parse it, and pull out rules().
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function extractRulesFromFormRequestFile(string $fqcn): ?array
    {
        $path = $this->index->findFile($fqcn);
        if ($path === null) {
            return null;
        }
        $code = @file_get_contents($path);
        if ($code === false) {
            return null;
        }
        try {
            $ast = $this->parser->parse($code);
        } catch (ParserError) {
            return null;
        }
        if ($ast === null) {
            return null;
        }

        $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        if (! $class) {
            return null;
        }

        $rulesMethod = $this->findMethod($class, 'rules');
        if ($rulesMethod === null) {
            return null;
        }

        $array = $this->findReturnedArray($rulesMethod);
        if ($array === null) {
            return null;
        }

        return $this->rulesArrayToFields($array);
    }

    /**
     * Walk method body for $request->validate([...]) or $this->validate($request, [...]).
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function extractInlineValidateFields(Node\Stmt\ClassMethod $method): ?array
    {
        $calls = $this->finder->findInstanceOf($method->stmts ?? [], Node\Expr\MethodCall::class);
        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier || $call->name->toLowerString() !== 'validate') {
                continue;
            }
            // $request->validate([...]) — array is arg 0
            // $this->validate($request, [...]) — array is arg 1
            $arrayArg = null;
            foreach ($call->args as $arg) {
                if ($arg->value instanceof Node\Expr\Array_) {
                    $arrayArg = $arg->value;
                    break;
                }
            }
            if ($arrayArg !== null) {
                return $this->rulesArrayToFields($arrayArg);
            }
        }

        return null;
    }

    /**
     * Convert a php-parser Array_ node of ['field' => 'rules|...'] pairs into manifest fields.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rulesArrayToFields(Node\Expr\Array_ $array): array
    {
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
     * Find dispatch(new JobX($args)) or JobX::dispatch(...) calls in the method body.
     *
     * @param  array<string, string>  $uses
     * @return array<int, string> list of FQCNs
     */
    private function detectDispatchedJobs(Node\Stmt\ClassMethod $method, array $uses, ?string $namespace): array
    {
        $jobs = [];

        // dispatch(new SomeJob(...)) / $this->dispatch(new SomeJob(...))
        $newCalls = $this->finder->findInstanceOf($method->stmts ?? [], Node\Expr\New_::class);
        foreach ($newCalls as $new) {
            if ($new->class instanceof Node\Name) {
                $fqcn = $this->resolveName($new->class, $uses, $namespace);
                if (str_contains($fqcn, '\\Jobs\\')) {
                    $jobs[$fqcn] = true;
                }
            }
        }

        // JobX::dispatch(...) / JobX::dispatchSync(...) / JobX::dispatchNow(...)
        $staticCalls = $this->finder->findInstanceOf($method->stmts ?? [], Node\Expr\StaticCall::class);
        foreach ($staticCalls as $staticCall) {
            if ($staticCall->class instanceof Node\Name
                && $staticCall->name instanceof Node\Identifier
                && in_array(strtolower($staticCall->name->toString()), ['dispatch', 'dispatchsync', 'dispatchnow'], true)
            ) {
                $fqcn = $this->resolveName($staticCall->class, $uses, $namespace);
                if (str_contains($fqcn, '\\Jobs\\')) {
                    $jobs[$fqcn] = true;
                }
            }
        }

        return array_keys($jobs);
    }

    // ──────────────────── shared helpers (duplicated across extractors for now) ────────────────────

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

    /**
     * @param  Node\Identifier|Node\Name|Node\NullableType|Node\UnionType|Node\IntersectionType|mixed  $type
     * @param  array<string, string>  $uses
     */
    private function typeToFqcn(Node $type, array $uses, ?string $namespace): ?string
    {
        if ($type instanceof Node\NullableType) {
            return $this->typeToFqcn($type->type, $uses, $namespace);
        }
        if ($type instanceof Node\Name) {
            return $this->resolveName($type, $uses, $namespace);
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
     * Find the array this method returns. Handles two common patterns:
     *   1) return [...]                       (direct array literal)
     *   2) $rules = [...]; ...; return $rules (assign-then-return)
     *
     * For (2), we return the initial assignment — conditional mutations later
     * in the method are captured as best-effort; Claude handles partial schemas.
     */
    private function findReturnedArray(Node\Stmt\ClassMethod $method): ?Node\Expr\Array_
    {
        $stmts = $method->stmts ?? [];

        // Pattern 1: direct return of an array literal anywhere in the body.
        $returns = $this->finder->findInstanceOf($stmts, Node\Stmt\Return_::class);
        foreach ($returns as $return) {
            if ($return->expr instanceof Node\Expr\Array_) {
                return $return->expr;
            }
        }

        // Pattern 2: return $someVar where $someVar = [...] earlier.
        foreach ($returns as $return) {
            if ($return->expr instanceof Node\Expr\Variable && is_string($return->expr->name)) {
                $varName = $return->expr->name;
                $assigns = $this->finder->findInstanceOf($stmts, Node\Expr\Assign::class);
                foreach ($assigns as $assign) {
                    if ($assign->var instanceof Node\Expr\Variable
                        && is_string($assign->var->name)
                        && $assign->var->name === $varName
                        && $assign->expr instanceof Node\Expr\Array_
                    ) {
                        return $assign->expr;
                    }
                }
            }
        }

        return null;
    }

    private function stringValue(Node $node): ?string
    {
        return $node instanceof Node\Scalar\String_ ? $node->value : null;
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
            fn ($p) => ! in_array($p, ['App', 'Http', 'Controllers', 'Webkul'], true),
        ));

        return $parts === [] ? $namespace : implode(' / ', $parts);
    }

    private function deriveToolId(string $className, string $methodName): string
    {
        $subject = preg_replace('/Controller$/', '', $className) ?? $className;
        $subject = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $subject) ?? $subject;

        return strtolower($methodName.'_'.$subject);
    }

    /**
     * @return array{likely_type: string, verb: string}
     */
    private function deriveOperationHints(string $methodName): array
    {
        $verb = strtolower($methodName);

        $readVerbs = ['index', 'show', 'list', 'get', 'view', 'search', 'find'];
        $deleteVerbs = ['destroy', 'delete', 'remove'];
        $writeVerbs = ['store', 'create', 'update', 'edit', 'patch', 'put', 'duplicate', 'send', 'toggle', 'split', 'match'];

        if (in_array($verb, $readVerbs, true)) {
            return ['likely_type' => 'read', 'verb' => $verb];
        }
        if (in_array($verb, $deleteVerbs, true)) {
            return ['likely_type' => 'delete', 'verb' => $verb];
        }
        if (in_array($verb, $writeVerbs, true)) {
            return ['likely_type' => 'write', 'verb' => $verb];
        }

        // Unknown: default to write (safer — triggers confirmation downstream).
        return ['likely_type' => 'write', 'verb' => $verb];
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
