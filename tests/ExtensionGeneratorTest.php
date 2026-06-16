<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Config\OutputConfig;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\ExtensionData;
use SqlcPhp\Generator\ExtensionGenerator;
use SqlcPhp\Generator\ModelGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for the extension trait scaffolding feature (v2.9.8).
 *
 * The `extensions:` output path activates write-once trait scaffolds that
 * let users add domain methods to models and DTOs without losing them on
 * the next `sqlc-php generate` run.
 *
 * Directory structure:
 *   Extensions/
 *     Models/     ← table model extensions
 *     DTOs/       ← query DTO extensions (mirrors scoped_dtos structure)
 *       Group/Method/  ← when scoped_dtos: true
 */
class ExtensionGeneratorTest extends TestCase
{
    private SchemaCatalog      $catalog;
    private MySQLTypeMapper    $mapper;
    private QueryParser        $parser;
    private QueryAnalyzer      $analyzer;
    private ExtensionGenerator $extGen;
    private ModelGenerator     $modelGen;
    private ResultDtoGenerator $dtoGen;

    private const NS_BASE       = 'App\\Database';
    private const NS_MODELS     = 'App\\Database\\Models';
    private const NS_DTOS       = 'App\\Database\\DTOs';
    private const NS_EXT_MODELS = 'App\\Database\\Extensions\\Models';
    private const NS_EXT_DTOS   = 'App\\Database\\Extensions\\DTOs';

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id     INT          AUTO_INCREMENT PRIMARY KEY,
                email  VARCHAR(100) NOT NULL,
                active TINYINT      NOT NULL DEFAULT 1
            );
            CREATE TABLE orders (
                id      INT            AUTO_INCREMENT PRIMARY KEY,
                user_id INT            NOT NULL,
                total   DECIMAL(10,2)  NOT NULL,
                status  VARCHAR(20)    NOT NULL DEFAULT 'pending'
            );
        SQL;

        $this->catalog   = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper    = new MySQLTypeMapper([], new EnumGenerator(self::NS_MODELS));
        $this->parser    = new QueryParser();
        $pr              = new ParamResolver($this->catalog, $this->mapper);
        $er              = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr              = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer  = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->extGen    = new ExtensionGenerator(self::NS_EXT_MODELS, self::NS_EXT_DTOS);
        $this->modelGen  = new ModelGenerator($this->catalog, $this->mapper, $this->parser, self::NS_MODELS);
        $this->dtoGen    = new ResultDtoGenerator(self::NS_DTOS, $this->mapper);
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    // =========================================================================
    // ExtensionGenerator::forModel
    // =========================================================================

    public function test_for_model_returns_extension_data(): void
    {
        $cols = [['name' => 'id', 'phpType' => 'int'], ['name' => 'email', 'phpType' => 'string']];
        $ext  = $this->extGen->forModel('User', $cols);

        $this->assertInstanceOf(ExtensionData::class, $ext);
        $this->assertSame('UserExtension', $ext->traitName);
        $this->assertSame(self::NS_EXT_MODELS, $ext->namespace);
        $this->assertSame(self::NS_EXT_MODELS . '\\UserExtension', $ext->fqcn);
        $this->assertSame('UserExtension.php', $ext->relPath);
    }

    public function test_for_model_scaffold_contains_trait_declaration(): void
    {
        $ext = $this->extGen->forModel('User', []);
        $this->assertStringContainsString('trait UserExtension', $ext->scaffoldCode);
        $this->assertStringContainsString('namespace ' . self::NS_EXT_MODELS, $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_has_property_tags_for_columns(): void
    {
        $cols = [
            ['name' => 'id',     'phpType' => 'int'],
            ['name' => 'email',  'phpType' => 'string'],
            ['name' => 'active', 'phpType' => 'int'],
        ];
        $ext = $this->extGen->forModel('User', $cols);
        $this->assertStringContainsString('@property int    $id',     $ext->scaffoldCode);
        $this->assertStringContainsString('@property string $email',  $ext->scaffoldCode);
        $this->assertStringContainsString('@property int    $active',  $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_nullable_property_tag(): void
    {
        $cols = [['name' => 'bio', 'phpType' => '?string']];
        $ext  = $this->extGen->forModel('User', $cols);
        $this->assertStringContainsString('@property ?string $bio', $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_datetime_property_tag(): void
    {
        $cols = [['name' => 'created_at', 'phpType' => '?DateTimeImmutable']];
        $ext  = $this->extGen->forModel('User', $cols);
        $this->assertStringContainsString('@property ?DateTimeImmutable $created_at', $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_has_never_overwrite_notice(): void
    {
        $ext = $this->extGen->forModel('User', []);
        $this->assertStringContainsString('NEVER overwrite', $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_has_mixin_tag_with_host_fqcn(): void
    {
        $cols = [['name' => 'id', 'phpType' => 'int', 'fqcn' => null]];
        $ext  = $this->extGen->forModel('User', $cols, self::NS_MODELS . '\\User');
        // @mixin uses the short class name (backed by a use statement in the scaffold)
        $this->assertStringContainsString('@mixin User', $ext->scaffoldCode);
        $this->assertStringContainsString('use ' . self::NS_MODELS . '\\User;', $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_no_mixin_when_no_host_fqcn(): void
    {
        $ext = $this->extGen->forModel('User', []);
        $this->assertStringNotContainsString('@mixin', $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_enum_property_gets_use_statement(): void
    {
        // Enum columns have a class type (BillingConfigVoucherType) — the scaffold
        // must include a `use` statement so PHPStan resolves the type correctly.
        $cols = [
            ['name' => 'id',           'phpType' => 'int',                           'fqcn' => null],
            ['name' => 'voucher_type',  'phpType' => 'BillingConfigVoucherType',      'fqcn' => 'App\\Database\\Enums\\BillingConfigVoucherType'],
            ['name' => 'provider',      'phpType' => 'BillingConfigProvider',         'fqcn' => 'App\\Database\\Enums\\BillingConfigProvider'],
        ];
        $ext = $this->extGen->forModel('BillingConfig', $cols, self::NS_MODELS . '\\BillingConfig');

        $this->assertStringContainsString('use App\\Database\\Enums\\BillingConfigVoucherType;', $ext->scaffoldCode,
            'Enum FQCN must appear as a use statement');
        $this->assertStringContainsString('use App\\Database\\Enums\\BillingConfigProvider;', $ext->scaffoldCode,
            'Second enum FQCN must also appear as a use statement');
        $this->assertStringContainsString('@property BillingConfigVoucherType', $ext->scaffoldCode,
            '@property must use short class name covered by the use statement');
        $this->assertStringContainsString('@property BillingConfigProvider', $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_datetime_uses_backslash_prefix_not_use(): void
    {
        // DateTimeImmutable is a global PHP class — no use statement, just \DateTimeImmutable
        $cols = [
            ['name' => 'created_at', 'phpType' => 'DateTimeImmutable', 'fqcn' => '\\DateTimeImmutable'],
        ];
        $ext = $this->extGen->forModel('User', $cols, self::NS_MODELS . '\\User');

        $this->assertStringNotContainsString('use DateTimeImmutable;', $ext->scaffoldCode,
            'DateTimeImmutable must NOT have a use statement — it is a global class');
        $this->assertStringContainsString('@property \\DateTimeImmutable $created_at', $ext->scaffoldCode,
            'DateTimeImmutable must use backslash prefix in @property tag');
    }

    public function test_for_model_scaffold_nullable_datetime_uses_backslash_prefix(): void
    {
        $cols = [
            ['name' => 'deleted_at', 'phpType' => '?DateTimeImmutable', 'fqcn' => '\\DateTimeImmutable'],
        ];
        $ext = $this->extGen->forModel('User', $cols);
        $this->assertStringContainsString('@property ?\\DateTimeImmutable $deleted_at', $ext->scaffoldCode);
        $this->assertStringNotContainsString('use DateTimeImmutable;', $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_property_types_aligned(): void
    {
        $cols = [
            ['name' => 'id',    'phpType' => 'int',    'fqcn' => null],
            ['name' => 'email', 'phpType' => 'string', 'fqcn' => null],
        ];
        $ext = $this->extGen->forModel('User', $cols);
        $this->assertStringContainsString('@property int    $id',    $ext->scaffoldCode);
        $this->assertStringContainsString('@property string $email', $ext->scaffoldCode);
    }

    // =========================================================================
    // ExtensionGenerator::forDto — flat (no scoped_dtos)
    // =========================================================================

    public function test_for_dto_flat_returns_extension_data(): void
    {
        $q   = $this->analyze("-- @name GetOrders\n-- @class Order\n-- @dto OrderRow\n-- @returns :many\nSELECT * FROM orders;");
        $ext = $this->extGen->forDto('OrderRow', $q[0]->resultColumns, [], null);

        $this->assertSame('OrderRowExtension', $ext->traitName);
        $this->assertSame(self::NS_EXT_DTOS, $ext->namespace);
        $this->assertSame(self::NS_EXT_DTOS . '\\OrderRowExtension', $ext->fqcn);
        $this->assertSame('OrderRowExtension.php', $ext->relPath);
    }

    // =========================================================================
    // ExtensionGenerator::forDto — scoped (with scoped_dtos: true)
    // =========================================================================

    public function test_for_dto_scoped_namespace_includes_group_and_method(): void
    {
        $q   = $this->analyze("-- @name GetDetails\n-- @class Order\n-- @dto OrderDetails\n-- @returns :one\nSELECT * FROM orders WHERE id = :id;");
        $ext = $this->extGen->forDto('OrderDetails', $q[0]->resultColumns, [], 'Order/GetDetails');

        $expectedNs = self::NS_EXT_DTOS . '\\Order\\GetDetails';
        $this->assertSame($expectedNs, $ext->namespace);
        $this->assertSame($expectedNs . '\\OrderDetailsExtension', $ext->fqcn);
        $this->assertSame('Order/GetDetails/OrderDetailsExtension.php', $ext->relPath);
    }

    public function test_for_dto_scoped_mirrors_dto_directory_structure(): void
    {
        // DTOs/ReserveBilling/GetByReserveId/ReserveBilling.php
        // → Extensions/DTOs/ReserveBilling/GetByReserveId/ReserveBillingExtension.php
        $ext = $this->extGen->forDto('ReserveBilling', [], [], 'ReserveBilling/GetByReserveId');

        $this->assertSame(
            'ReserveBilling/GetByReserveId/ReserveBillingExtension.php',
            $ext->relPath
        );
        $this->assertStringContainsString('ReserveBilling\\GetByReserveId', $ext->namespace);
    }

    // =========================================================================
    // ExtensionGenerator::injectIntoClass
    // =========================================================================

    public function test_inject_adds_use_fqcn_after_namespace(): void
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\nreadonly class User\n{\n    public function __construct(public int \$id) {}\n}\n";
        $ext  = new ExtensionData('UserExtension', 'App\\Ext', 'App\\Ext\\UserExtension', 'UserExtension.php', '');

        $result = $this->extGen->injectIntoClass($code, $ext);

        $this->assertStringContainsString('use App\\Ext\\UserExtension;', $result);
    }

    public function test_inject_does_not_add_mixin_in_host_class_docblock(): void
    {
        // PHPStan rejects @mixin when the target is a trait (rule: mixin.trait).
        // The host class must NOT have @mixin — PHPStan resolves the trait via `use`.
        // The @mixin lives only in the scaffold (pointing back at the host class).
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\n/**\n * DTO for users.\n * Generated by sqlc-php — do not edit manually.\n */\nreadonly class User\n{\n    public function __construct(public int \$id) {}\n}\n";
        $ext  = new ExtensionData('UserExtension', 'App\\Ext', 'App\\Ext\\UserExtension', 'UserExtension.php', '');

        $result = $this->extGen->injectIntoClass($code, $ext);

        $this->assertStringNotContainsString('@mixin UserExtension', $result,
            'Host class must NOT have @mixin — PHPStan rejects @mixin for trait targets');
    }

    public function test_inject_adds_use_trait_inside_class_body(): void
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\nreadonly class User\n{\n    public function __construct(public int \$id) {}\n}\n";
        $ext  = new ExtensionData('UserExtension', 'App\\Ext', 'App\\Ext\\UserExtension', 'UserExtension.php', '');

        $result = $this->extGen->injectIntoClass($code, $ext);

        // use TraitName; inside the class body
        $this->assertMatchesRegularExpression('/class User[^{]*\{[^}]*use UserExtension;/s', $result);
    }

    public function test_inject_no_mixin_in_host_regardless_of_calls(): void
    {
        // Even if called multiple times, @mixin must never appear in the host class
        $code = "<?php\ndeclare(strict_types=1);\nnamespace App;\n/**\n * Generated by sqlc-php — do not edit manually.\n */\nreadonly class Foo\n{\npublic function __construct(public int \$id) {}\n}\n";
        $ext  = new ExtensionData('FooExtension', 'App\\Ext', 'App\\Ext\\FooExtension', 'FooExtension.php', '');

        $result = $this->extGen->injectIntoClass($code, $ext);

        $this->assertStringNotContainsString('@mixin', $result,
            '@mixin must never appear in the generated host class');
    }

    // =========================================================================
    // ModelGenerator integration
    // =========================================================================

    public function test_model_generate_without_extgen_unchanged(): void
    {
        $m = $this->modelGen->generate('users');
        $this->assertArrayNotHasKey('extension', $m,
            'Without extGen, no extension key in result');
        $this->assertStringNotContainsString('use ', $m['code'],
            'Without extGen, no use statement injected');
    }

    public function test_model_generate_with_extgen_injects_use(): void
    {
        $m = $this->modelGen->generate('users', $this->extGen);

        $this->assertArrayHasKey('extension', $m);
        $this->assertInstanceOf(ExtensionData::class, $m['extension']);
        $this->assertSame('UserExtension', $m['extension']->traitName);

        // use FQCN at top of file
        $this->assertStringContainsString('use App\\Database\\Extensions\\Models\\UserExtension;', $m['code']);
        // use TraitName inside class body
        $this->assertStringContainsString('use UserExtension;', $m['code']);
        // @mixin must NOT appear in the host class (PHPStan mixin.trait rule)
        $this->assertStringNotContainsString('@mixin UserExtension', $m['code'],
            'Host class must not have @mixin — PHPStan rejects @mixin for trait targets');
    }

    public function test_model_generate_extension_relpath(): void
    {
        $m = $this->modelGen->generate('users', $this->extGen);
        $this->assertSame('UserExtension.php', $m['extension']->relPath);
    }

    public function test_model_generate_extension_scaffold_lists_columns(): void
    {
        $m = $this->modelGen->generate('users', $this->extGen);
        $scaffold = $m['extension']->scaffoldCode;
        $this->assertStringContainsString('$id',     $scaffold);
        $this->assertStringContainsString('$email',  $scaffold);
        $this->assertStringContainsString('$active', $scaffold);
    }

    // =========================================================================
    // ResultDtoGenerator integration
    // =========================================================================

    public function test_dto_generate_without_extgen_unchanged(): void
    {
        $q = $this->analyze("-- @name GetOrders\n-- @class Order\n-- @dto OrderRow\n-- @returns :many\nSELECT * FROM orders;");
        $r = $this->dtoGen->generate($q[0]);

        $this->assertArrayHasKey('extensions', $r);
        $this->assertEmpty($r['extensions'], 'Without extGen, extensions must be empty');
    }

    public function test_dto_generate_with_extgen_injects_use(): void
    {
        $q = $this->analyze("-- @name GetOrders\n-- @class Order\n-- @dto OrderRow\n-- @returns :many\nSELECT * FROM orders;");
        $r = $this->dtoGen->generate($q[0], scoped: false, extGen: $this->extGen);

        $this->assertNotEmpty($r['extensions']);
        $this->assertStringContainsString('use App\\Database\\Extensions\\DTOs\\OrderRowExtension;', $r['code']);
        $this->assertStringContainsString('use OrderRowExtension;', $r['code']);
        // @mixin must NOT appear in the host class
        $this->assertStringNotContainsString('@mixin OrderRowExtension', $r['code'],
            'Host class must not have @mixin — PHPStan rejects @mixin for trait targets');
    }

    public function test_dto_generate_scoped_extension_mirrors_dto_path(): void
    {
        $q = $this->analyze("-- @name GetDetails\n-- @class Order\n-- @dto OrderDetails\n-- @returns :one\nSELECT * FROM orders WHERE id = :id;");
        $r = $this->dtoGen->generate($q[0], scoped: true, extGen: $this->extGen);

        $this->assertArrayHasKey('Order/GetDetails/OrderDetailsExtension.php', $r['extensions']);
        $ext = $r['extensions']['Order/GetDetails/OrderDetailsExtension.php'];
        $this->assertSame('OrderDetailsExtension', $ext->traitName);
        $this->assertStringContainsString('Order\\GetDetails', $ext->namespace);
    }

    public function test_embed_extension_uses_stripped_property_names(): void
    {
        // Embed columns have prefixed aliases (user__email, user__active) in the result.
        // The embed DTO declares properties WITHOUT the prefix ($email, $active).
        // The embed extension's @property tags must match the DTO's property names.
        $q = $this->analyze(
            "-- @name GetOrderDetails\n-- @class Order\n-- @dto OrderDetails\n" .
            "-- @embed UserInfo user__\n-- @returns :one\n" .
            "SELECT orders.id, orders.total, orders.status, users.email as user__email, users.active as user__active\n" .
            "FROM orders INNER JOIN users ON orders.user_id = users.id WHERE orders.id = :id;"
        );
        $r = $this->dtoGen->generate($q[0], scoped: true, extGen: $this->extGen);

        // Find the UserInfo extension
        $embedExtKey = null;
        foreach (array_keys($r['extensions']) as $k) {
            if (str_contains($k, 'UserInfo')) { $embedExtKey = $k; break; }
        }
        $this->assertNotNull($embedExtKey, 'UserInfo extension must be generated');

        $scaffold = $r['extensions'][$embedExtKey]->scaffoldCode;

        // Must have stripped names (email, active) — not prefixed (user__email, user__active)
        $this->assertStringContainsString('@property string $email',  $scaffold);
        $this->assertStringContainsString('@property int    $active', $scaffold);
        $this->assertStringNotContainsString('user__email',  $scaffold);
        $this->assertStringNotContainsString('user__active', $scaffold);
    }

    // =========================================================================
    // OutputConfig — extensions support
    // =========================================================================

    public function test_output_config_has_extensions_returns_false_without_declaration(): void
    {
        $out = OutputConfig::fromRaw([
            'queries' => 'app/Repos',
            'models'  => 'app/Models',
            'dtos'    => 'app/DTOs',
        ], 'App\\Database');

        $this->assertFalse($out->hasExtensions());
    }

    public function test_output_config_has_extensions_returns_true_when_declared(): void
    {
        $out = OutputConfig::fromRaw([
            'queries'    => 'app/Repos',
            'models'     => 'app/Models',
            'dtos'       => 'app/DTOs',
            'extensions' => 'app/Extensions',
        ], 'App\\Database');

        $this->assertTrue($out->hasExtensions());
    }

    public function test_output_config_dir_for_extensions_models(): void
    {
        $out = OutputConfig::fromRaw([
            'extensions' => 'app/Extensions',
            'models'     => 'app/Models',
        ], 'App');

        $this->assertSame('app/Extensions/Models', $out->dirFor('extensions_models'));
        $this->assertSame('app/Extensions/DTOs',   $out->dirFor('extensions_dtos'));
    }

    public function test_output_config_namespace_for_extensions(): void
    {
        $out = OutputConfig::fromRaw([
            'extensions' => 'app/Database/Extensions',
            'models'     => 'app/Database/Models',
        ], 'App\\Database');

        $this->assertSame('App\\Database\\Extensions\\Models', $out->namespaceFor('extensions_models'));
        $this->assertSame('App\\Database\\Extensions\\DTOs',   $out->namespaceFor('extensions_dtos'));
    }

    public function test_dto_generate_with_enum_columns_has_use_statements(): void
    {
        // Regression: DTO extension scaffold was missing `use` for enum types because
        // extractUseMap read the DTO code's use statements (empty for enums) instead
        // of querying toPhpFqcn() directly from the type mapper.
        $schema = "
            CREATE TABLE billing_configs (
                id           INT          AUTO_INCREMENT PRIMARY KEY,
                voucher_type ENUM('A','B') NOT NULL,
                provider     ENUM('afip','test') NOT NULL
            );";
        $enumNs  = 'App\\Database\\Enums';
        $cat     = new \SqlcPhp\Catalog\SchemaCatalog((new \SqlcPhp\Parser\SchemaParser())->parse($schema));
        $enumGen = new \SqlcPhp\Generator\EnumGenerator($enumNs);
        $mapper  = new \SqlcPhp\TypeMapper\MySQLTypeMapper([], $enumGen);
        $parser  = new \SqlcPhp\Parser\QueryParser();
        $pr      = new \SqlcPhp\Resolver\ParamResolver($cat, $mapper);
        $er      = new \SqlcPhp\Resolver\ExpressionTypeResolver($cat, $mapper);
        $cr      = new \SqlcPhp\Resolver\ColumnResolver($cat, $mapper, $pr, $er);
        $az      = new \SqlcPhp\Analyzer\QueryAnalyzer($pr, $cr, $parser, new \SqlcPhp\Rewriter\SqlRewriter(), $cat);
        $dg      = new ResultDtoGenerator('App\\DTOs', $mapper);
        $extGen  = new ExtensionGenerator('App\\Extensions\\Models', 'App\\Extensions\\DTOs');

        $qs = $az->analyze($parser->parse(
            "-- @name ListBilling\n-- @class Billing\n-- @dto BillingRow\n-- @returns :many\n" .
            "SELECT * FROM billing_configs;"
        ));
        $r = $dg->generate($qs[0], scoped: true, extGen: $extGen);

        $extKey   = array_key_first($r['extensions']);
        $scaffold = $r['extensions'][$extKey]->scaffoldCode;

        $this->assertStringContainsString(
            "use {$enumNs}\\BillingConfigVoucherType;",
            $scaffold,
            'DTO extension must have use statement for enum BillingConfigVoucherType'
        );
        $this->assertStringContainsString(
            "use {$enumNs}\\BillingConfigProvider;",
            $scaffold,
            'DTO extension must have use statement for enum BillingConfigProvider'
        );
        // @property must use short names (resolved by use statements)
        $this->assertStringContainsString('@property BillingConfigVoucherType', $scaffold);
        $this->assertStringContainsString('@property BillingConfigProvider', $scaffold);
    }

    public function test_dto_with_embeds_generates_use_statements_for_embed_types(): void
    {
        // Regression: embed DTO types (Product, Reserve) appeared in @property tags
        // without a corresponding `use` statement — PHPStan/Psalm couldn't resolve them.
        $q = $this->analyze(
            "-- @name GetDetails\n-- @class Order\n-- @dto OrderDetails\n" .
            "-- @embed UserData user__\n-- @returns :one\n" .
            "SELECT orders.id, orders.total, orders.status,\n" .
            "       users.email as user__email, users.active as user__active\n" .
            "FROM orders INNER JOIN users ON orders.user_id = users.id WHERE orders.id = :id;"
        );
        $r = $this->dtoGen->generate($q[0], scoped: true, extGen: $this->extGen);

        $extKey = null;
        foreach (array_keys($r['extensions']) as $k) {
            if (str_ends_with($k, 'OrderDetailsExtension.php')) { $extKey = $k; break; }
        }
        $this->assertNotNull($extKey, 'OrderDetailsExtension must be generated');
        $scaffold = $r['extensions'][$extKey]->scaffoldCode;

        // @property UserData must use the short name resolved by a use statement
        // The property name comes from the embed prefix: user__ → $user
        $this->assertStringContainsString('@property UserData $user', $scaffold);
        $this->assertMatchesRegularExpression('/^use .*UserData;$/m', $scaffold,
            'use statement for embed class UserData must be present');
    }

    public function test_dto_nullable_embed_generates_nullable_property_tag(): void
    {
        // Nullable embed (all cols @nillable) → @property ?EmbedClass
        $q = $this->analyze(
            "-- @name GetOrderOpt\n-- @class Order\n-- @dto OrderOpt\n" .
            "-- @embed OptUser opt__\n-- @nillable opt__email\n-- @nillable opt__active\n-- @returns :opt\n" .
            "SELECT orders.id, orders.total, users.email as opt__email, users.active as opt__active\n" .
            "FROM orders LEFT JOIN users ON orders.user_id = users.id WHERE orders.id = :id;"
        );
        $r = $this->dtoGen->generate($q[0], scoped: true, extGen: $this->extGen);

        $extKey = null;
        foreach (array_keys($r['extensions']) as $k) {
            if (str_ends_with($k, 'OrderOptExtension.php')) { $extKey = $k; break; }
        }
        $this->assertNotNull($extKey);
        $scaffold = $r['extensions'][$extKey]->scaffoldCode;

        $this->assertStringContainsString('@property ?OptUser $opt', $scaffold,
            'Nullable embed must generate @property ?EmbedClass');
    }

    // =========================================================================
    // Enum extensions (v2.10.0)
    // =========================================================================

    public function test_for_enum_returns_extension_data(): void
    {
        $cases = [['name' => 'Afip', 'value' => 'afip'], ['name' => 'Test', 'value' => 'test']];
        $ext   = $this->extGen->forEnum('FacturantelogSupplier', $cases, 'string');

        $this->assertSame('FacturantelogSupplierExtension', $ext->traitName);
        $this->assertSame(self::NS_EXT_MODELS . '\\FacturantelogSupplierExtension', $ext->fqcn);
        $this->assertSame('FacturantelogSupplierExtension.php', $ext->relPath);
    }

    public function test_for_enum_scaffold_contains_trait_declaration(): void
    {
        $ext = $this->extGen->forEnum('OrderStatus', [], 'string');
        $this->assertStringContainsString('trait OrderStatusExtension', $ext->scaffoldCode);
        $this->assertStringContainsString('NEVER overwrite', $ext->scaffoldCode);
    }

    public function test_for_enum_scaffold_has_mixin_tag(): void
    {
        $ext = $this->extGen->forEnum('OrderStatus', [], 'string', self::NS_MODELS . '\\OrderStatus');
        $this->assertStringContainsString('@mixin OrderStatus', $ext->scaffoldCode);
        $this->assertStringContainsString('use ' . self::NS_MODELS . '\\OrderStatus;', $ext->scaffoldCode);
    }

    public function test_for_enum_scaffold_lists_cases(): void
    {
        $cases = [
            ['name' => 'Afip', 'value' => 'afip'],
            ['name' => 'Test', 'value' => 'test'],
        ];
        $ext = $this->extGen->forEnum('FacturantelogSupplier', $cases, 'string');

        $this->assertStringContainsString("FacturantelogSupplier::Afip", $ext->scaffoldCode);
        $this->assertStringContainsString("FacturantelogSupplier::Test", $ext->scaffoldCode);
        $this->assertStringContainsString("'afip'", $ext->scaffoldCode);
        $this->assertStringContainsString("'test'", $ext->scaffoldCode);
    }

    public function test_for_enum_scaffold_has_no_property_tags(): void
    {
        // Enums cannot have instance properties — @property must never appear
        $cases = [['name' => 'Active', 'value' => 'active']];
        $ext   = $this->extGen->forEnum('Status', $cases, 'string');

        $this->assertStringNotContainsString('@property', $ext->scaffoldCode,
            'Enum scaffold must NOT have @property — enums cannot have instance properties');
    }

    public function test_for_enum_scaffold_has_property_restriction_warning(): void
    {
        $ext = $this->extGen->forEnum('Status', [], 'string');
        $this->assertStringContainsString(
            'Traits used by enums MUST NOT declare instance properties',
            $ext->scaffoldCode
        );
    }

    public function test_for_enum_scaffold_example_uses_match_this(): void
    {
        $cases = [['name' => 'Active', 'value' => 'active'], ['name' => 'Inactive', 'value' => 'inactive']];
        $ext   = $this->extGen->forEnum('Status', $cases, 'string');

        $this->assertStringContainsString('match($this)', $ext->scaffoldCode);
    }

    public function test_inject_into_enum_adds_use_fqcn_after_namespace(): void
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Enums;\n\nenum Status: string\n{\n    case Active = 'active';\n}\n";
        $ext  = new ExtensionData('StatusExtension', 'App\\Ext', 'App\\Ext\\StatusExtension', 'StatusExtension.php', '');

        $result = $this->extGen->injectIntoEnum($code, $ext);

        $this->assertStringContainsString('use App\\Ext\\StatusExtension;', $result);
    }

    public function test_inject_into_enum_adds_use_trait_inside_enum_body(): void
    {
        $code = "<?php\ndeclare(strict_types=1);\nnamespace App\\Enums;\nenum Status: string\n{\n    case Active = 'active';\n}\n";
        $ext  = new ExtensionData('StatusExtension', 'App\\Ext', 'App\\Ext\\StatusExtension', 'StatusExtension.php', '');

        $result = $this->extGen->injectIntoEnum($code, $ext);

        $this->assertStringContainsString('use StatusExtension;', $result);
        // Must be inside the enum body
        $this->assertMatchesRegularExpression('/enum Status[^{]*\{[^}]*use StatusExtension;/s', $result);
    }

    public function test_enum_generator_without_extgen_unchanged(): void
    {
        $schema  = "CREATE TABLE orders (id INT PRIMARY KEY, status ENUM('active','inactive') NOT NULL);";
        $cat     = new \SqlcPhp\Catalog\SchemaCatalog((new \SqlcPhp\Parser\SchemaParser())->parse($schema));
        $enumGen = new \SqlcPhp\Generator\EnumGenerator('App\\Enums');

        $table = $cat->getTable('orders');
        $col   = array_values(array_filter($table->columns, fn($c) => $c->isEnum()))[0];

        $result = $enumGen->generate('orders', $col);

        $this->assertArrayNotHasKey('extension', $result);
        $this->assertStringNotContainsString('use ', $result['code'],
            'Without extGen no use statement injected');
    }

    public function test_enum_generator_with_extgen_injects_use_trait(): void
    {
        $schema  = "CREATE TABLE orders (id INT PRIMARY KEY, status ENUM('active','inactive') NOT NULL);";
        $cat     = new \SqlcPhp\Catalog\SchemaCatalog((new \SqlcPhp\Parser\SchemaParser())->parse($schema));
        $enumGen = new \SqlcPhp\Generator\EnumGenerator('App\\Enums');
        $extGen  = new \SqlcPhp\Generator\ExtensionGenerator(
            'App\\Extensions\\Models',
            'App\\Extensions\\DTOs',
            'App\\Extensions\\Enums',
        );

        $table = $cat->getTable('orders');
        $col   = array_values(array_filter($table->columns, fn($c) => $c->isEnum()))[0];

        $result = $enumGen->generate('orders', $col, $extGen);

        $this->assertArrayHasKey('extension', $result);
        $this->assertSame('OrderStatusExtension', $result['extension']->traitName);
        $this->assertStringContainsString('use App\\Extensions\\Enums\\OrderStatusExtension;', $result['code']);
        $this->assertStringContainsString('use OrderStatusExtension;', $result['code']);
        // No @mixin in the host enum
        $this->assertStringNotContainsString('@mixin', $result['code']);
    }

    public function test_output_config_dir_for_extensions_enums(): void
    {
        $out = \SqlcPhp\Config\OutputConfig::fromRaw([
            'extensions' => 'app/Extensions',
            'enums'      => 'app/Enums',
        ], 'App');

        $this->assertSame('app/Extensions/Enums', $out->dirFor('extensions_enums'));
    }

    public function test_output_config_namespace_for_extensions_enums(): void
    {
        $out = \SqlcPhp\Config\OutputConfig::fromRaw([
            'extensions' => 'app/Database/Extensions',
            'enums'      => 'app/Database/Enums',
        ], 'App\\Database');

        $this->assertSame('App\\Database\\Extensions\\Enums', $out->namespaceFor('extensions_enums'));
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_10_0(): void
    {
        $this->assertSame('2.10.0', \SqlcPhp\Version::VERSION);
    }
}
