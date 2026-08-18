<?php

declare(strict_types=1);

namespace SqlcPhp\Generator;

use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Parser\EmbedDefinition;
use SqlcPhp\Generator\ExtensionData;
use SqlcPhp\Generator\ExtensionGenerator;
use SqlcPhp\Parser\QueryDefinition;
use SqlcPhp\Resolver\ResolvedColumn;
use SqlcPhp\TypeMapper\TypeMapperInterface;

/**
 * Generates a PHP readonly DTO for the result set of a query
 * that doesn't map 1:1 to a single table model.
 *
 * Supports @embed: columns whose alias matches an embed prefix are grouped
 * into a nested readonly value object instead of being flattened.
 *
 * Supports @json: columns containing JSON_ARRAYAGG output are typed as
 * arrays of a generated DTO class instead of plain PHP arrays.
 *
 * Naming convention: {QueryName}Row  (e.g. GetUserWithRoleRow)
 */
class ResultDtoGenerator
{
    public function __construct(
        private readonly string               $namespace,
        private readonly ?TypeMapperInterface $typeMapper       = null,
        private readonly ?SchemaCatalog       $catalog          = null,
        private readonly ?string              $modelsNamespace  = null,
        private readonly string               $datetimeFormat   = 'Y-m-d H:i:s',
    ) {}

    /**
     * Build the argument list for the Params DTO static from() method.
     * Each param is cast from $data['name'] to the correct PHP type.
     *
     * @param \SqlcPhp\Resolver\QueryParam[] $params
     */
    private function buildFromArrayBody(array $params): string
    {
        $lines = [];
        foreach ($params as $param) {
            $name     = $param->name;
            $phpType  = $param->phpType;
            $nullable = str_starts_with($phpType, '?');
            $bare     = ltrim($phpType, '?');
            $key      = "\$data['{$name}']";
            $nullKey  = "\$data['{$name}'] ?? null";

            $cast = $this->fromArrayCastExpr($key, $nullKey, $bare, $nullable, $param->optional || $param->inList === false && $nullable);
            $lines[] = "            {$cast},";
        }
        return implode("\n", $lines);
    }

    private function fromArrayCastExpr(string $key, string $nullKey, string $bare, bool $nullable, bool $allowMissing): string
    {
        $src = $allowMissing || $nullable ? $nullKey : $key;

        // BackedEnum — ::from() for required, ::tryFrom() for nullable
        if ($this->typeMapper?->needsValueExtraction($bare)
            || $this->typeMapper?->needsValueExtraction(($nullable ? '?' : '') . $bare)
        ) {
            if ($nullable) {
                return "({$nullKey}) !== null ? {$bare}::tryFrom((string) ({$nullKey})) : null";
            }
            return "{$bare}::from((string) {$key})";
        }

        // DateTimeImmutable
        if (in_array($bare, ['\\DateTimeImmutable', 'DateTimeImmutable'], true)) {
            if ($nullable) {
                return "({$nullKey}) !== null ? new \\DateTimeImmutable((string) ({$nullKey})) : null";
            }
            return "new \\DateTimeImmutable((string) {$key})";
        }

        // array / JSON — accept both array and JSON string
        if ($bare === 'array') {
            if ($nullable) {
                return "({$nullKey}) !== null ? (is_string({$nullKey}) ? json_decode({$nullKey}, true) : {$nullKey}) : null";
            }
            return "is_string({$key}) ? json_decode({$key}, true) : {$key}";
        }

        // int
        if ($bare === 'int') {
            return $nullable
                ? "({$nullKey}) !== null ? (int) ({$nullKey}) : null"
                : "(int) {$key}";
        }

        // float
        if ($bare === 'float') {
            return $nullable
                ? "({$nullKey}) !== null ? (float) ({$nullKey}) : null"
                : "(float) {$key}";
        }

        // bool
        if ($bare === 'bool') {
            return $nullable
                ? "({$nullKey}) !== null ? (bool) ({$nullKey}) : null"
                : "(bool) {$key}";
        }

        // string
        if ($bare === 'string') {
            return $nullable
                ? "({$nullKey}) !== null ? (string) ({$nullKey}) : null"
                : "(string) {$key}";
        }

        // Unknown / mixed — pass through
        return $src;
    }

