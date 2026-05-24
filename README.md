# sqlc-php

A PHP code generator inspired by [sqlc](https://sqlc.dev) for Go. It reads your SQL schema and annotated query files, and generates fully-typed PHP 8.4 classes that use PDO under the hood — no ORM, no magic, just plain objects derived directly from your database.

---

## How it works

```
schema.sql + queries.sql + sqlc.yaml
              ↓
         sqlc-php (CLI)
              ↓
   User.php · UserQuery.php · UserQueryInterface.php · OrderStatus.php
```

1. **Parse** — reads `CREATE TABLE` statements and builds a schema catalog.
2. **Analyze** — resolves every query's parameters and result columns against the catalog.
3. **Generate** — emits one `readonly` DTO per table, PHP backed enums for `ENUM` columns, one query class per `@group`, and optionally a matching interface per query class.

---

## Requirements

- PHP 8.3+
- PDO extension

---

## Installation

```bash
composer require phpibe/sqlc-php
```

Then run the CLI from your project root:

```bash
php ./vendor/bin/sqlc-php sqlc.yaml            # generate files
php ./vendor/bin/sqlc-php --dry-run sqlc.yaml  # preview without writing
php ./vendor/bin/sqlc-php --diff    sqlc.yaml  # show what would change
php ./vendor/bin/sqlc-php --verify  sqlc.yaml  # CI check — exit 1 if stale
```

---

## Configuration — `sqlc.yaml`

```yaml
version: "1"
schema:  schema.sql    # single file (scalar) or list of files
queries: queries.sql   # single file (scalar) or list of files

php:
  namespace:           "App\\Database"  # PHP namespace for all generated classes
  out:                 generated        # output directory
  engine:              mysql            # database engine (mysql supported)
  generate_interfaces: true             # generate *Interface alongside each Query class

# Optional type overrides
type_overrides:
  - column:   "users.active"        # target a specific table.column
    php_type: "bool"

  - db_type:  "TINYINT"             # target every column of this SQL type
    php_type: "bool"

  - db_type:  "TIMESTAMP"
    php_type: "\\DateTimeImmutable"
    nullable: true                    # force nullable regardless of schema
```

### Multiple output targets

`targets` lets you generate multiple namespaces and output directories from the same schema in a single run. Each target has its own `namespace`, `out`, and `queries` list, and can declare its own `generate_interfaces` flag and `type_overrides` that are merged on top of the root-level overrides.

```yaml
version: "1"
schema:
  - database/schema/users.sql
  - database/schema/orders.sql

# Global type overrides shared across all targets
type_overrides:
  - db_type: "TIMESTAMP"
    php_type: "\\DateTimeImmutable"
    nullable: true

targets:
  - namespace: "App\\Database\\Read"
    out:       generated/read
    queries:
      - database/queries/read/users.sql
      - database/queries/read/orders.sql
    generate_interfaces: true

  - namespace: "App\\Database\\Write"
    out:       generated/write
    queries:
      - database/queries/write/users.sql
    generate_interfaces: false
    type_overrides:
      - column: "users.active"
        php_type: "bool"
```

When `targets` is present, the root-level `php:` block is used as fallback defaults but each target's settings take precedence. When `targets` is absent, the root `php:` block is used as the single target.

### Multiple schema files

`schema` accepts both a scalar string (single file) and a YAML list of paths. All files are parsed and merged into a single catalog before analysis:

```yaml
schema:
  - database/schema/users.sql
  - database/schema/orders.sql
  - database/schema/roles.sql
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
Schema : database/schema/users.sql
Schema : database/schema/orders.sql
Schema : 3 table(s) — users, orders, roles
Queries: 8 query(ies) from database/queries/users.sql
Queries: 3 query(ies) from database/queries/orders.sql
Queries: 11 total query(ies) parsed
```

### Type override precedence

| Priority | Rule | Description |
|---|---|---|
| 1 | `column` | Exact `table.column` match — wins over everything |
| 2 | `db_type` | Matches any column whose SQL type matches |
| 3 | Default | Built-in SQL → PHP type mapping |

### Nullable override

Any `type_override` entry accepts an optional `nullable` field that forces the nullability of the property regardless of how the column is declared in the schema:

```yaml
type_overrides:
  # Force nullable even though the column is NOT NULL in the schema
  - column:   "users.deleted_at"
    php_type: "\\Carbon\\Carbon"
    nullable: true

  # Force NOT nullable for all TIMESTAMP columns despite schema nullability
  - db_type:  "TIMESTAMP"
    php_type: "\\DateTimeImmutable"
    nullable: false

  # Only change nullability, keep the default type mapping
  - column:   "users.created_at"
    nullable: false
```

When `nullable` is omitted, the nullability is inherited from the schema column as usual.

### Default SQL → PHP type mapping

| SQL type | PHP type | Notes |
|---|---|---|
| `INT`, `BIGINT`, `SMALLINT`, `TINYINT` | `int` | |
| `DECIMAL`, `FLOAT`, `DOUBLE` | `float` | |
| `VARCHAR`, `CHAR`, `TEXT` | `string` | |
| `DATE`, `DATETIME`, `TIMESTAMP` | `string` | override with `\DateTimeImmutable` via `type_overrides` |
| `JSON` | `array` | hydrated via `json_decode` in `fromRow` |
| `ENUM(...)` | `EnumClass` | generates a PHP 8.1 backed enum file |
| `BOOLEAN` | `bool` | |

---

## Annotating queries

Every query must have at minimum a `@name` and a `@returns` annotation, written as SQL comments:

```sql
-- @name    MethodName          required — PHP method name (camelCase)
-- @group   ClassName           optional — query class name; inferred from FROM table if omitted
-- @returns :many               required — :many | :one | :opt | :exec
-- @param   userId users.id     optional — explicit type override for a named parameter
-- @optional paramName          optional — passing null skips the filter condition entirely
-- @deprecated reason           optional — marks the generated method as @deprecated
-- @nillable columnAlias        optional — forces a result column to be nullable in the DTO
```

### Return type semantics

| Annotation | PHP return type | Behaviour |
|---|---|---|
| `:many` | `ModelClass[]` | Returns an array; empty array if no rows |
| `:many-paginated` | `ModelClass[]` | Like `:many` but auto-injects `LIMIT`/`OFFSET` params |
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
- Method in `UserQuery.php`:

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

### `:many-paginated` — automatic pagination

Using `:many-paginated` instructs sqlc-php to automatically append `LIMIT :limit OFFSET :offset` to the SQL and add those two parameters to the generated method with sensible defaults.

```sql
-- @name ListUsers
-- @group User
-- @returns :many-paginated
SELECT users.* FROM users ORDER BY created_at DESC;
```

Generated method:

```php
/**
 * @param int $limit  Maximum number of rows to return.
 * @param int $offset Number of rows to skip.
 * @return User[]
 */
public function listUsers(int $limit = 20, int $offset = 0): array
```

The SQL stored in the class becomes:

```sql
SELECT users.* FROM users ORDER BY created_at DESC
LIMIT :limit OFFSET :offset
```

Any user-defined parameters appear first in the signature; `$limit` and `$offset` are always last:

```sql
-- @name ListActiveUsers
-- @returns :many-paginated
-- @optional status
SELECT users.* FROM users WHERE users.status = :status;
```

```php
public function listActiveUsers(?string $status = null, int $limit = 20, int $offset = 0): array
```

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

### MySQL ENUM → PHP backed enum

When a column is defined as `ENUM(...)`, sqlc-php generates a PHP 8.1 backed enum file and uses it as the property type in the DTO. The `fromRow` method uses `::from()` or `::tryFrom()` depending on nullability.

```sql
CREATE TABLE orders (
    id     INT AUTO_INCREMENT PRIMARY KEY,
    status ENUM('pending', 'processing', 'completed', 'cancelled') NOT NULL
);
```

Generated enum:

```php
// OrderStatus.php — generated by sqlc-php
enum OrderStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';
}
```

Generated DTO property and cast:

```php
// in Order.php
public OrderStatus $status,

// in fromRow()
OrderStatus::from((string) $row['status']),
```

Nullable ENUM columns use `::tryFrom()`:

```php
public ?OrderStatus $status,

// in fromRow()
isset($row['status']) ? OrderStatus::tryFrom((string) $row['status']) : null,
```

Enum naming convention: `{SingularTable}{PascalColumn}` — e.g. `orders.status` → `OrderStatus`, `users.role` → `UserRole`. Hyphenated values are converted to PascalCase: `in-progress` → `case InProgress = 'in-progress'`.

---

### JSON column → typed array

`JSON` columns map to `array` in PHP and are automatically hydrated via `json_decode` in the generated `fromRow`:

```sql
CREATE TABLE orders (
    metadata JSON null
);
```

```php
// in Order.php
public ?array $metadata,

// in fromRow()
isset($row['metadata']) ? json_decode((string) $row['metadata'], true) : null,
```

For `NOT NULL` JSON columns, the fallback is `?? []` to guarantee a non-null array is always returned.

---

### @deprecated — mark a method as deprecated

Adding `@deprecated` to a query causes the generated method to include a `@deprecated` PHPDoc tag. This is useful when migrating queries without breaking existing code.

```sql
-- @name GetUser
-- @group User
-- @returns :one
-- @deprecated Use getUserById instead
SELECT users.* FROM users WHERE users.id = :id;
```

Generated method:

```php
/**
 * @deprecated Use getUserById instead
 * @param ?int $id
 * @return User
 */
public function getUser(?int $id): User
```

The reason is optional — `-- @deprecated` without a message emits `@deprecated` alone.

---

### @nillable — force a result column to be nullable

`@nillable columnAlias` forces a specific column in the result set to be `?type` in the generated DTO or return type, regardless of how the column is declared in the schema.

This is useful in two scenarios:

**LEFT JOIN — column may be NULL at runtime even though NOT NULL in schema:**

```sql
-- @name GetUserWithOptionalRole
-- @group User
-- @returns :one
-- @nillable role_name
-- @nillable role_description
SELECT
    users.id,
    users.email,
    roles.name        AS role_name,
    roles.description AS role_description
FROM users
LEFT JOIN roles ON roles.id = users.role_id
WHERE users.id = :id;
```

Generated DTO (multi-table → custom DTO):

```php
readonly class GetUserWithOptionalRoleRow
{
    public function __construct(
        public ?int    $id,
        public string  $email,
        public ?string $role_name,         // forced nullable via @nillable
        public ?string $role_description,  // forced nullable via @nillable
    ) {}
}
```

**Direct model queries (`SELECT *`) — forces a dedicated DTO instead of reusing the table model:**

When `@nillable` is used on a query that would normally return the table model directly (single-table `SELECT *`), sqlc-php generates a dedicated `*Row` DTO so the nullability can be applied without mutating the base model class:

```sql
-- @name GetUserProfile
-- @group User
-- @returns :one
-- @nillable email
SELECT users.* FROM users WHERE users.id = :id;
```

This generates `GetUserProfileRow` with `public ?string $email` instead of reusing `User` where `email` is `NOT NULL`.

Multiple `@nillable` annotations can be stacked. The annotation targets the output alias (the name after `AS`), or the column name when no alias is used.

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

#### Limitations

`@optional` is only safe on queries with a plain `WHERE` clause over a single table. The following shapes produce a fatal error at generation time:

- **JOIN clauses** — params in `ON` conditions would be rewritten incorrectly.
- **Subqueries** — the rewriter cannot distinguish inner from outer `WHERE`.
- **HAVING** — semantically different from a row filter.

For these cases, use PHP-side conditional query building instead.

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
├── OrderStatus.php               # backed enum for orders.status ENUM column
├── User.php                      # readonly DTO for the `users` table
├── Order.php                     # readonly DTO for the `orders` table
├── GetUserWithRoleRow.php        # result DTO for a JOIN query
├── GetUserStatsRow.php           # result DTO for an aggregate query
├── UserQuery.php                 # query class for the User group
└── UserQueryInterface.php        # interface for UserQuery (when generate_interfaces: true)
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

When `generate_interfaces: true`, the class declares `implements UserQueryInterface`:

```php
class UserQuery implements UserQueryInterface
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

### Interface example (`UserQueryInterface.php`)

```php
interface UserQueryInterface
{
    /** @return User[] */
    public function listUsers(): array;

    /** @return User */
    public function getUser(?int $id): User;

    /** @return User|null */
    public function getUserByEmail(string $email): ?User;

    public function deleteUser(?int $id): void;

    /**
     * @param ?string $status   Pass null to skip this filter.
     * @param ?string $username Pass null to skip this filter.
     * @return User[]
     */
    public function searchUsers(?string $status = null, ?string $username = null): array;
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

The recommended pattern is to wrap the generated query class inside a repository class, bind it in a Service Provider using the generated interface, and inject it into controllers or services via the constructor.

### 1. Create a repository

```php
namespace App\Repositories;

use App\Database\User;
use App\Database\UserQueryInterface;

class UserRepository
{
    public function __construct(private UserQueryInterface $userQuery) {}

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

```php
namespace App\Providers;

use App\Database\UserQuery;
use App\Database\UserQueryInterface;
use App\Repositories\UserRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the interface to the concrete implementation
        $this->app->bind(UserQueryInterface::class, function ($app) {
            return new UserQuery(
                $app->make('db')->connection()->getPdo()
            );
        });

        $this->app->bind(UserRepository::class, function ($app) {
            return new UserRepository(
                $app->make(UserQueryInterface::class)
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

### 5. Testing with the interface

Because the repository depends on `UserQueryInterface`, you can swap in a mock without touching the database:

```php
class UserControllerTest extends TestCase
{
    public function test_show_returns_user(): void
    {
        $mock = $this->createMock(UserQueryInterface::class);
        $mock->method('getUser')->willReturn(new User(
            id: 1, email: 'alice@example.com', username: 'alice',
            // ...
        ));

        $this->app->instance(UserQueryInterface::class, $mock);

        $this->getJson('/api/users/1')->assertOk();
    }
}
```

---

## CLI flags

### `--verify` — CI check

Generates all files in memory and compares them against the existing output. Writes nothing. Exits `1` if anything is missing or out of date.

```bash
php vendor/bin/sqlc-php --verify sqlc.yaml
```

```
✓ All 6 generated file(s) are up to date.
```

```
✗ Generated files are out of date.

Missing files (1):
  - generated/OrderStatus.php

Modified files (1):
  - generated/User.php

Run `php vendor/bin/sqlc-php sqlc.yaml` to regenerate.
```

### `--dry-run` — preview without writing

Prints the full content of every file that would be generated to stdout. Writes nothing to disk.

```bash
php vendor/bin/sqlc-php --dry-run sqlc.yaml
```

```
──────────────────────────────────────────────────────────────────────
// generated/User.php
──────────────────────────────────────────────────────────────────────
<?php
declare(strict_types=1);
// ...

✓ Dry run complete. 4 file(s) would be written.
```

### `--diff` — show what would change

Compares generated content against existing files and prints a colored unified diff. Exits `0` when nothing would change, `1` when there are differences. Writes nothing.

```bash
php vendor/bin/sqlc-php --diff sqlc.yaml
```

```
--- generated/User.php (current)
+++ generated/User.php (generated)
  public ?int $id,
- public string $email,
+ public ?string $email,
  public ?bool $active,
```

---

## Running the tests

```bash
phpunit --configuration phpunit.xml
```

The test suite covers:

| Suite | File | What it tests |
|---|---|---|
| Schema Parser | `tests/Parser/SchemaParserTest.php` | CREATE TABLE, ENUM, nullable, AUTO_INCREMENT, DEFAULT |
| Query Parser | `tests/Parser/QueryParserTest.php` | All annotations incl. @deprecated, @nillable, blank lines |
| Type Mapper | `tests/TypeMapper/MySQLTypeMapperTest.php` | Default mappings, nullable override, PDO constants |
| JSON Type | `tests/TypeMapper/JsonTypeTest.php` | JSON → array, json_decode casts |
| Config | `tests/Config/ConfigTest.php` | YAML parsing, scalar/list schema and queries, generate_interfaces |
| New Features v1.3 | `tests/Config/NewFeaturesTest.php` | Multiple schemas, nullable override, @deprecated, @nillable |
| New Features v1.4 | `tests/NewFeaturesV14Test.php` | :many-paginated, @nillable on direct models, targets, --dry-run, --diff |
| Param Resolver | `tests/Resolver/ParamResolverTest.php` | WHERE/SET/UPDATE param resolution, camelCase→snake |
| Expression Resolver | `tests/Resolver/ExpressionTypeResolverTest.php` | All aggregate and scalar functions |
| Analyzer | `tests/Analyzer/QueryAnalyzerTest.php` | Full pipeline: model detection, JOINs, aggregates |
| SQL Rewriter | `tests/Rewriter/SqlRewriterTest.php` | All operators, unsafe construct guards |
| Optional Params | `tests/Analyzer/OptionalParamTest.php` | @optional end-to-end |
| Enum Generator | `tests/Generator/EnumGeneratorTest.php` | ENUM parsing, backed enum generation, fromRow casts |
| Interface Generator | `tests/Generator/InterfaceGeneratorTest.php` | Interface code, method signatures, implements clause |
| Generator | `tests/Generator/GeneratorTest.php` | Code structure, docblock indentation, PDO bindings |
| Verify Flag | `tests/VerifyFlagTest.php` | --verify exit codes, no file writes |

---

## Project structure

```
sqlc-php/
├── bin/
│   └── sqlc-php                        # CLI entry point (supports --verify)
├── src/
│   ├── Analyzer/
│   │   └── QueryAnalyzer.php           # Enriches parsed queries with resolved types
│   ├── Catalog/
│   │   └── SchemaCatalog.php           # In-memory table/column index
│   ├── Config/
│   │   ├── Config.php                  # YAML loader (schema/queries/targets lists)
│   │   ├── Target.php                  # Single output target value object
│   │   └── TypeOverride.php            # php_type + nullable override
│   ├── Generator/
│   │   ├── EnumGenerator.php           # Generates PHP 8.1 backed enums for ENUM columns
│   │   ├── InterfaceGenerator.php      # Generates *Interface alongside each Query class
│   │   ├── ModelGenerator.php          # Generates table DTO classes
│   │   ├── QueryGenerator.php          # Generates query classes with PDO methods
│   │   └── ResultDtoGenerator.php      # Generates result DTOs for JOIN/aggregate queries
│   ├── Parser/
│   │   ├── SchemaParser.php            # Parses CREATE TABLE SQL (including ENUM values)
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
├── tests/                              # PHPUnit test suite (326 tests)
├── sqlc.yaml                           # Example configuration
└── phpunit.xml                         # Test configuration
```

---

## Changelog

### [1.4.0]
- **`:many-paginated` return type** — auto-injects `LIMIT :limit OFFSET :offset` into the SQL at analysis time and appends `int $limit = 20, int $offset = 0` to the generated method signature. User-defined params always appear first; `$limit` and `$offset` are last. Works with `@optional` params on the same query.
- **`@nillable` on direct model queries** — previously `@nillable` only worked on multi-table JOIN queries. Now, when `@nillable` is used on a single-table `SELECT *` query (which would normally reuse the table model), a dedicated `*Row` DTO is generated instead, allowing nullability overrides without mutating the base model class.
- **Multiple output targets** — `targets:` block in `sqlc.yaml` allows generating multiple namespaces and output directories from the same schema in a single CLI run. Each target has its own `namespace`, `out`, `queries`, `generate_interfaces`, and optional `type_overrides` that merge on top of the root-level overrides.
- **`--dry-run` flag** — prints the full content of every file that would be generated to stdout, without writing anything to disk.
- **`--diff` flag** — shows a colored unified diff between current files and what would be generated. Exits `0` when nothing would change, `1` when there are differences. Writes nothing.
- **Parser fix** — `@returns` regex now accepts hyphens, enabling `:many-paginated` to be parsed correctly (previously only `\w` characters were matched).
- **YAML `parseScalar` fix** — double-quoted strings now correctly unescape `\\` → `\`, `\"` → `"`, `\n` → newline. This fixes namespace values like `"App\\Database"` being stored as `App\\Database` instead of `App\Database`.
- **33 new tests** in `tests/NewFeaturesV14Test.php` covering all five features end-to-end.

### [1.3.0]
- **Multiple schema files** — `schema` in `sqlc.yaml` now accepts a scalar string (legacy) or a YAML list, mirroring the existing `queries` list support. All files are parsed and merged into a single catalog before analysis. The `config->schemas` property always returns `string[]`.
- **Nullable override in `type_overrides`** — entries now accept an optional `nullable: true|false` field that forces the nullability of the generated property regardless of the schema column definition. Can be used alone (without `php_type`) to only change nullability while keeping the default type mapping.
- **`@deprecated` annotation** — adding `-- @deprecated reason` to a query emits a `@deprecated` PHPDoc tag on the generated method. The reason message is optional. The tag appears before `@param` lines following PHPDoc convention.
- **`@nillable` annotation** — adding `-- @nillable columnAlias` forces a specific result column to be `?type` in the generated DTO or return type, regardless of the schema. Useful for LEFT JOIN queries where a column from the joined table may be `NULL` at runtime even though it is `NOT NULL` in the schema. Multiple `@nillable` annotations can be stacked on the same query.
- **33 new tests** in `tests/Config/NewFeaturesTest.php` covering all four features end-to-end.

### [1.2.0]
- **MySQL ENUM → PHP backed enum** — `ENUM('a','b','c')` columns generate a PHP 8.1 backed enum file (e.g. `OrderStatus.php`). The DTO uses the enum as the property type. `fromRow` uses `::from()` for `NOT NULL` columns and `::tryFrom()` for nullable ones. Hyphenated values are converted to PascalCase case names (`in-progress` → `case InProgress`).
- **JSON column → typed array** — `JSON` columns now map to `array` (previously `string`). `fromRow` automatically calls `json_decode(..., true)` with a `?? []` fallback for `NOT NULL` columns.
- **Generate PHP interfaces** — enabling `generate_interfaces: true` in `sqlc.yaml` generates a `*Interface` file alongside each Query class (e.g. `UserQueryInterface`). The Query class declares `implements UserQueryInterface`. Useful for Laravel DI, mocking in tests, and depending on abstractions rather than concrete PDO classes.
- **`--verify` flag for CI** — `php vendor/bin/sqlc-php --verify sqlc.yaml` exits `0` when all generated files are up to date, `1` otherwise. Reports missing and modified files with a regeneration hint. Writes nothing to disk.
- **49 new tests** across `EnumGeneratorTest`, `JsonTypeTest`, `InterfaceGeneratorTest`, and `VerifyFlagTest`.

### [1.1.0]
- **Optional query parameters** — parameters can be marked with `@optional`. The SQL condition is rewritten at generation time so that passing `null` skips the filter entirely, without any PHP-side conditionals.
- **`SqlRewriter`** — rewrites `col OP :param` into `(:param IS NULL OR col OP :param)` for every occurrence of the parameter. Supported operators: `=`, `<>`, `!=`, `>`, `<`, `>=`, `<=`, `LIKE`, `ILIKE`.
- **Unsafe construct guard** — queries with `JOIN`, `HAVING`, or subqueries (`IN / EXISTS`) produce a fatal error at generation time when `@optional` is used, preventing silently incorrect SQL.
- **Parameter validation** — `@optional` names are validated against the SQL at parse time; typos produce a fatal error with the list of known params.
- **Method signature** — required params first, optional params last with `= null` and forced nullable type.
- **34 new tests** across `SqlRewriterTest` and `OptionalParamTest`.

### [1.0.0]
- **Multiple query files** — `queries` in `sqlc.yaml` accepts a scalar string or a YAML list of paths.
- **Expression type inference** — `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`, `COALESCE`, `IFNULL`, `NULLIF`, `CAST`, `CONCAT`, `CASE WHEN` resolved to typed PHP properties with auto-generated aliases.
- **`:opt` return type** — `:one` throws `RuntimeException` when no row is found; `:opt` returns `null`.
- **Type overrides** — `type_overrides` in `sqlc.yaml` remaps columns or DB types to arbitrary PHP types.
- **Initial release** — schema parser, query parser, param/column resolvers, PDO bindings, `readonly` DTOs, result DTOs for JOINs and aggregates.