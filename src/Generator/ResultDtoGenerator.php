<?php

declare(strict_types=1);

namespace SqlcPhp\Generator;

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
 * Naming convention: {QueryName}Row  (e.g. GetUserWithRoleRow)
 */
class ResultDtoGenerator
{
    public function __construct(
        private readonly string               $namespace,
        private readonly ?TypeMapperInterface $typeMapper = null,
    ) {}

    public function dtoClassName(QueryDefinition $query): string
    {
        return $query->dtoClassName ?? ucfirst($query->name) . 'Row';
    }

    /**
     * Derive the scoped namespace for a query method.
     *
     * Structure: {baseNamespace}\{Group}\{MethodPascalCase}
     *
     * Examples:
     *   namespace "App\DTOs", group "ReserveBilling", method "getDetails"
     *   → "App\DTOs\ReserveBilling\GetDetails"
     *
     *   namespace "App\DTOs", group "User", method "listActiveUsers"
     *   → "App\DTOs\User\ListActiveUsers"
     */
    public function scopedNamespace(QueryDefinition $query): string
    {
        $group  = $query->group;                // PascalCase group (@class)
        $method = ucfirst($query->name);        // PascalCase method
        return rtrim($this->namespace, '\\') . '\\' . $group . '\\' . $method;
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
     *   scopeSubdir:   string|null,
     *   namespace:     string,
     *   extensions:    array<string, ExtensionData>,
     * }
     */
    public function generate(QueryDefinition $query, bool $scoped = false, ?ExtensionGenerator $extGen = null): array
    {
        $namespace = $scoped ? $this->scopedNamespace($query) : $this->namespace;
        $className = $this->dtoClassName($query);
        $embeds    = $query->embeds;
        $columns   = $query->resultColumns;

        // Split columns into: flat (no embed match) + per-embed groups.
        // Sort embeds by prefix length DESC so that longer (more specific) prefixes
        // match before shorter ones. e.g. "role_type_" wins over "role_".
        $sortedEmbeds = $embeds;
        usort($sortedEmbeds, fn($a, $b) => strlen($b->prefix) <=> strlen($a->prefix));

        $flatColumns  = [];
        $embedColumns = [];   // className → ResolvedColumn[]

        foreach ($embeds as $embed) {
            $embedColumns[$embed->className] = [];
        }

        foreach ($columns as $col) {
            $assigned = false;
            foreach ($sortedEmbeds as $embed) {
                if ($embed->matches($col->alias)) {
                    $embedColumns[$embed->className][] = $col;
                    $assigned = true;
                    break;
                }
            }
            if (!$assigned) {
                $flatColumns[] = $col;
            }
        }

        // Build constructor properties and fromRow arguments
        $props    = [];
        $fromArgs = [];

        foreach ($flatColumns as $col) {
            $props[]    = "        public {$col->phpType} \${$col->alias},";
            $fromArgs[] = $this->buildCast($col);
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

        $propsStr    = implode("\n", $props);
        $fromArgsStr = implode("\n", $fromArgs);
        $sourceDesc  = $this->buildSourceDescription($columns);

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

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

        $scopeSubdir = $scoped ? $this->scopeSubdirFor($query) : null;

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

            $propsWithFqcn = $this->attachFqcns($flatColumns);
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
        }

        return [
            'className'   => $className,
            'code'        => $code,
            'embeds'      => $embedFiles,
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
