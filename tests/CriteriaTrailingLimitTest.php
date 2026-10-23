<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * A plain :many + @with criteria query that ends in a hand-written
 * LIMIT :limit [OFFSET :offset] must keep that LIMIT in the generated SQL.
 * Before the fix the LIMIT was stripped while :limit/:offset were still bound,
 * so PDO failed with SQLSTATE[HY093] Invalid parameter number.
 */
class CriteriaTrailingLimitTest extends TestCase
{
    private QueryAnalyzer $analyzer;
    private QueryParser $parser;
    private QueryGenerator $gen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE refunds_subcategories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                parent_id INT NULL,
                name VARCHAR(100) NOT NULL,
                deleted_at DATETIME NULL
            );
            CREATE TABLE refunds_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            );
        SQL;
        $catalog = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper  = new MySQLTypeMapper();
        $this->parser = new QueryParser('english');
        $pr = new ParamResolver($catalog, $mapper);
        $er = new ExpressionTypeResolver($catalog, $mapper);
        $cr = new ColumnResolver($catalog, $mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $catalog);
        $this->gen = new QueryGenerator(
            $catalog, $mapper, new ResultDtoGenerator('App\\DTOs', $mapper, $catalog),
            'App\\Q', criteriasNamespace: 'App\\Criterias'
        );
    }

    private function code(string $sql): string
    {
        foreach ($this->gen->generate($this->analyzer->analyze($this->parser->parse($sql))) as $key => $f) {
            if (str_ends_with($key, 'Query')) return $f['code'];
        }
        return '';
    }

    private function sql(string $annotations, string $select): string
    {
        return "-- @name ListSubs\n-- @class Subs\n-- @with criteria\n{$annotations}-- @returns :many\n{$select}";
    }

    private const WITH_LIMIT_OFFSET =
        "SELECT refunds_subcategories.*, refunds_categories.name AS category_name\n" .
        "FROM refunds_subcategories\n" .
        "LEFT JOIN refunds_categories ON refunds_categories.id = refunds_subcategories.parent_id\n" .
        "WHERE refunds_subcategories.deleted_at IS NULL\n" .
        "ORDER BY refunds_subcategories.name\n" .
        "LIMIT :limit OFFSET :offset;";

    public function test_limit_and_offset_are_kept_in_generated_sql(): void
    {
        $code = $this->code($this->sql("-- @param limit int\n-- @param offset int\n", self::WITH_LIMIT_OFFSET));
        $this->assertStringContainsString("\$__sql .= ' LIMIT :limit OFFSET :offset';", $code);
        $this->assertStringContainsString("bindValue(':limit'", $code);
        $this->assertStringContainsString("bindValue(':offset'", $code);
    }

    public function test_limit_is_appended_after_the_order_by_block(): void
    {
        $code = $this->code($this->sql("-- @param limit int\n-- @param offset int\n", self::WITH_LIMIT_OFFSET));
        $orderPos = strpos($code, "ORDER BY refunds_subcategories.name");
        $limitPos = strpos($code, "' LIMIT :limit OFFSET :offset'");
        $this->assertNotFalse($orderPos);
        $this->assertNotFalse($limitPos);
        $this->assertGreaterThan($orderPos, $limitPos);
    }

    public function test_limit_only_is_not_trapped_inside_the_static_order_branch(): void
    {
        // Before: ' ORDER BY ... LIMIT :limit' lived in the else-branch, so a Criteria
        // with its own orderBy dropped the LIMIT while :limit was still bound.
        $code = $this->code($this->sql(
            "-- @param limit int\n",
            "SELECT refunds_subcategories.id FROM refunds_subcategories ORDER BY refunds_subcategories.id DESC LIMIT :limit;"
        ));
        $this->assertStringNotContainsString("ORDER BY refunds_subcategories.id DESC LIMIT :limit", $code);
        $this->assertStringContainsString("\$__sql .= ' LIMIT :limit';", $code);
    }

    public function test_literal_limit_is_preserved_without_bindings(): void
    {
        $code = $this->code($this->sql(
            "",
            "SELECT refunds_subcategories.id FROM refunds_subcategories ORDER BY refunds_subcategories.id LIMIT 10;"
        ));
        $this->assertStringContainsString("\$__sql .= ' LIMIT 10';", $code);
        $this->assertStringNotContainsString("bindValue(':limit'", $code);
    }

    public function test_query_without_limit_is_unchanged(): void
    {
        $code = $this->code($this->sql(
            "",
            "SELECT refunds_subcategories.id FROM refunds_subcategories ORDER BY refunds_subcategories.id;"
        ));
        $this->assertStringNotContainsString('LIMIT', $code);
    }

    public function test_companion_count_method_still_has_no_limit(): void
    {
        $sql  = "-- @name ListSubs\n-- @class Subs\n-- @with criteria,count\n-- @param limit int\n-- @param offset int\n-- @returns :many\n" . self::WITH_LIMIT_OFFSET;
        $code = $this->code($sql);
        $countPos = strpos($code, 'function listSubsCount(');
        $this->assertNotFalse($countPos);
        $countCode = substr($code, $countPos);
        $this->assertStringNotContainsString("LIMIT :limit", $countCode);
    }

    /**
     * Runs the generated SQL-building block with a stub Criteria and checks that every
     * placeholder bound later actually exists in the final SQL (what PDO validates).
     */
    public function test_generated_sql_contains_every_bound_placeholder_at_runtime(): void
    {
        $code = $this->code($this->sql("-- @param limit int\n-- @param offset int\n", self::WITH_LIMIT_OFFSET));
        $this->assertSame(1, preg_match('/(\$__sql = .*?)(?=\s*\$stmt = \$this->pdo->prepare)/s', $code, $m));
        $block = $m[1];

        foreach ([false, true] as $criteriaHasOrder) {
            $criteria = new class($criteriaHasOrder) {
                public function __construct(private bool $order) {}
                public function hasFilters(): bool { return false; }
                public function toFilterClause(bool &$hasWhere): string { return ''; }
                public function hasOrderBy(): bool { return $this->order; }
                public function toOrderClause(): string { return ' ORDER BY refunds_subcategories.id DESC'; }
            };
            $__sql = '';
            eval($block);

            $this->assertStringContainsString(':limit', $__sql, 'criteria order=' . var_export($criteriaHasOrder, true));
            $this->assertStringContainsString(':offset', $__sql);
            $this->assertStringEndsWith('LIMIT :limit OFFSET :offset', $__sql);
        }
    }
}
