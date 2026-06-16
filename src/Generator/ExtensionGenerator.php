<?php

declare(strict_types=1);

namespace SqlcPhp\Generator;

use SqlcPhp\Resolver\ResolvedColumn;

/**
 * Generates extension trait scaffolds for models and DTOs.
 *
 * Each generated model and DTO class includes a `use XExtension` statement
 * pointing to a trait that sqlc-php generates ONCE and never overwrites.
 * The user can add domain methods to these traits freely.
 *
 * Directory structure:
 *   Extensions/
 *     Models/
 *       UserExtension.php           ← scaffold for User model
 *       BillingConfigExtension.php
 *     DTOs/
 *       BillingDetails/             (scoped_dtos: false → flat)
 *         BillingDetailsExtension.php
 *       ReserveBilling/             (scoped_dtos: true → mirrored)
 *         GetByReserveId/
 *           ReserveBillingExtension.php
 *           ReserveBillingReserveExtension.php
 *
 * The extension is activated by declaring `extensions:` in the `out:` map:
 *
 *   out:
 *     extensions: app/Database/Extensions
 */
class ExtensionGenerator
{
    public function __construct(
        /** Base namespace for model extensions: e.g. App\Database\Extensions\Models */
        private readonly string $nsModels,
        /** Base namespace for DTO extensions: e.g. App\Database\Extensions\DTOs */
        private readonly string $nsDtos,
    ) {}

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Generate extension data for a model class.
     *
     * @param  string              $className  e.g. 'User'
     * @param  array               $columns    Column definitions with name + phpType
     * @return ExtensionData
     */
    public function forModel(string $className, array $columns): ExtensionData
    {
        $traitName = $className . 'Extension';
        $namespace = $this->nsModels;
        $relPath   = "{$traitName}.php";

        $props = array_map(fn($col) => [
            'name' => $col->name ?? $col['name'],
            'type' => is_string($col) ? 'mixed' : ($col->phpType ?? $col['phpType'] ?? 'mixed'),
        ], $columns);

        return new ExtensionData(
            traitName:    $traitName,
            namespace:    $namespace,
            fqcn:         $namespace . '\\' . $traitName,
            relPath:      $relPath,
            scaffoldCode: $this->buildScaffold($traitName, $namespace, $className, $props, 'model'),
        );
    }

    /**
     * Generate extension data for a DTO class.
     *
     * @param  string              $className   e.g. 'ReserveBilling'
     * @param  ResolvedColumn[]    $columns     Flat (non-embed) columns for the DTO
     * @param  array               $embeds      Embed definitions [{className, propertyName}]
     * @param  string|null         $scopeSubdir e.g. 'ReserveBilling/GetByReserveId'
     * @return ExtensionData
     */
    public function forDto(
        string  $className,
        array   $columns,
        array   $embeds      = [],
        ?string $scopeSubdir = null,
    ): ExtensionData {
        $traitName = $className . 'Extension';

        // Namespace mirrors the DTO scope: DTOs\ReserveBilling\GetByReserveId → Extensions\DTOs\ReserveBilling\GetByReserveId
        if ($scopeSubdir !== null) {
            $nsSuffix  = str_replace('/', '\\', $scopeSubdir);
            $namespace = $this->nsDtos . '\\' . $nsSuffix;
            $relPath   = $scopeSubdir . '/' . $traitName . '.php';
        } else {
            $namespace = $this->nsDtos;
            $relPath   = $traitName . '.php';
        }

        // Build property list for docblock
        $props = [];
        foreach ($columns as $col) {
            if ($col instanceof ResolvedColumn) {
                $props[] = ['name' => $col->alias, 'type' => $col->phpType];
            }
        }
        // Add embed properties
        foreach ($embeds as $embed) {
            $embedClass = $embed->className ?? $embed['className'];
            $propName   = method_exists($embed, 'propertyName')
                ? $embed->propertyName()
                : (isset($embed->propertyName) ? $embed->propertyName : lcfirst($embedClass));
            $props[] = ['name' => $propName, 'type' => $embedClass];
        }

        return new ExtensionData(
            traitName:    $traitName,
            namespace:    $namespace,
            fqcn:         $namespace . '\\' . $traitName,
            relPath:      $relPath,
            scaffoldCode: $this->buildScaffold($traitName, $namespace, $className, $props, 'dto'),
        );
    }

    // =========================================================================
    // Code injection helpers (called by ModelGenerator and ResultDtoGenerator)
    // =========================================================================

    /**
     * Inject a `use TraitName;` statement inside the class body,
     * a `@mixin TraitName` into the docblock, and a `use FQCN;` at the top.
     */
    public function injectIntoClass(string $code, ExtensionData $ext): string
    {
        // 1. Inject `use FQCN;` after the namespace declaration
        $code = preg_replace(
            '/^(namespace [^;]+;)/m',
            "$1\n\nuse {$ext->fqcn};",
            $code,
            1
        ) ?? $code;

        // 2. Inject `@mixin TraitName` at the end of the docblock
        //    Target: "Generated by sqlc-php — do not edit manually."
        $marker = ' * Generated by sqlc-php — do not edit manually.';
        if (str_contains($code, $marker)) {
            $code = str_replace(
                $marker,
                $marker . "\n *\n * @mixin {$ext->traitName}",
                $code
            );
        }

        // 3. Inject `use TraitName;` as first statement in the class body,
        //    followed by a blank line before the constructor.
        $className = substr($ext->traitName, 0, -strlen('Extension'));
        $code = preg_replace(
            '/^((?:readonly\s+)?class\s+' . preg_quote($className, '/') . '[^{]*\{)/m',
            "$1\n    use {$ext->traitName};\n",
            $code,
            1
        ) ?? $code;

        return $code;
    }