    /**
     * Build the body of toArray() for a set of resolved columns.
     * Rules:
     *   - BackedEnum              → ->value  (or ?->value if nullable)
     *   - DateTimeImmutable       → ->format($datetimeFormat) (or ?->format(...))
     *   - Embedded DTO / model    → ->toArray() if available, else (array) cast
     *   - Everything else         → returned as-is
     *
     * @param ResolvedColumn[] $columns
     * @param array<string,string> $extraProps  ['propName' => 'phpType'] for array props (grouped items, tableModels)
     */
    private function buildToArrayBody(array $columns, array $extraProps = []): string
    {
        $lines = [];

        foreach ($columns as $col) {
            $alias   = $col->alias;
            $phpType = ltrim($col->phpType, '?');
            $nullable = str_starts_with($col->phpType, '?');
            $line    = $this->toArrayExpr("\$this->{$alias}", $phpType, $nullable);
            $lines[] = "            '{$alias}' => {$line},";
        }

        foreach ($extraProps as $propName => $phpType) {
            $nullable = str_starts_with($phpType, '?');
            $bare     = ltrim($phpType, '?');
            $line     = $this->toArrayExpr("\$this->{$propName}", $bare, $nullable);
            $lines[]  = "            '{$propName}' => {$line},";
        }

        return implode("\n", $lines);
    }

    private function toArrayExpr(string $expr, string $bareType, bool $nullable): string
    {
        $op = $nullable ? '?->' : '->';

        // BackedEnum → ->value
        if ($this->typeMapper?->needsValueExtraction($bareType)
            || $this->typeMapper?->needsValueExtraction(($nullable ? '?' : '') . $bareType)
        ) {
            return $nullable ? "{$expr}?->value" : "{$expr}->value";
        }

        // DateTimeImmutable → ->format(...)
        if (in_array($bareType, ['\\DateTimeImmutable', 'DateTimeImmutable', '\\DateTime', 'DateTime'], true)) {
            $fmt = addslashes($this->datetimeFormat);
            return $nullable ? "{$expr}?->format('{$fmt}')" : "{$expr}->format('{$fmt}')";
        }

        // Nested DTO with toArray() — use method_exists for safety
        if (preg_match('/^[A-Z]/', $bareType) && !in_array($bareType, ['array', 'string', 'int', 'float', 'bool', 'mixed'], true)) {
            if ($nullable) {
                return "{$expr} !== null ? (method_exists({$expr}, 'toArray') ? {$expr}->toArray() : (array) {$expr}) : null";
            }
            return "method_exists(\$this->{$bareType}, 'toArray') ? {$expr}->toArray() : (array) {$expr}";
        }

        // Scalar / array / mixed — return as-is
        return $expr;
    }

    public function dtoClassName(QueryDefinition $query): string
    {
        return $query->dtoClassName ?? ucfirst($query->name) . 'Row';
    }

    /**
     * Resolve the DTO namespace for a given scope mode.
     *
     *   'none'   → App\DTOs                           (flat, default)
     *   'class'  → App\DTOs\CmsConfig                 (grouped by @class)
     *   'method' → App\DTOs\CmsConfig\GetActive        (grouped by @class + @name)
     */
    public function scopedNamespace(QueryDefinition $query, string $dtoScope = 'none'): string
    {
        $base   = rtrim($this->namespace, '\\');
        $group  = $query->group;
        $method = ucfirst($query->name);

        return match ($dtoScope) {
            'class'  => $base . '\\' . $group,
            'method' => $base . '\\' . $group . '\\' . $method,
            default  => $base,
        };
    }

    /**
     * @deprecated Use scopedNamespace($query, 'method') instead.
     */
    public function scopedNamespaceOld(QueryDefinition $query): string
    {
        return $this->scopedNamespace($query, 'method');
    }

    /**
     * Resolve the DTO subdirectory path (relative to the DTOs base dir).
     * Mirrors scopedNamespace() but returns a filesystem path.
     */
    public function scopeSubdir(QueryDefinition $query, string $dtoScope = 'none'): ?string
    {
        return match ($dtoScope) {
            'class'  => $query->group,
            'method' => $query->group . '/' . ucfirst($query->name),
            default  => null,
        };
    }

    /**
     * Derive the scoped subdirectory path (relative to the DTOs base dir).
     * Matches the namespace structure: {Group}/{MethodPascalCase}
     */
    /**
     * Attach an 'fqcn' key to each column by querying the type mapper.
     * This resolves FQCNs for enums, DateTimeImmutable, and other class types
     * directly from the column's SQL type — not from the generated code's use
     * statements (which may be absent for enums in DTO files).
     *
     * @param  ResolvedColumn[] $columns
     * @return array<int, array{name: string, phpType: string, fqcn: string|null}>
     */
    private function attachFqcns(array $columns): array
    {
        return array_map(function ($col): array {
            $fqcn = $this->typeMapper?->toPhpFqcn(
                $col->sqlType,
                $col->tableName,
                $col->columnName,
            );
            return [
                'name'    => $col->alias,
                'phpType' => $col->phpType,
                'fqcn'    => $fqcn,
            ];
        }, $columns);
    }

