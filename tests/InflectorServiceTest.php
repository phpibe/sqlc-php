<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Config\Config;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Inflector\InflectorService;
use SqlcPhp\Parser\QueryParser;

class InflectorServiceTest extends TestCase
{
    // =========================================================================
    // InflectorService — construction and fallback
    // =========================================================================

    public function test_instantiates_without_arguments(): void
    {
        $svc = new InflectorService();
        $this->assertInstanceOf(InflectorService::class, $svc);
    }

    public function test_defaults_to_english(): void
    {
        $svc = new InflectorService();
        $this->assertSame('user', $svc->singularize('users'));
    }

    // =========================================================================
    // English singularisation — doctrine handles these correctly
    // =========================================================================

    public function test_english_regular_plural(): void
    {
        $svc = new InflectorService('english');
        $this->assertSame('user',    $svc->singularize('users'));
        $this->assertSame('order',   $svc->singularize('orders'));
        $this->assertSame('product', $svc->singularize('products'));
        $this->assertSame('role',    $svc->singularize('roles'));
    }

    public function test_english_ies_plural(): void
    {
        $svc = new InflectorService('english');
        $this->assertSame('category',  $svc->singularize('categories'));
        $this->assertSame('country',   $svc->singularize('countries'));
        $this->assertSame('ability',   $svc->singularize('abilities'));
    }

    public function test_english_irregular_analyses(): void
    {
        $svc = new InflectorService('english');
        $this->assertSame('analysis', $svc->singularize('analyses'));
    }

    public function test_english_irregular_matrices(): void
    {
        $svc = new InflectorService('english');
        $this->assertSame('matrix', $svc->singularize('matrices'));
    }

    public function test_english_irregular_aliases(): void
    {
        $svc = new InflectorService('english');
        $this->assertSame('alias', $svc->singularize('aliases'));
    }

    public function test_english_already_singular_unchanged(): void
    {
        $svc = new InflectorService('english');
        $this->assertSame('user',    $svc->singularize('user'));
        $this->assertSame('product', $svc->singularize('product'));
        $this->assertSame('status',  $svc->singularize('status'));
    }

    // =========================================================================
    // Spanish singularisation
    // =========================================================================

    public function test_spanish_regular_plural(): void
    {
        $svc = new InflectorService('spanish');
        $this->assertSame('usuario',  $svc->singularize('usuarios'));
        $this->assertSame('pedido',   $svc->singularize('pedidos'));
        $this->assertSame('producto', $svc->singularize('productos'));
        $this->assertSame('rol',      $svc->singularize('roles'));
    }

    public function test_spanish_iones_plural(): void
    {
        $svc = new InflectorService('spanish');
        $result = $svc->singularize('canciones');
        // doctrine Spanish rules: canciones → canción (or cancion)
        $this->assertNotSame('canciones', $result, 'Should be singularised');
    }

    // =========================================================================
    // Portuguese singularisation
    // =========================================================================

    public function test_portuguese_regular_plural(): void
    {
        $svc = new InflectorService('portuguese');
        $this->assertSame('usuario',  $svc->singularize('usuarios'));
        $this->assertSame('produto',  $svc->singularize('produtos'));
    }

    // =========================================================================
    // French singularisation
    // =========================================================================

    public function test_french_regular_plural(): void
    {
        $svc = new InflectorService('french');
        $this->assertSame('utilisateur', $svc->singularize('utilisateurs'));
        $this->assertSame('produit',     $svc->singularize('produits'));
    }

    // =========================================================================
    // Language aliases and normalization
    // =========================================================================

    public function test_norwegian_bokmal_underscore_alias(): void
    {
        // Both "norwegian-bokmal" and "norwegian_bokmal" should work
        $svc1 = new InflectorService('norwegian-bokmal');
        $svc2 = new InflectorService('norwegian_bokmal');

        $this->assertSame(
            $svc1->singularize('brukere'),
            $svc2->singularize('brukere'),
        );
    }

    public function test_unknown_language_falls_back_to_english(): void
    {
        // Unknown language → English fallback (no exception thrown)
        $svc = new InflectorService('klingon');
        $this->assertSame('user', $svc->singularize('users'));
    }

    // =========================================================================
    // toPascalCase
    // =========================================================================