    // =========================================================================
    // Scaffold builder
    // =========================================================================

    /**
     * Build the write-once trait scaffold.
     *
     * Uses PHP 8.4 abstract properties to declare the contract between this
     * trait and its host class. The abstract declarations:
     *
     *   - Give the IDE full type information for $this inside the trait
     *   - Are enforced by PHP at compile time — if the host class removes
     *     a column that the trait declares abstract, PHP throws a fatal error
     *     immediately, making schema drift impossible to miss
     *   - Work natively with PHPStan, Psalm, and Intelephense without extra
     *     annotations or configuration
     *
     * When a new column is added to the schema, add a corresponding abstract
     * property here to get type-safe access to it in your methods.
     */
    private function buildScaffold(
        string $traitName,
        string $namespace,
        string $className,
        array  $props,
        string $kind,        // 'model' | 'dto'
    ): string {
        // Build the abstract property declarations block
        $abstractLines = '';
        if (!empty($props)) {
            $maxType = max(array_map(fn($p) => strlen($p['type']), $props));
            foreach ($props as $p) {
                // Sanitize type for PHP abstract property syntax.
                // DateTimeImmutable needs backslash, nullable types use ?prefix.
                $type = $this->toAbstractType($p['type']);
                $pad  = str_repeat(' ', $maxType - strlen($p['type']));
                $abstractLines .= "    abstract public {$type}{$pad} \${$p['name']};\n";
            }
        }

        $kindLabel  = $kind === 'model' ? 'model' : 'DTO';
        $sourceNote = $kind === 'model'
            ? "Extends the `{$className}` table model."
            : "Extends the `{$className}` result DTO.";

        $exampleMethod = $kind === 'model'
            ? " *   public function isActive(): bool\n *   {\n *       return \$this->active === 1;\n *   }"
            : " *   public function toApiArray(): array\n *   {\n *       return ['id' => \$this->id];\n *   }";

        $abstractBlock = $abstractLines !== ''
            ? "\n    // ── PHP 8.4 schema contract (enforced at compile time) ──────────────\n{$abstractLines}\n"
            : '';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

/**
 * Extension trait for the `{$className}` {$kindLabel}.
 * {$sourceNote}
 *
 * ╔══════════════════════════════════════════════════════════╗
 * ║  This file is yours — sqlc-php will NEVER overwrite it.  ║
 * ╚══════════════════════════════════════════════════════════╝
 *
 * The abstract properties below declare the contract between this trait
 * and the `{$className}` {$kindLabel}. PHP 8.4 enforces them at compile time:
 *
 *   ✓ Full IDE type inference for \$this inside your methods — no annotations needed.
 *   ✓ If the schema removes a column that is declared abstract here, PHP emits a
 *     fatal error at class load time — schema drift is impossible to miss.
 *   ✓ PHPStan, Psalm, and Intelephense understand abstract properties natively.
 *
 * When a new column is added to the schema, add an abstract property here
 * to get type-safe access to it in your domain methods.
 *
 * Example:
{$exampleMethod}
 */
trait {$traitName}
{{$abstractBlock}    // Your methods here
}
PHP;
    }

    /**
     * Convert a PHP type string to a valid type for an abstract property declaration.
     * Handles nullable (?type), DateTimeImmutable, arrays, and scalars.
     */
    private function toAbstractType(string $phpType): string
    {
        // Bare class names that need a leading backslash in property declarations
        $needsBackslash = ['DateTimeImmutable', 'DateTimeInterface', 'DateTime', 'DateInterval'];

        $nullable = str_starts_with($phpType, '?');
        $base     = ltrim($phpType, '?');

        if (in_array($base, $needsBackslash, true)) {
            $base = '\\' . $base;
        }

        return $nullable ? "?{$base}" : $base;
    }
}

// ============================================================================
// Value object returned by ExtensionGenerator
// ============================================================================

/**
 * All data needed to inject an extension trait into a generated class
 * and to write the scaffold file.
 */
readonly class ExtensionData
{
    public function __construct(
        /** Short trait name, e.g. 'UserExtension' */
        public string $traitName,
        /** PHP namespace of the trait */
        public string $namespace,
        /** Fully qualified class name */
        public string $fqcn,
        /** Path relative to the extensions base dir, e.g. 'UserExtension.php'
         *  or 'ReserveBilling/GetByReserveId/ReserveBillingExtension.php' */
        public string $relPath,
        /** Full PHP source code of the scaffold trait */
        public string $scaffoldCode,
    ) {}
}