    private function scopeSubdirFor(QueryDefinition $query): string
    {
        return $query->group . '/' . ucfirst($query->name);
    }

    /**
     * Generate the PHP code for the result DTO.
     *
     * @param  bool                  $scoped   When true, the DTO and its embeds use a namespace
     *                                         scoped to the query method name (scoped_dtos: true).
     * @param  ExtensionGenerator|null $extGen  When provided, injects extension trait and returns
     *                                         scaffold data for write-once generation.
     * @return array{
     *   className:     string,
     *   code:          string,
     *   embeds:        array<string, array{className: string, code: string}>,
     *   jsonDtos:      array<string, array{className: string, code: string}>,
     *   scopeSubdir:   string|null,
     *   namespace:     string,
     *   extensions:    array<string, ExtensionData>,
     * }
     */
    /**
     * Generate the PHP code for a :grouped result DTO.
     *
     * Splits result columns into two groups:
     *   - Scalar columns (from the primary/group-by table) → regular typed properties
     *   - Repeated columns (from JOINed tables) → typed sub-item class + array property
     *
     * The @group_by column identifies the primary table. All columns whose tableName
     * matches that table become scalar. All others become items in a sub-array.
     */
    public function generateGrouped(QueryDefinition $query, string $dtoScope = 'none', ?ExtensionGenerator $extGen = null): array
    {
        $namespace    = $this->scopedNamespace($query, $dtoScope);
        $className    = $this->dtoClassName($query);
        $groupByCol   = $query->groupByColumn ?? '';
        $columns      = $query->resultColumns;

        // Determine the primary table from @group_by (e.g. 'profiles.id' → 'profiles')
        $primaryTable = str_contains($groupByCol, '.')
            ? explode('.', $groupByCol, 2)[0]
            : '';

        // Split: scalar (primary table) vs repeated (join side)
        $scalarCols   = [];
        $repeatedCols = [];
        foreach ($columns as $col) {
            if ($primaryTable === '' || strtolower($col->tableName) === strtolower($primaryTable)) {
                $scalarCols[] = $col;
            } else {
                $repeatedCols[] = $col;
            }
        }

        // Generate the item class for repeated columns (e.g. GetProfileWithReservesItem)
        $itemClassName = rtrim($className, 'Row') . 'Item';
        $itemProps     = [];
        $itemFromArgs  = [];
        foreach ($repeatedCols as $col) {
            $cast           = $this->typeMapper?->fromRowCast($col->phpType, $col->alias, $col->nullable) ?? "(string) \$row['{$col->alias}']";
            $itemProps[]    = "        public {$col->phpType} \${$col->alias},";
            $itemFromArgs[] = "            {$cast},";
        }
        $itemPropsStr    = implode("\n", $itemProps);
        $itemFromArgsStr = implode("\n", $itemFromArgs);

        $itemCode = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

/**
 * Item type for repeated (JOIN-side) columns in the `{$query->name}` grouped result.
 * Generated by sqlc-php — do not edit manually.
 */
readonly class {$itemClassName}
{
    public function __construct(
{$itemPropsStr}
    ) {}

    /** @param array<string, mixed> \$row */
    public static function fromRow(array \$row): self
    {
        return new self(
{$itemFromArgsStr}
        );
    }
}
PHP;

        // Determine item property name from the repeated table name
        $itemPropName = !empty($repeatedCols) ? ($repeatedCols[0]->tableName ?: 'items') : 'items';

        // Generate the main DTO with scalar properties + the array property
        $scalarProps    = [];
        $scalarFromArgs = [];
        foreach ($scalarCols as $col) {
            $cast              = $this->typeMapper?->fromRowCast($col->phpType, $col->alias, $col->nullable) ?? "(string) \$row['{$col->alias}']";
            $scalarProps[]    = "        public {$col->phpType} \${$col->alias},";
            $scalarFromArgs[] = "            {$cast},";
        }

        // The array property always comes last
        $scalarProps[]    = "        /** @var {$itemClassName}[] */\n        public array \${$itemPropName},";
        $scalarFromArgs[] = "            [],  // populated by groupResults()";

        $propsStr    = implode("\n", $scalarProps);
        $fromArgsStr = implode("\n", $scalarFromArgs);
        $sourceDesc  = $this->buildSourceDescription($columns);

        // Build the grouping key extraction (the @group_by column alias)
        $groupByAlias = str_contains($groupByCol, '.') ? explode('.', $groupByCol, 2)[1] : $groupByCol;

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

/**
 * Grouped result DTO for the `{$query->name}` query.
 * {$sourceDesc}
 * Generated by sqlc-php — do not edit manually.
 */
readonly class {$className}
{
    public function __construct(
{$propsStr}
    ) {}

    /** @param array<string, mixed> \$row */
    public static function fromRow(array \$row): self
    {
        return new self(
{$fromArgsStr}
        );
    }

    /**
     * Collapse a flat PDO result set (one row per JOIN) into grouped objects.
     * Each unique @group_by key becomes one {$className} with its {$itemClassName}[] populated.
     *
     * @param  iterable<array<string, mixed>> \$rows  Raw PDO rows
     * @return {$className}[]
     */
    public static function groupResults(iterable \$rows): array
    {
        /** @var array<mixed, array{dto: self, items: array}> \$acc */
        \$acc = [];
        foreach (\$rows as \$row) {
            \$key = \$row['{$groupByAlias}'];
            if (!isset(\$acc[\$key])) {
                \$acc[\$key] = ['dto' => self::fromRow(\$row), 'items' => []];
            }
            \$acc[\$key]['items'][] = {$itemClassName}::fromRow(\$row);
        }
        return array_values(array_map(
            fn(array \$entry) => new self(
                ...array_slice((array) \$entry['dto'], 0, -1),
                {$itemPropName}: \$entry['items'],
            ),
            \$acc,
        ));
    }
}
PHP;

        return [
            'className'   => $className,
            'code'        => $code,
            'itemClass'   => $itemClassName,
            'itemCode'    => $itemCode,
            'scopeSubdir' => $this->scopeSubdir($query, $dtoScope),
            'namespace'   => $namespace,
            'embeds'      => [],
            'jsonDtos'    => [],
            'extensions'  => [],
        ];
    }