    public function test_pascal_case_simple(): void
    {
        $svc = new InflectorService();
        $this->assertSame('User',    $svc->toPascalCase('user'));
        $this->assertSame('Order',   $svc->toPascalCase('order'));
        $this->assertSame('Product', $svc->toPascalCase('product'));
    }

    public function test_pascal_case_with_underscores(): void
    {
        $svc = new InflectorService();
        $this->assertSame('UserRole',    $svc->toPascalCase('user_role'));
        $this->assertSame('OrderStatus', $svc->toPascalCase('order_status'));
    }

    public function test_pascal_case_already_upper(): void
    {
        $svc = new InflectorService();
        $this->assertSame('User', $svc->toPascalCase('USER'));
    }

    // =========================================================================
    // Config — language field
    // =========================================================================

    public function test_config_defaults_to_english(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sqlc-lang-' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/sqlc.yaml',
            "version: \"1\"\nschema: s.sql\nqueries: q.sql\n"
        );

        $config = Config::fromFile($tmpDir . '/sqlc.yaml');
        $this->assertSame('english', $config->language);

        array_map('unlink', glob($tmpDir . '/*') ?: []);
        rmdir($tmpDir);
    }

    public function test_config_parses_language_field(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sqlc-lang-' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/sqlc.yaml',
            "version: \"1\"\nschema: s.sql\nqueries: q.sql\nphp:\n  language: spanish\n"
        );

        $config = Config::fromFile($tmpDir . '/sqlc.yaml');
        $this->assertSame('spanish', $config->language);

        array_map('unlink', glob($tmpDir . '/*') ?: []);
        rmdir($tmpDir);
    }

    public function test_config_language_is_lowercased(): void
    {
        $tmpDir = sys_get_temp_dir() . '/sqlc-lang-' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/sqlc.yaml',
            "version: \"1\"\nschema: s.sql\nqueries: q.sql\nphp:\n  language: Spanish\n"
        );

        $config = Config::fromFile($tmpDir . '/sqlc.yaml');
        $this->assertSame('spanish', $config->language);

        array_map('unlink', glob($tmpDir . '/*') ?: []);
        rmdir($tmpDir);
    }

    // =========================================================================
    // QueryParser — language propagation
    // =========================================================================

    public function test_query_parser_uses_language_for_group_inference(): void
    {
        $parser  = new QueryParser('spanish');
        $queries = $parser->parse(
            "-- @name ListarUsuarios\n-- @returns :many\nSELECT * FROM usuarios;"
        );

        // "usuarios" → singularize(spanish) → "usuario" → toPascalCase → "Usuario"
        $this->assertSame('Usuario', $queries[0]->group);
    }

    public function test_query_parser_english_analyses_group(): void
    {
        $parser  = new QueryParser('english');
        $queries = $parser->parse(
            "-- @name ListAnalyses\n-- @returns :many\nSELECT * FROM analyses;"
        );

        // "analyses" → singularize(english) → "analysis" → toPascalCase → "Analysis"
        $this->assertSame('Analysis', $queries[0]->group);
    }

    public function test_query_parser_default_english_when_no_language(): void
    {
        $parser  = new QueryParser();
        $queries = $parser->parse(
            "-- @name List\n-- @returns :many\nSELECT * FROM users;"
        );

        $this->assertSame('User', $queries[0]->group);
    }

    // =========================================================================
    // EnumGenerator — language propagation
    // =========================================================================

    public function test_enum_generator_uses_language_for_class_name(): void
    {
        $gen   = new EnumGenerator('App\\Db', 'spanish');
        $name  = $gen->enumClassName('pedidos', 'estado');
        // "pedidos" → singular(spanish) → "pedido" → Pascal → "Pedido"
        // "estado" → Pascal → "Estado"
        $this->assertSame('PedidoEstado', $name);
    }

    public function test_enum_generator_english_default(): void
    {
        $gen  = new EnumGenerator('App\\Db');
        $name = $gen->enumClassName('orders', 'status');
        $this->assertSame('OrderStatus', $name);
    }

    public function test_enum_generator_english_analyses_table(): void
    {
        $gen  = new EnumGenerator('App\\Db', 'english');
        $name = $gen->enumClassName('analyses', 'type');
        // "analyses" → "analysis" → "Analysis"
        $this->assertSame('AnalysisType', $name);
    }
}
