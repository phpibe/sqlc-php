<?php

declare(strict_types=1);

namespace SqlcPhp\Generator;

use SqlcPhp\Resolver\ResolvedColumn;

/**
 * Generates extension trait scaffolds for models and DTOs.
 *
 * Each generated model and DTO class includes a `use XExtension` statement
 * pointing to a trait that sqlc-php generates ONCE and never overwrites.
 * The user adds domain methods to these traits freely.
 *
 * The scaffold uses two complementary docblock tags for full tooling support:
 *
 *   @mixin \Full\Class\Name
 *     → PhpStorm, Intelephense and Psalm resolve $this as the host class.
 *       Full autocomplete for all properties AND methods of the host.
 *
 *   @property type $name  (one per host property)
 *     → PHPStan understands individual properties independently of @mixin.
 *       Also serves as readable documentation of the available surface area.
 *
 * Directory structure:
 *   Extensions/
 *     Models/                         ← table model extensions
 *       UserExtension.php
 *     DTOs/                           ← query DTO extensions
 *       BillingDetails/               (scoped_dtos: false → flat)
 *         BillingDetailsExtension.php
 *       ReserveBilling/               (scoped_dtos: true → mirrored)
 *         GetByReserveId/
 *           ReserveBillingExtension.php
 *
 * Activated by declaring `extensions:` in the `out:` map:
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
     * @param  string  $className  e.g. 'User'
     * @param  array   $columns    Column definitions — each item has 'name' and 'phpType'
     * @param  string  $hostFqcn   FQCN of the host model, e.g. 'App\Database\Models\User'
     * @return ExtensionData
     */
    public function forModel(string $className, array $columns, string $hostFqcn = ''): ExtensionData
    {
        $traitName = $className . 'Extension';
        $namespace = $this->nsModels;
        $relPath   = "{$traitName}.php";

        $props = array_map(fn($col) => [
            'name'    => $col->name ?? $col['name'],
            'type'    => is_string($col) ? 'mixed' : ($col->phpType ?? $col['phpType'] ?? 'mixed'),
            'fqcn'    => is_array($col) ? ($col['fqcn'] ?? null) : null,
        ], $columns);

        return new ExtensionData(
            traitName:    $traitName,
            namespace:    $namespace,
            fqcn:         $namespace . '\\' . $traitName,
            relPath:      $relPath,
            scaffoldCode: $this->buildScaffold($traitName, $namespace, $className, $props, 'model', $hostFqcn),
        );
    }

    /**
     * Generate extension data for a DTO class.
     *
     * @param  string              $className   e.g. 'ReserveBilling'
     * @param  ResolvedColumn[]    $columns     Flat (non-embed) columns for the DTO
     * @param  array               $embeds      Embed definitions [{className, propertyName}]
     * @param  string|null         $scopeSubdir e.g. 'ReserveBilling/GetByReserveId'
     * @param  string              $hostFqcn    FQCN of the host DTO
     * @return ExtensionData
     */
    public function forDto(
        string  $className,
        array   $columns,
        array   $embeds      = [],
        ?string $scopeSubdir = null,
        string  $hostFqcn    = '',
    ): ExtensionData {
        $traitName = $className . 'Extension';

        if ($scopeSubdir !== null) {
            $nsSuffix  = str_replace('/', '\\', $scopeSubdir);
            $namespace = $this->nsDtos . '\\' . $nsSuffix;
            $relPath   = $scopeSubdir . '/' . $traitName . '.php';
        } else {
            $namespace = $this->nsDtos;
            $relPath   = $traitName . '.php';
        }

        $props = [];
        foreach ($columns as $col) {
            if ($col instanceof ResolvedColumn) {
                $props[] = ['name' => $col->alias, 'type' => $col->phpType, 'fqcn' => null];
            } elseif (is_array($col)) {
                $props[] = ['name' => $col['name'], 'type' => $col['phpType'], 'fqcn' => $col['fqcn'] ?? null];
            }
        }
        foreach ($embeds as $embed) {
            if (is_array($embed)) {
                // Enriched embed: ['className', 'propName', 'nullable', 'fqcn']
                $embedClass = $embed['className'];
                $propName   = $embed['propName'] ?? lcfirst($embedClass);
                $nullable   = $embed['nullable'] ?? false;
                $embedFqcn  = $embed['fqcn'] ?? null;
            } else {
                // Legacy EmbedDefinition object
                $embedClass = $embed->className;
                $propName   = method_exists($embed, 'propertyName')
                    ? $embed->propertyName()
                    : (isset($embed->propertyName) ? $embed->propertyName : lcfirst($embedClass));
                $nullable   = false;
                // Derive embed FQCN from host namespace
                $hostDtoNs = $hostFqcn !== ''
                    ? implode('\\', array_slice(explode('\\', $hostFqcn), 0, -1))
                    : null;
                $embedFqcn = $hostDtoNs !== null ? $hostDtoNs . '\\' . $embedClass : null;
            }
            $displayType = $nullable ? "?{$embedClass}" : $embedClass;
            $props[] = [
                'name' => $propName,
                'type' => $displayType,
                'fqcn' => $embedFqcn,
            ];
        }

        return new ExtensionData(
            traitName:    $traitName,
            namespace:    $namespace,
            fqcn:         $namespace . '\\' . $traitName,
            relPath:      $relPath,
            scaffoldCode: $this->buildScaffold($traitName, $namespace, $className, $props, 'dto', $hostFqcn),
        );
    }

    // =========================================================================
    // Code injection — called by ModelGenerator and ResultDtoGenerator
    // =========================================================================

    /**
     * Inject into a generated class:
     *   - `use FQCN;` after the namespace declaration
     *   - `use TraitName;` as the first statement in the class body
     *
     * Note: we intentionally do NOT inject `@mixin TraitName` into the host class
     * docblock. PHPStan rejects @mixin when the target is a trait (rule: mixin.trait)
     * because PHPStan already resolves traits via the `use` statement — no annotation
     * needed. PhpStorm and Intelephense also resolve traits via `use` directly.
     *
     * The @mixin tag lives in the scaffold (XExtension.php), pointing back at the
     * host class — that gives the IDE full $this context inside the trait methods.
     */
    public function injectIntoClass(string $code, ExtensionData $ext): string
    {
        // 1. `use FQCN;` after namespace
        $code = preg_replace(
            '/^(namespace [^;]+;)/m',
            "$1\n\nuse {$ext->fqcn};",
            $code,
            1
        ) ?? $code;

        // 2. `use TraitName;` as first line inside the class body
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
     * Build the write-once trait scaffold with @property + @mixin docblock.
     *
     * @param string $traitName  e.g. 'UserExtension'
     * @param string $namespace  PHP namespace for the trait
     * @param string $className  Short name of the host class, e.g. 'User'
     * @param array  $props      [{name, type}, ...] — all host properties
     * @param string $kind       'model' | 'dto'
     * @param string $hostFqcn   FQCN of the host class for @mixin
     */
    private function buildScaffold(
        string $traitName,
        string $namespace,
        string $className,
        array  $props,
        string $kind,
        string $hostFqcn,
    ): string {
        $kindLabel  = $kind === 'model' ? 'model' : 'DTO';
        $sourceNote = $kind === 'model'
            ? "Extends the `{$className}` table model."
            : "Extends the `{$className}` result DTO.";

        // Collect FQCNs that need `use` statements in the trait file.
        // These are class-type properties (enums, DateTimeImmutable, embeds)
        // whose short name would be unresolvable without a use declaration.
        $useStatements = [];

        // Host class itself (for @mixin)
        if ($hostFqcn !== '') {
            $hostShort = basename(str_replace('\\', '/', $hostFqcn));
            $useStatements[$hostShort] = $hostFqcn;
        }

        // Per-property FQCNs — provided when the type is a class (enum, DateTimeImmutable, etc.)
        foreach ($props as $p) {
            $fqcn = $p['fqcn'] ?? null;
            if ($fqcn === null) continue;
            // Strip leading backslash for use statements
            $fqcn  = ltrim($fqcn, '\\');
            $short = basename(str_replace('\\', '/', $fqcn));
            if ($short !== '' && !isset($useStatements[$short])) {
                $useStatements[$short] = $fqcn;
            }
        }

        // Build `use` block — skip if FQCN is in the same namespace as the trait,
        // or if it's a global PHP class (leading backslash / no namespace)
        $globalClasses = ['DateTimeImmutable', 'DateTimeInterface', 'DateTime', 'DateInterval', 'Throwable', 'Exception'];
        $useLines = '';
        foreach ($useStatements as $short => $fqcn) {
            $fqcnNs = implode('\\', array_slice(explode('\\', $fqcn), 0, -1));
            if ($fqcnNs === $namespace) continue;       // same namespace — no use needed
            if (in_array($short, $globalClasses, true)) continue; // global — use backslash prefix
            $useLines .= "use {$fqcn};\n";
        }
        $useBlock = $useLines !== '' ? "\n{$useLines}" : '';

        // @mixin line — points to the always-current generated host class
        $mixinLine = $hostFqcn !== ''
            ? " * @mixin " . basename(str_replace('\\', '/', $hostFqcn)) . "\n"
            : '';

        // @property lines — one per property for PHPStan + human readability
        $propertyLines = '';
        if (!empty($props)) {
            // Build display type — use short class name (covered by use statement above),
            // or backslash-prefixed for global PHP classes (DateTimeImmutable, etc.)
            $globalClasses = ['DateTimeImmutable', 'DateTimeInterface', 'DateTime', 'DateInterval', 'Throwable', 'Exception'];
            $displayTypes = array_map(function ($p) use ($globalClasses): string {
                $fqcn     = $p['fqcn'] ?? null;
                $nullable = str_starts_with($p['type'], '?');
                if ($fqcn === null) return $p['type'];
                $short = basename(str_replace('\\', '/', ltrim($fqcn, '\\')));
                // Global PHP classes: use \ClassName prefix instead of a use statement
                if (in_array($short, $globalClasses, true)) {
                    return $nullable ? "?\\{$short}" : "\\{$short}";
                }
                return $nullable ? "?{$short}" : $short;
            }, $props);

            $maxType = max(array_map('strlen', $displayTypes));
            foreach ($props as $i => $p) {
                $display        = $displayTypes[$i];
                $pad            = str_repeat(' ', $maxType - strlen($display));
                $propertyLines .= " * @property {$display}{$pad} \${$p['name']}\n";
            }
        }

        $docExtras = $mixinLine !== '' || $propertyLines !== ''
            ? " *\n{$mixinLine}{$propertyLines}"
            : '';

        $exampleMethod = $kind === 'model'
            ? "    // public function isActive(): bool\n    // {\n    //     return \$this->active === 1;\n    // }"
            : "    // public function toApiArray(): array\n    // {\n    //     return ['id' => \$this->id];\n    // }";

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};
{$useBlock}
/**
 * Extension trait for the `{$className}` {$kindLabel}.
 * {$sourceNote}
 *
 * ╔══════════════════════════════════════════════════════════╗
 * ║  This file is yours — sqlc-php will NEVER overwrite it.  ║
 * ╚══════════════════════════════════════════════════════════╝
 *
 * Add domain methods that operate on `{$className}` properties.
 * All properties of the {$kindLabel} are available via `\$this`.
{$docExtras} *
 * Example:
{$exampleMethod}
 */
trait {$traitName}
{
    // Your methods here
}
PHP;
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
