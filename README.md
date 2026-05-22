# sqlc-php

A PHP code generator inspired by [sqlc](https://sqlc.dev) for Go. It reads your SQL schema and annotated query files, and generates fully-typed PHP 8.4 classes that use PDO under the hood — no ORM, no magic, just plain objects derived directly from your database.

---

## How it works

```
    schema.sql + queries.sql + sqlc.yaml
              ↓
         sqlc-php (CLI)
              ↓
   User.php · UserQuery.php · GetUserWithRoleRow.php
```

1. **Parse** — reads `CREATE TABLE` statements and builds a schema catalog.
2. **Analyze** — resolves every query's parameters and result columns against the catalog.
3. **Generate** — emits one `readonly` DTO per table (or per query when columns span multiple tables) and one query class per `@group` with PDO methods.

---

## Requirements

- PHP 8.3+
- PDO extension

---

## Installation

```bash
  composer require phpibe/sqlc-php
```

then run the CLI from your project root:

```bash
php ./vendor/bin/sqlc-php sqlc.yaml
```

---

## Configuration — `sqlc.yaml`

```yaml
version: "1"
schema:  schema.sql    # path to your CREATE TABLE file(s)
queries: queries.sql   # single file (scalar) or list of files

php:
  namespace: "App\\Database"   # PHP namespace for all generated classes
  out:       generated         # output directory
  engine:    mysql             # database engine (mysql supported)

# Optional type overrides
type_overrides:
  - column:   "users.active"         # target a specific table.column
    php_type: "bool"

  - db_type:  "TINYINT"              # target every column of this SQL type
    php_type: "bool"

  - db_type:  "TIMESTAMP"
    php_type: "\\DateTimeImmutable"
```

### Multiple query files

`queries` accepts both a scalar string (single file) and a YAML list of paths:

```yaml
queries:
  - database/queries/users.sql
  - database/queries/roles.sql
  - database/queries/orders.sql
```

All files are parsed and merged before analysis. The CLI prints a per-file count alongside the total:

```
Queries: 8 query(ies) from database/queries/users.sql
Queries: 3 query(ies) from database/queries/roles.sql
Queries: 11 total query(ies) parsed
```

### Type override precedence

| Priority | Rule | Description |
|---|---|---|
| 1 | `column` | Exact `table.column` match — wins over everything |
| 2 | `db_type` | Matches any column whose SQL type matches |
| 3 | Default | Built-in SQL → PHP type mapping |

---

## Annotating queries

Every query must have at minimum a `@name` and a `@returns` annotation, written as SQL comments:

```sql
-- @name    MethodName   required — generates the PHP method name (camelCase)
-- @group   ClassName    optional — groups methods into a class; inferred from the FROM table if omitted
-- @returns :many        required — :many | :one | :opt | :exec
-- @param   userId users.id        optional — explicit type override for a named parameter
-- @optional paramName             optional — passing null skips the filter condition entirely
```

### Return type semantics

| Annotation | PHP return type | Behaviour |
|---|---|---|
| `:many` | `ModelClass[]` | Returns an array; empty array if no rows |
| `:one` | `ModelClass` | Returns the object; **throws `RuntimeException`** if no row found |
| `:opt` | `ModelClass\|null` | Returns the object or `null` if no row found |
| `:exec` | `void` | Executes the statement (INSERT, UPDATE, DELETE) |

---

## Query examples

### SELECT * — returns the table model

```sql
-- @name ListUsers
-- @group User
-- @returns :many
SELECT users.* FROM users;
```

Generated method:
```php
/** @return User[] */
public function listUsers(): array
```

---

### SELECT * with WHERE — :one throws, :opt returns null

```sql
-- @name GetUser
-- @group User
-- @returns :one
SELECT users.* FROM users WHERE users.id = :id;

-- @name GetUserByEmail
-- @group User
-- @returns :opt
SELECT users.* FROM users WHERE users.email = :email;
```

Generated methods:
```php
/** @return User */
public function getUser(?int $id): User               // throws RuntimeException if missing

/** @return User|null */
public function getUserByEmail(string $email): ?User  // returns null if missing
```

---

### SELECT specific columns

When columns come from a single table, the return type is still the table model:

```sql
-- @name GetUserProfile
-- @group User
-- @returns :one
SELECT users.id, users.email, users.firstname, users.avatar
FROM users
WHERE users.id = :id;
```

```php
public function getUserProfile(?int $id): User
```

---

### JOIN — generates a result DTO

When columns come from multiple tables, a dedicated `*Row` DTO is generated:

```sql
-- @name GetUserWithRole
-- @group User
-- @returns :one
SELECT
    users.id,
    users.email,
    roles.name        AS role_name,
    roles.description AS role_description
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.id = :id;
```

Generated files:
- `GetUserWithRoleRow.php` — readonly DTO with `id`, `email`, `role_name`, `role_description`
- method in `UserQuery.php`:

```php
public function getUserWithRole(?int $id): GetUserWithRoleRow
```

---

### Aggregate and expression columns

sqlc-php infers types from SQL functions. Aliases are generated automatically when none is provided (mirroring sqlc/Go behaviour):

```sql
-- @name GetUserStats
-- @group User
-- @returns :one
SELECT
    COUNT(*)        AS total_users,
    SUM(active)     AS total_active,
    AVG(role_id)    AS avg_role,
    MAX(created_at) AS last_signup
FROM users;
```

Generated DTO:

```php
readonly class GetUserStatsRow
{
    public function __construct(
        public int                 $total_users,   // COUNT → int, never null
        public ?int                $total_active,  // SUM   → ?int (null on empty set)
        public ?float              $avg_role,      // AVG   → ?float
        public ?\DateTimeImmutable $last_signup,   // MAX   → nullable, type from column
    ) {}
}
```

#### Expression type inference table

| SQL expression | PHP type | Auto-alias (no AS) |
|---|---|---|
| `COUNT(*)` | `int` | `count` |
| `SUM(int_col)` | `?int` | `sumIntCol` |
| `SUM(decimal_col)` | `?float` | `sumDecimalCol` |
| `AVG(col)` | `?float` | `avgCol` |
| `MIN(col)` | `?{type of col}` | `minCol` |
| `MAX(col)` | `?{type of col}` | `maxCol` |
| `COALESCE(col, x)` | `{type of col}` (not nullable) | `coalesceCol` |
| `IFNULL(col, x)` | `{type of col}` (not nullable) | `ifnullCol` |
| `NULLIF(col, x)` | `?{type of col}` | `nullifCol` |
| `CONCAT(...)` | `?string` | `concat` |
| `CAST(x AS INT)` | `int` | `castX` |
| `UPPER/LOWER/TRIM(col)` | `string` | `upper` / `lower` / `trim` |
| `LENGTH(col)` | `int` | `length` |
| `CASE WHEN ...` | `?string` | `case` |
| Unknown expression | `mixed` | `col_1`, `col_2`… |

---

### UPDATE / DELETE — :exec

```sql
-- @name UpdateUserActive
-- @group User
-- @returns :exec
UPDATE users SET active = :active, updated_at = :updatedAt WHERE id = :id;

-- @name DeleteUser
-- @group User
-- @returns :exec
DELETE FROM users WHERE id = :id;
```

```php
public function updateUserActive(?bool $active, ?string $updatedAt, ?int $id): void
public function deleteUser(?int $id): void
```

---

### Optional parameters

Marking a parameter as `@optional` instructs sqlc-php to rewrite the SQL condition at generation time. When `null` is passed at runtime the filter is skipped entirely; when a value is passed it filters normally. No `if` statements or query builders required.

```sql
-- @name SearchUsers
-- @group User
-- @returns :many
-- @optional status
-- @optional username
SELECT users.* FROM users
WHERE users.status   = :status
  AND users.username = :username;
```

sqlc-php rewrites each optional condition before emitting any PHP:

```sql
-- rewritten SQL stored in the generated class
SELECT users.* FROM users
WHERE (:status   IS NULL OR users.status   = :status)
  AND (:username IS NULL OR users.username = :username)
```

Generated method:

```php
/**
 * @param ?string $status   Pass null to skip this filter.
 * @param ?string $username Pass null to skip this filter.
 * @return User[]
 */
public function searchUsers(?string $status = null, ?string $username = null): array
```

Calling the method:

```php
// All rows — both filters skipped
$repo->searchUsers();

// Filter by status only — username skipped
$repo->searchUsers(status: 'active');

// Filter by both
$repo->searchUsers(status: 'active', username: 'alice');
```

#### Mixing required and optional parameters

Required parameters always appear first in the signature; optional parameters follow with `= null`.

```sql
-- @name GetUsersByRole
-- @group User
-- @returns :many
-- @optional status
SELECT users.* FROM users
WHERE users.role_id = :roleId
  AND users.status  = :status;
```

```php
// roleId is required, status is optional
public function getUsersByRole(int $roleId, ?string $status = null): array
```

#### Supported operators

| Operator | Rewritten form |
|---|---|
| `=`    | `(:param IS NULL OR col = :param)` |
| `<>`   | `(:param IS NULL OR col <> :param)` |
| `!=`   | `(:param IS NULL OR col != :param)` |
| `>`    | `(:param IS NULL OR col > :param)` |
| `<`    | `(:param IS NULL OR col < :param)` |
| `>=`   | `(:param IS NULL OR col >= :param)` |
| `<=`   | `(:param IS NULL OR col <= :param)` |
| `LIKE` | `(:param IS NULL OR col LIKE :param)` |
| `ILIKE`| `(:param IS NULL OR col ILIKE :param)` |

#### Parameter name validation

If a name declared in `@optional` does not match any `:param` token in the SQL, generation stops immediately with a fatal error:

```
RuntimeException: Query 'SearchUsers': @optional 'stauts' does not match any
named parameter in the SQL. Known params: status, username
```

This catches typos at generation time rather than silently producing incorrect SQL at runtime.


#### Limitations

`@optional` rewrites SQL conditions using regex pattern matching. This approach is only safe when the rewriter can be certain it is only touching `WHERE` clauses. The following query shapes are **not supported** and will produce a fatal error at generation time:

**JOIN clauses** — a parameter in an `ON` condition is a join predicate, not a row filter. Rewriting it as `(:param IS NULL OR ...)` would turn a missing value into a cartesian product.

```sql
-- This will throw a RuntimeException at generation time:
-- @optional roleId
SELECT users.* FROM users
INNER JOIN roles ON roles.id = :roleId   -- unsafe: ON condition
WHERE users.active = 1;
```

**Subqueries** — the rewriter cannot distinguish between the outer `WHERE` and a nested `SELECT`'s `WHERE`, so it may rewrite the wrong condition.

```sql
-- This will throw a RuntimeException at generation time:
-- @optional status
SELECT * FROM users
WHERE id IN (SELECT user_id FROM orders WHERE status = :status);  -- unsafe: subquery
```

**HAVING clauses** — `HAVING` filters aggregated groups, not rows. Skipping a `HAVING` condition has different semantics than skipping a row filter, and the implicit behaviour would be surprising.

```sql
-- This will throw a RuntimeException at generation time:
-- @optional minCount
SELECT role_id, COUNT(*) FROM users
GROUP BY role_id
HAVING COUNT(*) > :minCount;  -- unsafe: HAVING
```

For these cases, handle the conditional logic in PHP directly:

```php
// Manual approach for queries with JOINs or subqueries
$sql = 'SELECT users.* FROM users
        INNER JOIN roles ON roles.id = users.role_id
        WHERE 1=1';

$params = [];
if ($status !== null) {
    $sql .= ' AND users.status = :status';
    $params[':status'] = $status;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
```


---

## Parameter type resolution

Named parameters (`:paramName`) are automatically typed by matching them to schema columns. Resolution order:

1. **`@param` annotation** — explicit override: `-- @param userId users.id`
2. **Qualified reference** — `WHERE table.col = :param`
3. **SET clause** — `SET col = :param`
4. **camelCase → snake_case** — `:updatedAt` → looks up `updated_at` in the schema
5. **Fallback** — `mixed` / `PDO::PARAM_STR`

---

## Generated file structure

```
generated/
├── User.php                      # readonly DTO for the `users` table
├── Role.php                      # readonly DTO for the `roles` table
├── GetUserWithRoleRow.php        # result DTO for a JOIN query
├── GetUserStatsRow.php           # result DTO for an aggregate query
└── UserQuery.php                 # all queries in the User group
```

### Model class example (`User.php`)

```php
readonly class User
{
    public function __construct(
        public ?int    $id,
        public string  $email,
        public ?string $username,
        public ?bool   $active,        // overridden via type_overrides
        public int     $role_id,
        public ?string $created_at,
    ) {}

    public static function fromRow(array $row): self { ... }
}
```

### Query class example (`UserQuery.php`)

```php
class UserQuery
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return User[] */
    public function listUsers(): array { ... }

    /** @return User */
    public function getUser(?int $id): User { ... }                     // :one — throws

    /** @return User|null */
    public function getUserByEmail(string $email): ?User { ... }        // :opt — nullable

    public function deleteUser(?int $id): void { ... }                  // :exec

    /** @return User[] */
    public function searchUsers(
        ?string $status   = null,   // @optional — pass null to skip filter
        ?string $username = null,   // @optional — pass null to skip filter
    ): array { ... }
}
```

---

## Usage in your application

```php
$pdo  = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
$repo = new UserQuery($pdo);

// :many — always an array
$users = $repo->listUsers();

// :one — throws RuntimeException if user not found
$user = $repo->getUser(42);

// :opt — returns null if not found
$user = $repo->getUserByEmail('alice@example.com');
if ($user === null) {
    // handle not found
}

// :exec — fire and forget
$repo->deleteUser(42);
$repo->updateUserActive(true, date('Y-m-d H:i:s'), 42);

// @optional — named arguments, skip filters by passing null
$all      = $repo->searchUsers();
$active   = $repo->searchUsers(status: 'active');
$filtered = $repo->searchUsers(status: 'active', username: 'alice');
```


---

## Usage with Laravel

The recommended pattern is to wrap the generated query class inside a repository class, bind it in a Service Provider, and inject it into controllers or services via the constructor.

### 1. Create a repository

The repository is a plain PHP class that wraps one or more generated query classes. It is the only place in your application that knows about sqlc-php:

```php
namespace App\Repositories;

use App\Database\User;
use App\Database\UserQuery;

class UserRepository
{
    public function __construct(private UserQuery $userQuery) {}

    public function getUser(int $id): User
    {
        return $this->userQuery->getUser($id);
    }

    public function getUserByEmail(string $email): ?User
    {
        return $this->userQuery->getUserByEmail($email);
    }

    /** @return User[] */
    public function searchUsers(?string $status = null, ?string $username = null): array
    {
        return $this->userQuery->searchUsers(
            status:   $status,
            username: $username,
        );
    }
}
```

### 2. Register the binding in a Service Provider

Use `app('db')->connection()->getPdo()` to obtain the current Laravel PDO connection and pass it to the generated query class:

```php
namespace App\Providers;

use App\Database\UserQuery;
use App\Repositories\UserRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepository::class, function ($app) {
            return new UserRepository(
                new UserQuery(
                    $app->make('db')->connection()->getPdo()
                )
            );
        });
    }
}
```

If your application uses multiple database connections, pass the connection name explicitly:

```php
$app->make('db')->connection('mysql_replica')->getPdo()
```

### 3. Inject the repository into a controller

```php
namespace App\Http\Controllers;

use App\Repositories\UserRepository;

class UserController extends Controller
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    public function show(int $id)
    {
        $user = $this->userRepository->getUser($id);
        return response()->json($user);
    }

    public function index(Request $request)
    {
        $users = $this->userRepository->searchUsers(
            status:   $request->query('status'),
            username: $request->query('username'),
        );

        return response()->json($users);
    }
}
```

### 4. Inject into a service or job

The same pattern works for any Laravel class resolved through the container:

```php
class SendWelcomeEmail implements ShouldQueue
{
    public function __construct(private readonly UserRepository $userRepository) {}

    public function handle(): void
    {
        $user = $this->userRepository->getUserByEmail($this->email);
        // ...
    }
}
```

---


---

## Running the tests

```bash
phpunit --configuration phpunit.xml
```

The test suite covers:

| Suite | File | What it tests |
|---|---|---|
| Schema Parser | `tests/Parser/SchemaParserTest.php` | CREATE TABLE parsing, column types, nullability, AUTO_INCREMENT, DEFAULT |
| Query Parser | `tests/Parser/QueryParserTest.php` | Annotation parsing, ReturnType variants, @param, group inference |
| Type Mapper | `tests/TypeMapper/MySQLTypeMapperTest.php` | Default mappings, nullability, PDO constants, column/db_type overrides |
| Config | `tests/Config/ConfigTest.php` | YAML parsing, defaults, multiple query files, type_overrides |
| Param Resolver | `tests/Resolver/ParamResolverTest.php` | WHERE/SET resolution, camelCase→snake, fallback |
| Expression Resolver | `tests/Resolver/ExpressionTypeResolverTest.php` | COUNT/SUM/AVG/MIN/MAX/COALESCE/CAST/CASE alias and type |
| Analyzer | `tests/Analyzer/QueryAnalyzerTest.php` | Full pipeline: model detection, JOIN DTOs, aggregates |
| SQL Rewriter | `tests/Rewriter/SqlRewriterTest.php` | Optional param rewriting, all operators, repeated params |
| Optional Params | `tests/Analyzer/OptionalParamTest.php` | Parser validation, analyzer marking, generator output |
| Generator | `tests/Generator/GeneratorTest.php` | Generated code structure, docblock indentation, PDO bindings |

---

## Project structure

```
sqlc-php/
├── bin/
│   └── sqlc-php                        # CLI entry point
├── src/
│   ├── Analyzer/
│   │   └── QueryAnalyzer.php           # Enriches parsed queries with resolved types
│   ├── Catalog/
│   │   └── SchemaCatalog.php           # In-memory table/column index
│   ├── Config/
│   │   ├── Config.php                  # YAML config loader
│   │   └── TypeOverride.php            # Type override value object
│   ├── Generator/
│   │   ├── ModelGenerator.php          # Generates table DTO classes
│   │   ├── QueryGenerator.php          # Generates query classes with PDO methods
│   │   └── ResultDtoGenerator.php      # Generates result DTOs for JOIN/aggregate queries
│   ├── Parser/
│   │   ├── SchemaParser.php            # Parses CREATE TABLE SQL
│   │   └── QueryParser.php             # Parses annotated SQL query files
│   ├── Resolver/
│   │   ├── ColumnResolver.php          # Resolves SELECT columns to typed ResolvedColumn objects
│   │   ├── ExpressionTypeResolver.php  # Infers types of SQL functions and expressions
│   │   ├── ParamResolver.php           # Infers types of named :parameters
│   │   ├── QueryParam.php              # Value object for a resolved parameter
│   │   └── ResolvedColumn.php          # Value object for a resolved output column
│   ├── Rewriter/
│   │   └── SqlRewriter.php             # Rewrites optional param conditions in SQL
│   └── TypeMapper/
│       └── MySQLTypeMapper.php         # Maps SQL types to PHP types and PDO constants
├── tests/                              # PHPUnit test suite (191 tests)
├── schema.sql                          # Example schema
├── queries.sql                         # Example queries
├── sqlc.yaml                           # Example configuration
└── phpunit.xml                         # Test configuration
```

---

## Changelog

### [1.1.0]
- **Optional query parameters** — parameters can be marked with `@optional` in the query annotations. The SQL condition is rewritten at generation time so that passing `null` skips the filter entirely, without any PHP-side conditionals.
- **`SqlRewriter`** — new class responsible for rewriting `col OP :param` into `(:param IS NULL OR col OP :param)` for every occurrence of the parameter in the SQL.
- **Supported operators** — `=`, `<>`, `!=`, `>`, `<`, `>=`, `<=`, `LIKE`, `ILIKE`.
- **Parameter validation** — if a name declared in `@optional` does not match any named parameter in the SQL, generation stops with a fatal error, catching typos at generation time rather than at runtime.
- **Method signature** — required parameters keep their position; optional parameters are moved to the end of the signature and receive a `= null` default. Types are forced nullable (`?string`, `?int`, …).
- **Docblock annotation** — optional parameters include a `Pass null to skip this filter.` note in the generated `@param` line.
- **All occurrences rewritten** — if the same parameter appears more than once in the SQL (e.g. `WHERE a = :val AND b = :val`), every occurrence is rewritten.
- **34 new tests** across `SqlRewriterTest` and `OptionalParamTest` covering the rewriter, the parser validation, the analyzer marking, and the generator output.

### [1.0.3]
- **Multiple query files** — `queries` in `sqlc.yaml` now accepts either a scalar string (legacy) or a YAML list of paths. All files are merged before analysis.
  ```yaml
  queries:
    - database/queries/users.sql
    - database/queries/roles.sql
  ```
- **Backward compatible** — single-file scalar format still works unchanged.
- **CLI output** — now prints a per-file query count alongside the total.
- **PHPUnit 9 / 10 / 13 compatibility** — removed `@dataProvider` docblock annotations in favour of explicit individual test methods, compatible with all PHPUnit versions without attributes or docblocks.
- **Absolute test paths** — `dirname(__DIR__, 2)` replaces relative paths so tests pass regardless of the working directory when invoking `phpunit`.

### [1.0.1]
- **Expression type inference** — `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`, `COALESCE`, `IFNULL`, `NULLIF`, `CAST`, `CONCAT`, `CASE WHEN` and common scalar functions are resolved to typed PHP properties in result DTOs.
- **Auto-generated aliases** — when no `AS alias` is provided, a name is inferred from the function (`count`, `sumPrice`, `avgRoleId`, …). Unknown expressions fall back to positional names `col_1`, `col_2`, …
- **`SUM` / `AVG` / `MIN` / `MAX` are nullable** — these return `NULL` on an empty result set, reflected as `?int`, `?float`, etc.
- **`:opt` return type** — new annotation alongside `:one`. `:one` now throws `RuntimeException` when no row is found; `:opt` returns `null`.
- **Type overrides** — `type_overrides` block in `sqlc.yaml` lets you remap any column or DB type to an arbitrary PHP type (`bool`, `\DateTimeImmutable`, …). Column-specific overrides take precedence over DB-type overrides.
- **Unit test suite** — 191 tests across 10 suites covering parser, resolver, analyzer, generator, config, type mapper, rewriter, and optional parameters.

### [1.0.0]
- **Initial release.**
- **Schema parser** — `CREATE TABLE` statements parsed into a typed column catalog using balanced-parenthesis scanning, handling `DEFAULT 'value'` and nested types correctly.
- **Query parser** — `@name`, `@group`, `@returns`, `@param` annotations; group inferred from `FROM` / `UPDATE` table when `@group` is omitted.
- **Parameter resolver** — named parameters (`:param`) typed against the schema via qualified references, `SET` clause matching, and camelCase → snake_case fallback.
- **Column resolver** — `SELECT *`, `table.*`, specific columns, `AS` aliases, and multi-table `JOIN` all resolved to typed `ResolvedColumn` objects.
- **`:many`** → `Model[]`, **`:one`** → `Model` (throws), **`:opt`** → `?Model`, **`:exec`** → `void`.
- **Result DTOs** — `*Row` classes generated automatically for queries whose columns span multiple tables or use aggregate expressions.
- **`readonly class` DTOs** — PHP 8.4 implicit readonly on all constructor-promoted properties; no redundant `readonly` keyword on individual properties.
- **PDO bindings** — `bindValue` calls use `PDO::PARAM_INT`, `PDO::PARAM_STR`, or `PDO::PARAM_BOOL` inferred from the schema column type.