    /**
     * Returns the Params DTO class name for a query using @with params.
     * e.g. query name 'createCmsConfig' → 'CreateCmsConfigParams'
     */
    public function paramsClassName(QueryDefinition $query): string
    {
        return ucfirst($query->name) . 'Params';
    }

    /**
     * Generate the readonly Params DTO for @with params queries.
     *
     * Groups all input parameters into a single readonly class, respecting:
     *   - optional params → nullable with = null default
     *   - @with params is only active when 2+ params exist
     *   - dto_scope applies (same subdirectory as result DTOs)
     *
     * @return array{className: string, code: string, scopeSubdir: string|null, namespace: string}
     */
    public function generateParams(QueryDefinition $query, string $dtoScope = 'none', ?ExtensionGenerator $extGen = null): array
    {
        $namespace  = $this->scopedNamespace($query, $dtoScope);
        $className  = $this->paramsClassName($query);

        $props    = [];
        $required = [];
        $optional = [];

        foreach ($query->params as $param) {
            if ($param->inList) {
                $required[] = $param;
            } elseif ($param->optional) {
                $optional[] = $param;
            } else {
                $required[] = $param;
            }
        }

        foreach ($required as $param) {
            if ($param->inList) {
                $props[] = "        public array \${$param->name},";
            } else {
                $props[] = "        public {$param->phpType} \${$param->name},";
            }
        }
        foreach ($optional as $param) {
            $type    = str_starts_with($param->phpType, '?') ? $param->phpType : '?' . $param->phpType;
            $props[] = "        public {$type} \${$param->name} = null,";
        }

        $propsStr    = implode("\n", $props);
        $fromBody    = $this->buildFromArrayBody(array_merge($required, $optional));

        $internalTag = ($query->visibility ?? 'public') === 'protected'
            ? "\n * @internal Used by the protected {$query->name}() method — not part of the public API."
            : '';

        // Build toArray() body from params
        $toArrayLines = [];
        foreach (array_merge($required, $optional) as $param) {
            $bare     = ltrim($param->phpType, '?');
            $nullable = str_starts_with($param->phpType, '?');
            $expr     = "\$this->{$param->name}";
            $line     = $this->toArrayExpr($expr, $bare, $nullable);
            $toArrayLines[] = "            '{$param->name}' => {$line},";
        }
        $toArrayBody = implode("\n", $toArrayLines);

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

/**
 * Input parameters for the `{$query->name}` query.{$internalTag}
 * Generated by sqlc-php — do not edit manually.
 */
readonly class {$className}
{
    public function __construct(
{$propsStr}
    ) {}

    /**
     * Construct from an associative array.
     * BackedEnum fields are cast with ::from() / ::tryFrom().
     * DateTimeImmutable fields are parsed from string.
     * JSON/array fields accept both array and JSON string.
     *
     * @param array<string, mixed> \$data
     */
    public static function from(array \$data): self
    {
        return new self(
{$fromBody}
        );
    }

    /**
     * Convert to an associative array.
     * BackedEnum values are unwrapped to their scalar value.
     * DateTimeImmutable values are formatted as '{$this->datetimeFormat}'.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
{$toArrayBody}
        ];
    }
}
PHP;

        $extensions = [];
        if ($extGen !== null) {
            $hostFqcn = $namespace . '\\' . $className;
            $ext      = $extGen->forDto($className, [], [], $this->scopeSubdir($query, $dtoScope), $hostFqcn);
            $code     = $extGen->injectIntoClass($code, $ext);
            $extensions[$ext->relPath] = $ext;
        }

        return [
            'className'   => $className,
            'code'        => $code,
            'scopeSubdir' => $this->scopeSubdir($query, $dtoScope),
            'namespace'   => $namespace,
            'extensions'  => $extensions,
        ];
    }

    public function generate(QueryDefinition $query, string $dtoScope = 'none', ?ExtensionGenerator $extGen = null): array
    {
        $namespace  = $this->scopedNamespace($query, $dtoScope);
        $className  = $this->dtoClassName($query);
        $embeds     = $query->embeds;
        $columns    = $query->resultColumns;

        // Split columns into: flat (no embed match) + per-embed groups.
        // Sort embeds by prefix length DESC so that longer (more specific) prefixes
        // match before shorter ones. e.g. "role_type_" wins over "role_".
        $sortedEmbeds = $embeds;
        usort($sortedEmbeds, fn($a, $b) => strlen($b->prefix) <=> strlen($a->prefix));

        // Collect @json and @type table.* mappings
        $jsonColumns  = $query->jsonColumns ?? [];
        $tableModels  = $query->tableModels  ?? [];

        // Validate: same table twice in @type table.* → self-join ambiguity
        $seenTables = [];
        foreach ($tableModels as $tm) {
            if (isset($seenTables[$tm['table']])) {
                throw new \RuntimeException(
                    "Query '{$query->name}': @type '{$tm['table']}.*' is declared more than once. " .
                    "Self-joins are not supported by @type table.* — use @embed with distinct prefixes instead."
                );
            }
            $seenTables[$tm['table']] = $tm['class'];
        }

        // Split columns: flat | @embed groups | @type table.* groups
        $flatColumns       = [];
        $embedColumns      = [];  // embedClassName → ResolvedColumn[]
        $tableModelColumns = [];  // tableName      → ResolvedColumn[]

        foreach ($embeds as $embed) {
            $embedColumns[$embed->className] = [];
        }
        foreach ($tableModels as $tm) {
            $tableModelColumns[$tm['table']] = [];
        }

        foreach ($columns as $col) {
            $assigned = false;
            // @embed prefix match takes priority
            foreach ($sortedEmbeds as $embed) {
                if ($embed->matches($col->alias)) {
                    $embedColumns[$embed->className][] = $col;
                    $assigned = true;
                    break;
                }
            }
            // @type table.* match by tableName
            if (!$assigned && $col->tableName !== '' && isset($tableModelColumns[$col->tableName])) {
                $tableModelColumns[$col->tableName][] = $col;
                $assigned = true;
            }
            if (!$assigned) {
                $flatColumns[] = $col;
            }
        }

        // Build constructor properties and fromRow arguments
        $props    = [];
        $fromArgs = [];

        // ── Flat columns (incl. @json) ──────────────────────────────────────
        foreach ($flatColumns as $col) {
                        if (isset($jsonColumns[$col->alias])) {
                // @type alias json:Class / json:Class[] / ?json:Class / ?json:Class[]
                // (also populated by legacy @json / @json:one / @json:many)
                $jsonDef  = $jsonColumns[$col->alias];
                $dtoClass = $jsonDef['class'];
                $isMany   = $jsonDef['many'];
                $nullable = $jsonDef['nullable'] ?? false;
                $access   = "\$row['{$col->alias}']";

                if ($isMany) {
                    if ($nullable) {
                        // ?json:Class[] → City[]|null — null when the JSON value is NULL
                        $props[]    = "        /** @var {$dtoClass}[]|null */\n        public ?array \${$col->alias},";
                        $fromArgs[] = "            isset({$access}) && {$access} !== null ? array_map(fn(array \$r) => {$dtoClass}::fromRow(\$r), json_decode((string) {$access}, true) ?? []) : null,";
                    } else {
                        // json:Class[] → City[]
                        $props[]    = "        /** @var {$dtoClass}[] */\n        public array \${$col->alias},";
                        $fromArgs[] = "            array_map(fn(array \$r) => {$dtoClass}::fromRow(\$r), json_decode((string) {$access}, true) ?? []),";
                    }
                } else {
                    if ($nullable) {
                        // ?json:Class → ?City
                        $props[]    = "        public ?{$dtoClass} \${$col->alias},";
                        $fromArgs[] = "            isset({$access}) && {$access} !== null ? {$dtoClass}::fromRow(json_decode((string) {$access}, true) ?? []) : null,";
                    } else {
                        // json:Class → City
                        $props[]    = "        public {$dtoClass} \${$col->alias},";
                        $fromArgs[] = "            {$dtoClass}::fromRow(json_decode((string) {$access}, true) ?? []),";
                    }
                }
            } else {
                $props[]    = "        public {$col->phpType} \${$col->alias},";
                $fromArgs[] = $this->buildCast($col);
            }
        }

        foreach ($embeds as $embed) {
            $cols = $embedColumns[$embed->className] ?? [];
            if (empty($cols)) continue;
            $propName   = $embed->propertyName();

            // If ALL columns in this embed group are nullable (e.g. all were @nillable
            // or all come from a LEFT JOIN side), the parent property is also nullable.
            // This makes the fromRow call conditional.
            $allNullable = !empty($cols) && count(array_filter($cols, fn($c) => !$c->nullable)) === 0;

            if ($allNullable) {
                $cast       = $this->buildNullableEmbedCast($embed, $cols);
                $props[]    = "        public ?{$embed->className} \${$propName},";
                $fromArgs[] = "            {$cast},";
            } else {
                $props[]    = "        public {$embed->className} \${$propName},";
                $fromArgs[] = "            {$embed->className}::fromRow(\$row),";
            }
        }

        // ── @type table.* — reutiliza modelo existente, hidratado con fromRow($row) ──
        foreach ($tableModels as $tm) {
            $cols       = $tableModelColumns[$tm['table']] ?? [];
            if (empty($cols)) continue;
            $rawClass   = $tm['class'];  // as written in the annotation
            $propName   = $tm['table'];  // property named after table name

            // Resolve the FQCN:
            //   - FQCN (contains \) → use as-is; short name in code, add use import
            //   - Short name + modelsNamespace set → prepend models namespace automatically
            //   - Short name + no modelsNamespace → use as-is (same namespace or global)
            if (str_contains($rawClass, '\\')) {
                $modelFqcn  = $rawClass;
                $modelShort = substr($rawClass, strrpos($rawClass, '\\') + 1);
            } elseif ($this->modelsNamespace !== null) {
                $modelFqcn  = $this->modelsNamespace . '\\' . $rawClass;
                $modelShort = $rawClass;
            } else {
                $modelFqcn  = $rawClass;
                $modelShort = $rawClass;
            }

            // If ALL matched columns are nullable (LEFT JOIN), the property is ?ModelClass
            $allNullable = !empty($cols) && count(array_filter($cols, fn($c) => !$c->nullable)) === 0;

            if ($allNullable) {
                $firstCol   = $cols[0]->alias;
                $props[]    = "        public ?{$modelShort} \${$propName},";
                $fromArgs[] = "            isset(\$row['{$firstCol}']) && \$row['{$firstCol}'] !== null ? {$modelShort}::fromRow(\$row) : null,";
            } else {
                $props[]    = "        public {$modelShort} \${$propName},";
                $fromArgs[] = "            {$modelShort}::fromRow(\$row),";
            }
        }
        $propsStr    = implode("\n", $props);
        $fromArgsStr = implode("\n", $fromArgs);
        $sourceDesc  = $this->buildSourceDescription($columns);

        // Build use imports for @type table.* classes.
        // Add when the resolved FQCN namespace differs from the current DTO namespace.
        $useImports = '';
        foreach ($tableModels as $tm) {
            if (empty($tableModelColumns[$tm['table']])) continue;
            $rawClass = $tm['class'];
            if (str_contains($rawClass, '\\')) {
                $fqcn = $rawClass;
            } elseif ($this->modelsNamespace !== null) {
                $fqcn = $this->modelsNamespace . '\\' . $rawClass;
            } else {
                continue;
            }
            $fqcnNs = implode('\\', array_slice(explode('\\', $fqcn), 0, -1));
            if ($fqcnNs !== $namespace) {
                $useImports .= "use {$fqcn};\n";
            }
        }
        if ($useImports !== '') {
            $useImports = "\n" . $useImports;
        }

        // Build toArray() body
        $extraProps = [];
        foreach ($embeds as $embed) {
            if (!empty($embedColumns[$embed->className])) {
                $extraProps[$embed->propertyName()] = $embed->className;
            }
        }
        foreach ($tableModels as $tm) {
            if (!empty($tableModelColumns[$tm['table']])) {
                $short = str_contains($tm['class'], '\\')
                    ? substr($tm['class'], strrpos($tm['class'], '\\') + 1)
                    : $tm['class'];
                $extraProps[$tm['table']] = $short;
            }
        }
        $toArrayBody = $this->buildToArrayBody($flatColumns, $extraProps);

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};
{$useImports}
/**
 * Result DTO for the `{$query->name}` query.
 * {$sourceDesc}
 * Generated by sqlc-php — do not edit manually.
 */
