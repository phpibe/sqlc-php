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

    public function test_for_model_scaffold_has_abstract_properties_for_columns(): void
    {
        // PHP 8.4: abstract properties replace docblock @property annotations.
        // The trait declares the contract; PHP enforces it at compile time.
        $cols = [
            ['name' => 'id',     'phpType' => 'int'],
            ['name' => 'email',  'phpType' => 'string'],
            ['name' => 'active', 'phpType' => 'int'],
        ];
        $ext = $this->extGen->forModel('User', $cols);
        $this->assertStringContainsString('abstract public int    $id;',     $ext->scaffoldCode);
        $this->assertStringContainsString('abstract public string $email;',  $ext->scaffoldCode);
        $this->assertStringContainsString('abstract public int    $active;', $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_nullable_abstract_property(): void
    {
        $cols = [['name' => 'bio', 'phpType' => '?string']];
        $ext  = $this->extGen->forModel('User', $cols);
        $this->assertStringContainsString('abstract public ?string $bio;', $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_datetime_abstract_property(): void
    {
        $cols = [['name' => 'created_at', 'phpType' => 'DateTimeImmutable']];
        $ext  = $this->extGen->forModel('User', $cols);
        // DateTimeImmutable needs a leading backslash in property declarations
        $this->assertStringContainsString('abstract public \DateTimeImmutable $created_at;', $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_has_never_overwrite_notice(): void
    {
        $ext = $this->extGen->forModel('User', []);
        $this->assertStringContainsString('NEVER overwrite', $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_has_abstract_properties_php84(): void
    {
        // PHP 8.4 abstract properties in traits are enforced by PHP at compile time.
        // When the host class removes a column that is declared abstract here,
        // PHP emits a fatal error at class load time — schema drift is immediately visible.
        $cols = [
            ['name' => 'id',     'phpType' => 'int'],
            ['name' => 'email',  'phpType' => 'string'],
            ['name' => 'active', 'phpType' => 'int'],
        ];
        $ext = $this->extGen->forModel('User', $cols);

        $this->assertStringContainsString('abstract public int    $id;',     $ext->scaffoldCode);
        $this->assertStringContainsString('abstract public string $email;',  $ext->scaffoldCode);
        $this->assertStringContainsString('abstract public int    $active;', $ext->scaffoldCode);
    }

    public function test_for_model_scaffold_abstract_section_label(): void
    {
        $cols = [['name' => 'id', 'phpType' => 'int']];
        $ext  = $this->extGen->forModel('User', $cols);
        $this->assertStringContainsString('PHP 8.4 schema contract', $ext->scaffoldCode);
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

    public function test_inject_adds_mixin_in_docblock(): void
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\n/**\n * DTO for users.\n * Generated by sqlc-php — do not edit manually.\n */\nreadonly class User\n{\n    public function __construct(public int \$id) {}\n}\n";
        $ext  = new ExtensionData('UserExtension', 'App\\Ext', 'App\\Ext\\UserExtension', 'UserExtension.php', '');

        $result = $this->extGen->injectIntoClass($code, $ext);

        $this->assertStringContainsString('@mixin UserExtension', $result);
    }

    public function test_inject_adds_use_trait_inside_class_body(): void
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\nreadonly class User\n{\n    public function __construct(public int \$id) {}\n}\n";
        $ext  = new ExtensionData('UserExtension', 'App\\Ext', 'App\\Ext\\UserExtension', 'UserExtension.php', '');

        $result = $this->extGen->injectIntoClass($code, $ext);

        // use TraitName; inside the class body
        $this->assertMatchesRegularExpression('/class User[^{]*\{[^}]*use UserExtension;/s', $result);
    }

    public function test_inject_mixin_appears_exactly_once(): void
    {
        $code = "<?php\ndeclare(strict_types=1);\nnamespace App;\n/**\n * Generated by sqlc-php — do not edit manually.\n */\nreadonly class Foo\n{\npublic function __construct(public int \$id) {}\n}\n";
        $ext  = new ExtensionData('FooExtension', 'App\\Ext', 'App\\Ext\\FooExtension', 'FooExtension.php', '');

        $result = $this->extGen->injectIntoClass($code, $ext);

        $this->assertSame(1, substr_count($result, '@mixin FooExtension'),
            '@mixin must appear exactly once');
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

        $this->assertStringContainsString('use App\\Database\\Extensions\\Models\\UserExtension;', $m['code']);
        $this->assertStringContainsString('@mixin UserExtension', $m['code']);
        $this->assertStringContainsString('use UserExtension;', $m['code']);
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
        $this->assertStringContainsString('@mixin OrderRowExtension', $r['code']);
        $this->assertStringContainsString('use OrderRowExtension;', $r['code']);
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
        // The embed extension's abstract properties must match the DTO's property names.
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
        $this->assertStringContainsString('abstract public string $email;',  $scaffold);
        $this->assertStringContainsString('abstract public int    $active;', $scaffold);
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

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_9_8(): void
    {
        $this->assertSame('2.9.8', \SqlcPhp\Version::VERSION);
    }
}
