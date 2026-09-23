<?php
declare(strict_types=1);
namespace SqlcPhp\Tests;
use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Parser\{QueryParser,SchemaParser};
use SqlcPhp\Resolver\{ColumnResolver,ExpressionTypeResolver,ParamResolver};
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class DistinctColumnTest extends TestCase
{
    private QueryAnalyzer $analyzer;
    private QueryParser   $parser;

    protected function setUp(): void
    {
        $schema = "
            CREATE TABLE profiles (id INT AUTO_INCREMENT PRIMARY KEY, firstname VARCHAR(100) NOT NULL);
            CREATE TABLE accounts (id INT AUTO_INCREMENT PRIMARY KEY, profile_id INT NOT NULL);
        ";
        $catalog = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper  = new MySQLTypeMapper();
        $this->parser = new QueryParser('english');
        $pr = new ParamResolver($catalog, $mapper);
        $er = new ExpressionTypeResolver($catalog, $mapper);
        $cr = new ColumnResolver($catalog, $mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $catalog);
    }

    private function firstCol(string $sql): \SqlcPhp\Resolver\ResolvedColumn
    {
        $q = $this->analyzer->analyze($this->parser->parse("-- @name T\n-- @class P\n-- @returns :many\n$sql"));
        return $q[0]->resultColumns[0];
    }

    public function test_distinct_bare_col(): void
    {
        $col = $this->firstCol('SELECT DISTINCT id FROM profiles;');
        $this->assertSame('id', $col->alias);
        $this->assertNotSame('col_1', $col->alias);
    }

    public function test_distinct_qualified_col(): void
    {
        $col = $this->firstCol('SELECT DISTINCT profiles.id FROM profiles;');
        $this->assertSame('id', $col->alias);
        $this->assertNotSame('col_1', $col->alias);
    }

    public function test_distinct_multi_col(): void
    {
        $q = $this->analyzer->analyze($this->parser->parse(
            "-- @name T\n-- @class P\n-- @returns :many\nSELECT DISTINCT id, firstname FROM profiles;"
        ));
        $aliases = array_map(fn($c) => $c->alias, $q[0]->resultColumns);
        $this->assertSame(['id', 'firstname'], $aliases);
        $this->assertNotContains('col_1', $aliases);
    }

    public function test_non_distinct_unaffected(): void
    {
        $col = $this->firstCol('SELECT id FROM profiles;');
        $this->assertSame('id', $col->alias);
        $this->assertSame('int', $col->phpType);
    }

    public function test_distinct_with_join(): void
    {
        $q = $this->analyzer->analyze($this->parser->parse(
            "-- @name T\n-- @class P\n-- @returns :many\n" .
            "SELECT DISTINCT profiles.id FROM profiles INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        ));
        $this->assertSame('id', $q[0]->resultColumns[0]->alias);
    }
}