readonly class {$className}
{
    public function __construct(
{$propsStr}
    ) {}

    /**
     * Hydrate from a PDO result row (associative array).
     *
     * @param array<string, mixed> \$row
     */
    public static function fromRow(array \$row): self
    {
        return new self(
{$fromArgsStr}
        );
    }

    /**
     * Convert to an associative array.
     * BackedEnum values are unwrapped to their scalar value.
     * DateTimeImmutable values are formatted as '{$this->datetimeFormat}'.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
{$toArrayBody}
        ];
    }
}
PHP;

        // Generate one embedded value-object file per @embed group
        $embedGen   = new EmbedGenerator($namespace, $this->typeMapper);
        $embedFiles = [];

        foreach ($embeds as $embed) {
            $cols = $embedColumns[$embed->className] ?? [];
            if (empty($cols)) continue;
            ['className' => $cls, 'code' => $ec] = $embedGen->generate($embed, $cols);
            $embedFiles[$cls] = ['className' => $cls, 'code' => $ec];
        }

        // Generate one JSON DTO file per @json annotation
        $jsonDtoFiles = [];
        if (!empty($jsonColumns) && $this->catalog !== null && $this->typeMapper !== null) {
            $jsonDtoGen = new JsonDtoGenerator($this->catalog, $this->typeMapper);
            foreach ($jsonColumns as $alias => $jsonDef) {
                $dtoClass  = $jsonDef['class'];
                $tableName = $jsonDtoGen->resolveTableName($dtoClass);
                if ($tableName === null) {
                    throw new \RuntimeException(
                        "@type '{$alias}' references JSON DTO class '{$dtoClass}' but no matching table " .
                        "was found in the schema (tried: {$dtoClass}, " . strtolower($dtoClass) .
                        ", " . strtolower($dtoClass) . "s, ...). " .
                        "Declare the table in schema.sql or add a virtual_table entry."
                    );
                }
                ['className' => $cls, 'code' => $jc] = $jsonDtoGen->generate($dtoClass, $namespace, $tableName);
                $jsonDtoFiles[$cls] = ['className' => $cls, 'code' => $jc];
            }
        }

        $scopeSubdir = $this->scopeSubdir($query, $dtoScope);

        // ── Extension trait injection ────────────────────────────────────────
        $extensions = [];
        if ($extGen !== null) {
            // Main DTO extension — build enriched embed list with nullable + FQCN
            $hostFqcn   = $namespace . '\\' . $className;
            $hostDtoNs  = implode('\\', array_slice(explode('\\', $hostFqcn), 0, -1));

            $embedsForExt = [];
            foreach ($embeds as $embed) {
                $cols        = $embedColumns[$embed->className] ?? [];
                $allNullable = !empty($cols) && count(array_filter($cols, fn($c) => !$c->nullable)) === 0;
                $embedsForExt[] = [
                    'className' => $embed->className,
                    'propName'  => $embed->propertyName(),
                    'nullable'  => $allNullable,
                    'fqcn'      => $hostDtoNs . '\\' . $embed->className,
                ];
            }

            // Add @json columns to the main DTO extension as typed properties
            $jsonColumnsForExt = [];
            foreach ($jsonColumns as $alias => $jsonDef) {
                $nullable = $jsonDef['nullable'] ?? false;
                if ($jsonDef['many']) {
                    $phpType = $nullable ? '?array' : 'array';
                } else {
                    $phpType = $nullable ? '?' . $jsonDef['class'] : $jsonDef['class'];
                }
                $jsonColumnsForExt[] = ['name' => $alias, 'phpType' => $phpType, 'fqcn' => null];
            }

            // Exclude @json columns from flat columns — they are listed separately below
            $flatColumnsForExt = array_filter($flatColumns, fn($c) => !isset($jsonColumns[$c->alias]));
            $propsWithFqcn = array_merge($this->attachFqcns(array_values($flatColumnsForExt)), $jsonColumnsForExt);
            $dtoExt        = $extGen->forDto($className, $propsWithFqcn, $embedsForExt, $scopeSubdir, $hostFqcn);
            $code          = $extGen->injectIntoClass($code, $dtoExt);
            $extensions[$dtoExt->relPath] = $dtoExt;

            // Embed extensions
            foreach ($embeds as $embed) {
                $embedColSet = $embedColumns[$embed->className] ?? [];
                if (empty($embedColSet)) continue;
                $strippedCols = array_map(function ($col) use ($embed) {
                    return new \SqlcPhp\Resolver\ResolvedColumn(
                        alias:      $embed->stripPrefix($col->alias),
                        columnName: $col->columnName,
                        tableName:  $col->tableName,
                        sqlType:    $col->sqlType,
                        nullable:   $col->nullable,
                        phpType:    $col->phpType,
                    );
                }, $embedColSet);
                $embedHostFqcn      = $namespace . '\\' . $embed->className;
                $embedPropsWithFqcn = $this->attachFqcns($strippedCols);
                $embedExt           = $extGen->forDto($embed->className, $embedPropsWithFqcn, [], $scopeSubdir, $embedHostFqcn);
                if (isset($embedFiles[$embed->className])) {
                    $embedFiles[$embed->className]['code'] = $extGen->injectIntoClass(
                        $embedFiles[$embed->className]['code'],
                        $embedExt
                    );
                }
                $extensions[$embedExt->relPath] = $embedExt;
            }

            // JSON DTO extensions — one extension trait per @json DTO class
            if (!empty($jsonDtoFiles) && $this->catalog !== null) {
                $jsonDtoResv = new JsonDtoGenerator($this->catalog, $this->typeMapper);
                foreach ($jsonDtoFiles as $cls => ['className' => $jCls, 'code' => $jCode]) {
                    $jsonHostFqcn  = $namespace . '\\' . $jCls;
                    $jsonTableName = $jsonDtoResv->resolveTableName($jCls);
                    $jsonProps     = [];
                    if ($jsonTableName !== null) {
                        $table = $this->catalog->getTable($jsonTableName);
                        foreach ($table?->columns ?? [] as $col) {
                            $phpType     = $this->typeMapper->toPhpType($col->sqlType, $col->nullable, $jsonTableName, $col->name);
                            $jsonProps[] = ['name' => $col->name, 'phpType' => $phpType, 'fqcn' => null];
                        }
                    }
                    $jsonExt  = $extGen->forDto($jCls, $jsonProps, [], $scopeSubdir, $jsonHostFqcn);
                    $jsonDtoFiles[$cls]['code'] = $extGen->injectIntoClass($jCode, $jsonExt);
                    $extensions[$jsonExt->relPath] = $jsonExt;
                }
            }
        }

        return [
            'className'   => $className,
            'code'        => $code,
            'embeds'      => $embedFiles,
            'jsonDtos'    => $jsonDtoFiles,
            'scopeSubdir' => $scopeSubdir,
            'namespace'   => $namespace,
            'extensions'  => $extensions,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Generate a conditional fromRow cast for a nullable embed:
     * isset($row['prefix_firstcol']) ? EmbedClass::fromRow($row) : null
     */
    private function buildNullableEmbedCast(EmbedDefinition $embed, array $cols): string
    {
        // Use the first column of the embed as the null-check sentinel
        $sentinel = $cols[0]->alias ?? '';
        if ($sentinel === '') {
            return "{$embed->className}::fromRow(\$row)";
        }
        return "isset(\$row['{$sentinel}']) ? {$embed->className}::fromRow(\$row) : null";
    }

    private function buildCast(ResolvedColumn $col): string
    {
        if ($this->typeMapper !== null) {
            $cast = $this->typeMapper->fromRowCast($col->phpType, $col->alias, $col->nullable);
            return "            {$cast},";
        }

        // Fallback for when no mapper injected (backward compatibility)
        $base     = ltrim($col->phpType, '?\\');
        $access   = "\$row['{$col->alias}']";
        $nullable = str_starts_with($col->phpType, '?');

        if ($nullable) {
            return match($base) {
                'int'   => "            isset({$access}) ? (int) {$access} : null,",
                'float' => "            isset({$access}) ? (float) {$access} : null,",
                'bool'  => "            isset({$access}) ? (bool) {$access} : null,",
                'array' => "            isset({$access}) ? json_decode((string) {$access}, true) : null,",
                default => "            {$access} ?? null,",
            };
        }

        return match($base) {
            'int'   => "            (int) {$access},",
            'float' => "            (float) {$access},",
            'bool'  => "            (bool) {$access},",
            'array' => "            json_decode((string) {$access}, true) ?? [],",
            'mixed' => "            {$access},",
            default => "            (string) {$access},",
        };
    }

    private function buildSourceDescription(array $columns): string
    {
        $tables = array_unique(
            array_filter(array_map(fn($c) => $c->tableName, $columns))
        );

        if (empty($tables)) return 'Sources: unknown.';

        return 'Sources: ' . implode(', ', $tables) . '.';
    }
}
