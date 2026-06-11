# sqlc-php | https://phpibe.github.io/sqlc-php

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
php ./vendor/bin/sqlc-php sqlc.yaml                      # generate files
php ./vendor/bin/sqlc-php --dry-run  sqlc.yaml           # preview without writing
php ./vendor/bin/sqlc-php --diff     sqlc.yaml           # show what would change
php ./vendor/bin/sqlc-php --verify   sqlc.yaml           # CI check — exit 1 if stale
php ./vendor/bin/sqlc-php --watch    sqlc.yaml           # watch for changes, auto-regenerate
php ./vendor/bin/sqlc-php --watch --interval=250 sqlc.yaml  # custom poll interval (ms)
php ./vendor/bin/sqlc-php --version                      # print version and exit
```

---

## Configuration — `sqlc.yaml`

```yaml
version: "2"

# Schema files — one or many (required)
schema:
  - database/schema/users.sql
  - database/schema/orders.sql

# Global defaults — inherited by all targets unless overridden locally
engine:   mysql    # database engine (mysql supported; postgres planned for v1.7.0)
language: english  # english | spanish | french | portuguese | norwegian-bokmal | turkish

# Global type overrides — applied to all targets
type_overrides:
  - column:   "users.active"
    php_type: "bool"
  - db_type:  "TINYINT"
    php_type: "bool"
  # To use string instead of \DateTimeImmutable for date columns:
  # - db_type: "DATE"
  #   php_type: "string"

# Virtual tables — views or external tables not present in the schema files (optional)
virtual_tables:
  - name: user_summary
    columns:
      - { name: id,          type: INT }
      - { name: email,       type: VARCHAR }
      - { name: role_name,   type: VARCHAR, nullable: true }
      - { name: order_count, type: INT }

# Include additional YAML fragments — each can contain virtual_tables, type_overrides,
# and targets sections that are merged before the main file's values (optional)
includes:
  - config/views.yaml
  - config/overrides.yaml

# Output targets — one or more (required)
targets:
  - namespace: "App\\Database"
    out:       generated
    queries:
      - database/queries/users.sql
      - database/queries/orders.sql
    # generate_interfaces: true   ← default, omit unless you want false
    # engine:   mysql             ← override global engine for this target
    # language: spanish           ← override global language for this target
    # type_overrides:             ← merged on top of global overrides
    #   - column: "users.bio"
    #     php_type: "string"
```

### Minimal single-target config

```yaml
version: "2"
schema: schema.sql
targets:
  - namespace: "App\\Database"
    out:       generated
    queries:   queries.sql
```

`generate_interfaces` defaults to `true` — interfaces are generated unless explicitly set to `false`:

```yaml
targets:
  - namespace: "App\\Database"
    out:       generated
    queries:   queries.sql
    generate_interfaces: false   # disable only if not needed
```

### Multiple output targets

`targets` accepts any number of entries — each produces a separate generation pass using the same parsed schema:

```yaml
version: "2"
schema:
  - database/schema/users.sql
  - database/schema/orders.sql

engine:   mysql
language: english

type_overrides:
  - db_type: "TINYINT"
    php_type: "bool"

targets:
  - namespace: "App\\Database\\Read"
    out:       generated/read
    queries:
      - database/queries/read/users.sql
      - database/queries/read/orders.sql

  - namespace: "App\\Database\\Write"
    out:       generated/write
    queries:
      - database/queries/write/users.sql
    generate_interfaces: false
    type_overrides:               # merged on top of global overrides
      - column: "users.active"
        php_type: "bool"
```

Each target inherits the global `engine`, `language`, and `type_overrides`. A target can override any of them locally.

### Multiple schema files

`schema` accepts both a scalar string (single file) and a YAML list. All files are parsed and merged into a single catalog:

```yaml
schema:
  - database/schema/users.sql
  - database/schema/orders.sql
  - database/schema/roles.sql
```

### Per-target queries

Each target has its own `queries` list. `queries` accepts a scalar or a list:

```yaml
targets:
  - namespace: "App\\Database"
    out:       generated
    queries:
      - database/queries/users.sql
      - database/queries/roles.sql
      - database/queries/orders.sql
```

The CLI prints a per-file count alongside the total:

```
Schema : database/schema/users.sql
Schema : database/schema/orders.sql
Schema : 3 table(s) — users, orders, roles

Target : App\Database → generated/
  Queries: 8 query(ies) from database/queries/users.sql
  Queries: 3 query(ies) from database/queries/orders.sql
  Queries: 11 total
```

### Inflection language

sqlc-php uses [doctrine/inflector](https://github.com/doctrine/inflector) to singularise table names when inferring class names. Set globally or per-target:

```yaml
language: spanish   # global default

targets:
  - namespace: "App\\Spanish"
    out:       gen/es
    queries:   queries/es.sql
    # inherits language: spanish

  - namespace: "App\\French"
    out:       gen/fr
    queries:   queries/fr.sql
    language:  french   # override for this target
```

With `language: spanish`, tables like `usuarios`, `pedidos`, `categorias` produce `Usuario`, `Pedido`, `Categoria` without needing `@group` on every query.

| Table name | `english` (doctrine) | `spanish` |
|---|---|---|
| `analyses` | `Analysis` ✅ | — |
| `matrices` | `Matrix` ✅ | — |
| `usuarios` | `Usuarios` ❌ | `Usuario` ✅ |
| `pedidos`  | `Pedido` ✅ | `Pedido` ✅ |
| `users`    | `User` ✅ | — |

The `@group` annotation always takes precedence over inferred names.

### virtual_tables — views and external tables

`virtual_tables:` declares tables that exist in the database but have no `CREATE TABLE` in the schema files — views, materialized views, or tables from other schemas.

```yaml
virtual_tables:
  - name: user_summary
    columns:
      - { name: id,          type: INT }
      - { name: email,       type: VARCHAR }
      - { name: role_name,   type: VARCHAR, nullable: true }
      - { name: order_count, type: INT }

  - name: monthly_revenue
    columns:
      - { name: month,   type: INT }
      - { name: revenue, type: DECIMAL }
```

**Nullability convention** — all columns are `NOT NULL` by default. Specify `nullable: true` only for columns that can be null. This is the inverse of schema parsing where `NOT NULL` must be explicit.

Virtual tables are registered in the `SchemaCatalog` for column type resolution. Queries against them work exactly like queries against real tables. The only difference: **no `Model` class is generated** for virtual tables.

```sql
-- @name ListUserSummaries
-- @returns :many
SELECT * FROM user_summary;
```

Generates `UserSummaryQuery.php` with correct column types, but no `UserSummary.php` model.

### includes — splitting the config

`includes:` loads additional YAML fragments and merges their list fields (`virtual_tables:`, `type_overrides:`, `targets:`) into the main config. Scalar fields (`engine:`, `language:`) in include files are silently ignored.

```yaml
# sqlc.yaml
includes:
  - config/views/user_views.yaml
  - config/views/order_views.yaml
  - config/overrides/timestamps.yaml
```

```yaml
# config/views/user_views.yaml
virtual_tables:
  - name: user_summary
    columns:
      - { name: id,    type: INT }
      - { name: email, type: VARCHAR }
```

```yaml
# config/views/order_views.yaml
virtual_tables:
  - name: order_summary
    columns:
      - { name: id,    type: INT }
      - { name: total, type: DECIMAL }
```

All `virtual_tables:` entries from all includes are accumulated. Multiple include files can each declare their own `virtual_tables:` — they are all merged before processing.

| Field | Behaviour |
|---|---|
| `virtual_tables:` | Accumulated — all entries from all includes + main file |
| `type_overrides:` | Accumulated — includes first, main file appended last |
| `targets:` | Accumulated — includes first, main file appended last |
| `engine:`, `language:` | Ignored in includes — main file always controls scalars |



### Type override precedence

| Priority | Rule | Description |
|---|---|---|
| 1 | `column` | Exact `table.column` match — wins over everything |
| 2 | `db_type` | Matches any column whose SQL type matches |
| 3 | Default | Built-in SQL → PHP type mapping |

### Nullable override

Any `type_override` entry accepts an optional `nullable` field:

```yaml
type_overrides:
  - column:   "users.deleted_at"
    php_type: "\\Carbon\\Carbon"
    nullable: true          # force nullable even if NOT NULL in schema

  - db_type:  "TIMESTAMP"
    php_type: "\\DateTimeImmutable"
    nullable: false         # force not-null regardless of schema

  - column:   "users.created_at"
    nullable: false         # only change nullability, keep default type
```

When `nullable` is omitted, nullability is inherited from the schema.

### Default SQL → PHP type mapping

| SQL type | PHP type | Notes |
|---|---|---|
| `INT`, `BIGINT`, `SMALLINT`, `TINYINT` | `int` | |
| `DECIMAL`, `FLOAT`, `DOUBLE` | `float` | |
| `VARCHAR`, `CHAR`, `TEXT` | `string` | |
| `DATE`, `DATETIME`, `TIMESTAMP` | `\DateTimeImmutable` | `fromRow` uses `new \DateTimeImmutable(...)` |
| `TIME` | `string` | no standard PHP time-interval type |
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
-- @embed    ClassName prefix_  optional — groups prefixed columns into a nested object
-- @dto      ClassName          optional — overrides the auto-generated DTO class name
-- @column   originalName alias optional — renames a result column in the DTO without SQL AS
-- @calls    method1,method2    optional — used with :transaction to list methods to call
-- @counted                     optional — generate companion {name}Count(): int method (only with :many-paginated)
-- @class    ClassName          sets the PHP class name (canonical, replaces @group)
-- @group    ClassName          deprecated — use @class instead (still works, emits a warning)
```

### Return type semantics

| Annotation | PHP return type | Behaviour |
|---|---|---|
| `:many` | `ModelClass[]` | Returns an array; empty array if no rows |
| `:many-paginated` | `ModelClass[]` | Like `:many` but auto-injects `LIMIT`/`OFFSET` params |
| `:one` | `ModelClass` | Returns the object; **throws `RuntimeException`** if no row found |
| `:opt` | `ModelClass\|null` | Returns the object or `null` if no row found |
| `:exec` | `void` | Executes the statement (INSERT, UPDATE, DELETE) |
| `:batch` | `int` | Executes the same INSERT/UPDATE N times in a transaction; returns row count |
| `:transaction` | `void` | Runs multiple `@calls` methods sequentially in one transaction |

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

### IN() clauses — array parameters

Parameters inside `IN()` clauses are automatically detected and handled with dynamic placeholder expansion at runtime. No manual SQL building required.

```sql
-- @name GetByIds
-- @group User
-- @returns :many
SELECT users.* FROM users WHERE id IN (:ids);
```

Generated method:

```php
/**
 * @param int[] $ids List of values for IN() clause — must be non-empty.
 * @return User[]
 */
public function getByIds(array $ids): array
{
    // Expand IN() placeholders dynamically at runtime
    $__sql = 'SELECT * FROM users WHERE id IN (:ids)';
    if (empty($ids)) {
        throw new \InvalidArgumentException('Parameter $ids for IN() clause must not be empty.');
    }
    $__ph_ids = implode(',', array_fill(0, count($ids), '?'));
    $__sql = str_replace(':ids', $__ph_ids, $__sql);
    $stmt = $this->pdo->prepare($__sql);
    $stmt->execute([...$ids]);

    return array_map(
        static fn(array $row): User => User::fromRow($row),
        $stmt->fetchAll(PDO::FETCH_ASSOC),
    );
}
```

The element type in the docblock (`int[]`) is inferred from the column type, just like any other parameter.

#### Mixed IN and regular parameters

```sql
-- @name FilterUsers
-- @returns :many
SELECT users.* FROM users
WHERE id IN (:ids) AND active = :active;
```

```php
/**
 * @param int[] $ids    List of values for IN() clause — must be non-empty.
 * @param int   $active
 * @return User[]
 */
public function filterUsers(array $ids, int $active): array
```

Regular params are bound with `bindValue()`; IN-list params are expanded positionally and passed to `execute(array)`. The two mechanisms are combined transparently.

#### Multiple IN clauses

```sql
-- @name FilterByIdsAndRoles
-- @returns :many
SELECT users.* FROM users
WHERE id IN (:ids) AND role_id IN (:roleIds);
```

```php
public function filterByIdsAndRoles(array $ids, array $roleIds): array
```

Each IN-list param gets its own placeholder variable (`$__ph_ids`, `$__ph_roleIds`) and its values are spread into `execute()` in order.

#### NOT IN

`NOT IN (:param)` works exactly like `IN (:param)`:

```sql
SELECT users.* FROM users WHERE id NOT IN (:excludedIds);
```

```php
public function excludeIds(array $excludedIds): array
```

---

### :batch — bulk operations in a transaction

Executes the same INSERT or UPDATE query N times inside a single PDO transaction. Rolls back and re-throws on any failure.

```sql
-- @name InsertUsers
-- @group User
-- @returns :batch
INSERT INTO users (email, username) VALUES (:email, :username);
```

```php
$count = $userQuery->insertUsers([
    ['email' => 'alice@example.com', 'username' => 'alice'],
    ['email' => 'bob@example.com',   'username' => 'bob'],
]);
// → int (number of rows processed)
```

The statement is prepared once and reused for every row. An empty `$rows` array returns `0` without opening a transaction.

---

### :transaction — multi-method transactions

Groups multiple `:exec` methods from the same Query class into a single transaction via `@calls`. Requires `@group` since there is no SQL to infer the group from.

```sql
-- @name TransferFunds
-- @group Account
-- @returns :transaction
-- @calls debitAccount,creditAccount
```

```php
// Wraps $this->debitAccount() and $this->creditAccount() in beginTransaction/commit/rollBack
public function transferFunds(): void { ... }
```

If the `:transaction` method has `@param` declarations, they are forwarded to all callee methods.

---

### Prepared statement caching

Opt-in per target. Caches PDOStatement objects to avoid re-preparing the same SQL on every call — especially useful in loops.

```yaml
targets:
  - namespace: "App\\Database"
    out: generated
    queries: queries.sql
    prepared_statement_cache: true
```

With caching enabled, the generated class includes `private array $stmts = []` and every method uses:

```php
$stmt = $this->stmts[__FUNCTION__] ??= $this->pdo->prepare('SELECT ...');
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

### @embed — nested objects for JOIN results

`@embed ClassName prefix_` groups all result columns whose alias starts with `prefix_` into a nested `readonly` value object instead of flattening them into the parent DTO.

```sql
-- @name GetUserWithRole
-- @group User
-- @returns :one
-- @embed Role role_
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

**`Role.php`** — standalone readonly value object with stripped property names:

```php
readonly class Role
{
    public function __construct(
        public string  $name,
        public ?string $description,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['role_name'],
            $row['role_description'] ?? null,
        );
    }
}
```

**`GetUserWithRoleRow.php`** — parent DTO with the nested `Role` object as a property:

```php
readonly class GetUserWithRoleRow
{
    public function __construct(
        public ?int  $id,
        public string $email,
        public Role   $role,       // ← nested object, not flat properties
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['email'],
            Role::fromRow($row),   // ← hydrates from the same flat PDO row
        );
    }
}
```

Usage:

```php
$result = $repo->getUserWithRole(42);

echo $result->role->name;         // instead of $result->role_name
echo $result->role->description;
```

#### Multiple @embed groups on one query

```sql
-- @name GetUserFull
-- @group User
-- @returns :one
-- @embed Role     role_
-- @embed Address  addr_
SELECT
    users.id,
    users.email,
    roles.name           AS role_name,
    addresses.street     AS addr_street,
    addresses.city       AS addr_city
FROM users
INNER JOIN roles     ON roles.id     = users.role_id
INNER JOIN addresses ON addresses.id = users.address_id
WHERE users.id = :id;
```

Generates `Role.php`, `Address.php`, and `GetUserFullRow.php` with:

```php
public function __construct(
    public ?int    $id,
    public string  $email,
    public Role    $role,     // prefix: role_
    public Address $addr,     // prefix: addr_
) {}
```

#### Naming convention

The DTO property name is derived from the prefix by stripping the trailing underscore:
- `role_` → `$role`
- `addr_` → `$addr`
- `billing_` → `$billing`

The prefix can be written with or without trailing underscore in the annotation:
`@embed Role role_` and `@embed Role role` both produce the same result.

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
├── Role.php                      # embedded value object from @embed Role role_
├── User.php                      # readonly DTO for the `users` table
├── Order.php                     # readonly DTO for the `orders` table
├── GetUserWithRoleRow.php        # result DTO for a JOIN query with @embed
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
| Embed | `tests/EmbedTest.php` | @embed annotation, EmbedDefinition, EmbedGenerator, nested DTO generation |
| Inflector | `tests/InflectorServiceTest.php` | InflectorService, all 6 languages, Config language field, group inference |
| Bug Fixes | `tests/BugFixTest.php` | Regression tests for v1.5.2 critical and medium bug fixes |
| IN() Params | `tests/InListParamTest.php` | IN/NOT IN detection, type inference, signature, placeholder expansion |
| TypeMapper Factory | `tests/TypeMapper/TypeMapperFactoryTest.php` | Interface contract, factory engine resolution, unsupported engine errors |
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
│   │   ├── EmbedGenerator.php          # Generates nested value-object classes for @embed
│   │   ├── EnumGenerator.php           # Generates PHP 8.1 backed enums for ENUM columns
│   │   ├── InterfaceGenerator.php      # Generates *Interface alongside each Query class
│   │   ├── ModelGenerator.php          # Generates table DTO classes
│   │   ├── QueryGenerator.php          # Generates query classes with PDO methods
│   │   └── ResultDtoGenerator.php      # Generates result DTOs; handles @embed partitioning
│   ├── Inflector/
│   │   └── InflectorService.php        # doctrine/inflector wrapper with fallback
│   ├── Parser/
│   │   ├── EmbedDefinition.php         # Value object for @embed annotation
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
│       ├── TypeMapperInterface.php     # Contract all engine mappers must implement
│       ├── TypeMapperFactory.php       # Resolves mapper by engine (mysql → MySQLTypeMapper)
│       └── MySQLTypeMapper.php         # MySQL: SQL types → PHP types + PDO constants
├── tests/                              # PHPUnit test suite (573 tests)
├── sqlc.yaml                           # Example configuration
└── phpunit.xml                         # Test configuration
```

---

## Changelog

### [2.9.2] — `scoped_dtos` & embed collision detection

**Embed collision detection:** when two queries use `@embed` with the same class name but different columns, generation now **aborts with a clear error** instead of silently overwriting:

```
Error: @embed class 'BillingReserve' is declared in multiple queries with different column shapes.

  getBillingDetails   → id:int, total_price:float
  getBillingWithDate  → id:int, created_at:DateTimeImmutable

Solutions:
  1. Enable scoped_dtos: true in sqlc.yaml
  2. Use distinct class names
  3. Use the same columns in both queries
```

**`scoped_dtos: true`:** each query's DTOs and embeds get a dedicated subdirectory named after the method. Collisions become structurally impossible:

```yaml
targets:
  - namespace: "App\Database"
    scoped_dtos: true
    out:
      dtos: app/Database/DTOs
```

```
DTOs/GetBillingDetails/BillingDetails.php    ← App\Database\DTOs\GetBillingDetails
DTOs/GetBillingDetails/BillingReserve.php    ← same namespace
DTOs/GetBillingWithDate/BillingWithDate.php  ← App\Database\DTOs\GetBillingWithDate
DTOs/GetBillingWithDate/BillingReserve.php   ← different namespace, no collision
```

Backward compatible — `scoped_dtos: false` by default. 16 new tests.

### [2.9.1] — `table.*` with `@embed` bugfix

**Bug fix:** using `reserve_billing.*` alongside `@embed` columns with `__` prefixes now generates the correct return type.

**Root cause:** `detectDirectModel` counted `reserve__id` (from `reserve` table) as proof of multiple tables, discarding the `@dto` annotation and falling back to `GetDetailsRow`.

**Fix:** columns with `__` in their alias are excluded from the single-table check — they are embedded object fields by design. `@embed` still forces DTO mode (the plain model doesn't have nested properties), but the `@dto` class name is correctly used as the return type.

```sql
-- @name GetDetails
-- @class ReserveBilling
-- @dto   ReserveBilling         ← now correctly used as return type
-- @embed ReserveBillingReserve reserve__
-- @returns :one
SELECT reserve_billing.*,          -- expands all billing columns
    reserve.id as reserve__id,     -- __ prefix = @embed, excluded from model check
    reserve.created_at as reserve__created_at
FROM reserve_billing
INNER JOIN reserve ON reserve_billing.reserve_id = reserve.id
WHERE reserve_billing.reserve_id = :id
```

13 new tests in `tests/TableWildcardEmbedTest.php`.

### [2.9.0] — OR groups in Criteria & UNION queries

**`Criteria::orGroup()`** — adds OR conditions to `@searchable` criteria. Fully immutable, backward compatible.

```php
(new UserCriteria())
    ->whereActiveEq(1)
    ->orGroup(fn($c) => $c->whereCountryIdEq(164))
    ->orGroup(fn($c) => $c->whereCountryIdEq(165))
// WHERE active = :active_f0 OR country_id = :country_id_f1 OR country_id = :country_id_f2
```

**UNION / UNION ALL** — natively supported. Types resolved from first SELECT. `@searchable`, `@partial`, `@returning` rejected on UNION with clear errors.

35 new tests in `tests/OrGroupUnionTest.php`.

### [2.8.5] — Technical debt refactor

Three structural improvements with no user-facing behavior changes — same SQL output, same generated code, better internals.

**Fix A — `renderPaginateCore()`**: the duplicated COUNT+SELECT body (∼50 lines) that appeared in both `renderPaginateMethod` and `renderSearchablePaginateMethod` is now a single shared method. Both entry points pass their specific SQL expressions and binding blocks as parameters.

**Fix B — `InterfaceGenerator` strategy dispatch**: the monolithic `renderMethodSignature()` with 7 `if/elseif` branches is replaced by a `match()` dispatch table routing to one dedicated renderer per return-type family. Adding a new return type now means adding one method — the router never changes.

**Fix C — `renderBindings(string $stmtVar = '$stmt')`**: root cause of the `$stmt undefined` bug in `:paginated`. `renderBindings()` now accepts the PDO statement variable name explicitly. The `:paginated` methods call `renderBindings($query, '$__countStmt')` and `renderBindings($query, '$__stmt')` directly — the `str_replace('$stmt->', ...)` workaround is gone.

23 new tests in `tests/TechDebtRefactorTest.php`.

### [2.8.0] — `:paginated` & `@returning`

**`:paginated`** — new return type (alongside `:many`, `:one`, etc.) that returns a `PaginatedResult` with items + metadata in one call:

```sql
-- @name ListUsers
-- @returns :paginated    ← limit defaults to 10, runs COUNT + SELECT
SELECT * FROM users WHERE active = :active ORDER BY created_at DESC;
```

```php
$result = $query->listUsers(active: 1, limit: 20, offset: 0);
$result->items;         // User[] — current page
$result->total;         // 150 — total matching rows
$result->pages;         // 8
$result->hasMore;       // true
$result->nextOffset();  // 20
```

**`@returning`** — INSERT that fetches and returns the created row:

```sql
-- @name CreateUser
-- @returning
-- @returns :one
INSERT INTO users (email, active) VALUES (:email, :active);
```

```php
$user = $query->createUser(email: 'alice@example.com', active: 1);
echo $user->id;    // auto-increment PK from lastInsertId()
```

Other changes:
- `SchemaCatalog::primaryKey()` — detects PK from `PRIMARY KEY`, `AUTO_INCREMENT`, or column `id`
- `ColumnDefinition::$isPrimaryKey` — new field from schema parser
- `SqlcPhp\Query\PaginatedResult` — new runtime readonly class with navigation helpers
- 53 new tests in `tests/PaginateReturningTest.php`

### [2.7.7] — `toDebugBindings()` for Debugbar integration

- **`QueryObject::toDebugBindings(): list<mixed>`** — flat array of values for `QueryExecuted` / Debugbar `QueryCollector`. Filters `_chk` params (`@optional`) and `:limit`/`:offset` (`:many-paginated`).

**The bug:** passing `$q->bindings()` directly to Debugbar showed `[,1]` because `bindings()` returns `[value, PDO_TYPE]` tuples — Debugbar serialized the inner array as a string.

**Fix — Option A (recommended):** `toDebugSql()` + empty bindings:
```php
// ServiceProvider
$this->app->bind(BillingConfigRepositoryInterface::class, function ($app) {
    return new BillingConfigRepository(
        pdo: $app->make('db')->connection()->getPdo(),
        afterQuery: function (QueryObject $q) use ($app): void {
            $collector = \Debugbar::getCollector('queries');
            $qe = new QueryExecuted(
                $q->toDebugSql(),  // SQL with values already interpolated
                [],
                $q->durationMs,
                $app->make('db')->connection(),
            );
            $collector->addQuery($qe);
        },
    );
});
```

**Fix — Option B:** `toString()` + `toDebugBindings()`:
```php
$qe = new QueryExecuted(
    $q->toString(),        // SQL with :placeholders
    $q->toDebugBindings(), // [1, 164] — flat, _chk filtered
    $q->durationMs,
    $connection,
);
```

- 17 new tests in `tests/DebugBindingsTest.php`.

### [2.7.6] — query execution timing (durationMs)

- **`QueryObject::$durationMs`** — every method wraps `$stmt->execute()` with `hrtime(true)` and stores the elapsed milliseconds.
- **`QueryObject::withDuration(float $ms): self`** — immutable named constructor for setting duration.
- **Log format updated** — `"listActiveUsers [4.217ms]: SELECT * FROM users WHERE ..."`.
- **`:batch` timing** — measures full transaction (all rows + commit).
- 21 new tests in `tests/DurationTest.php`.

```php
$repo->listActiveUsers(active: 1);
$q = $repo->lastQuery();

echo $q->durationMs;   // 4.217 — float milliseconds

// Slow query detection
new UserRepository(
    pdo:        $pdo,
    afterQuery: function (QueryObject $q): void {
        if ($q->durationMs > 100) {
            Log::warning("Slow: {$q->queryName} took {$q->durationMs}ms");
        }
    },
);
```

### [2.7.5] — PSR-3 logger & afterQuery hook

- **Constructor updated** — every generated Query class now accepts `?LoggerInterface $logger = null` and `?Closure $afterQuery = null`. Fully backward compatible.

```php
$repo = new UserRepository(
    pdo:        $pdo,
    logger:     app(LoggerInterface::class),        // PSR-3 → Telescope / files
    afterQuery: fn(QueryObject $q) =>
        \Debugbar::addMessage($q->toString(), 'queries'),  // Debugbar
);
```

- **PSR-3 logger** — every executed method calls `$logger->debug(queryName + SQL, values)`. Works with Monolog, Laravel Log, Symfony Logger. Appears in Telescope Logs tab.
- **afterQuery hook** — `Closure` called after every query with `QueryObject`. Use for Debugbar, OpenTelemetry, metrics, per-request query collection.
- `psr/log: ^1.0 || ^2.0 || ^3.0` added as a dependency.
- 22 new tests in `tests/LoggerHookTest.php`.

### [2.7.4] — lastQuery() inspection

- **`lastQuery(): ?QueryObject`** — every generated Query class records the SQL and bound parameters of the most recently executed method.

```php
$users = $repo->listActiveUsers(active: 1);
$q     = $repo->lastQuery();

echo $q->toString();    // SQL with placeholders — safe to log
echo $q->toDebugSql();  // SQL with values — debug only
$key   = $q->cacheKey();   // stable md5 for caching
$q->values();              // bound values as array
```

- **`QueryObject`** — readonly value object with `toString()`, `toDebugSql()`, `bindings()`, `values()`, `cacheKey()`, `paramCount()`. Lives in `SqlcPhp\Query\QueryObject`.
- **`Criteria::getBindings()`** — new method exposing filter bindings as array; enables `@searchable` queries to correctly populate `lastQuery`.
- **Not in interface** — `lastQuery()` is excluded from `*Interface.php` — it's infrastructure, not domain contract.
- 43 new tests in `tests/LastQueryTest.php`.

### [2.7.3] — out: map form (per-type directories)

- **`out:` now accepts a YAML map** — each file type gets its own output directory and namespace, enabling DDD layouts like `Database/Repositories`, `Database/Models`, `Database/DTOs`.

```yaml
targets:
  - namespace: "App\\Database"
    queries:   queries.sql
    out:
      queries:    database/Repositories   # → App\Database\Repositories\UserRepository.php
      models:     database/Models         # → App\Database\Models\User.php
      dtos:       database/DTOs           # → App\Database\DTOs\GetUserWithRoleRow.php
      enums:      database/Enums
      interfaces: database/Contracts
      criterias:  database/Criterias
```

- **Namespace derivation** — `namespace + '\' + last path segment`. No extra config.
- **Automatic `use` statements** — injected where needed when namespaces differ.
- **Backward compatible** — `out: generated` (string form) still works exactly as before.
- **Error on missing type** — generation fails with a clear message if a needed type has no declared dir.
- 23 new tests in `tests/OutputConfigTest.php`.

### [2.7.1] — @partial (PATCH/UPDATE)

- **`@partial` annotation** — marks an UPDATE query as a partial update. Parameters inside `COALESCE(:param, column)` in the SET clause become optional (`?type $param = null`). Passing `null` leaves the column unchanged at the database level via `COALESCE(NULL, column)` — no PHP conditionals needed.

```sql
-- @name PatchUser
-- @partial
-- @returns :exec
UPDATE users SET
  email  = COALESCE(:email,  email),
  name   = COALESCE(:name,   name),
  active = COALESCE(:active, active)
WHERE id = :id;
```

```php
// Generated: required WHERE params first, optional SET params last
public function patchUser(int $id, ?string $email = null, ?string $name = null, ?int $active = null): void

// Update only email
$query->patchUser(id: 1, email: 'new@example.com');

// Update only active
$query->patchUser(id: 1, active: 0);
```

- **Param ordering is automatic** — WHERE params (required) always come first; SET params (optional) go last, regardless of order in the SQL.
- Can be combined with `@optional` on the same query for optional WHERE filters.
- Only valid on `:exec` UPDATE queries. Detects COALESCE params at compile time — no runtime overhead.
- 23 new tests in `tests/PartialTest.php`.

### [2.7.0] — @searchable dynamic filters

- **`@searchable` annotation** — adds a typed `Criteria` parameter to `:many` and `:many-paginated` methods. Enables dynamic `WHERE` conditions and `ORDER BY` at runtime, without writing separate queries.
- **Generated `{Group}Criteria` class** — extends `SqlcPhp\Criteria\Criteria`. Contains typed per-column methods inferred from the result schema: `whereActiveEq(int)`, `whereEmailLike(string)`, `whereIdIn(int ...$values)`, `whereCreatedAtBetween(DateTimeImmutable, DateTimeImmutable)`, `orderByCreatedAt('DESC')`, etc.
- **`@searchable` + `@counted`** — the companion count method also accepts the same Criteria, ensuring counts match the filtered result set.
- **`@searchable` + `:many-paginated`** — two-branch generation preserved (`$limit === null` → all rows).
- **SQL injection safe** — column names in ORDER BY are validated against an `ALLOWED_COLUMNS` whitelist generated at compile time. IN/NOT_IN values use per-element placeholders.
- **Static WHERE compatibility** — if the base SQL already has a `WHERE` clause, the Criteria appends `AND` conditions. Without a WHERE, it adds `WHERE`.
- **Static ORDER BY compatibility** — if the base SQL has an ORDER BY, the Criteria replaces it when the caller provides one; falls back to the static order otherwise.
- **Immutable Criteria** — `add()` and `orderBy()` return new instances; the original is never mutated.
- **`SqlcPhp\Criteria\` namespace** — three new runtime classes: `Criteria`, `Filter`, `FilterOperator`.
- 71 new tests in `tests/SearchableTest.php`.

```sql
-- @name ListBillingConfig
-- @class BillingConfig
-- @searchable
-- @counted
-- @returns :many-paginated
SELECT id, active, country_id, end_num FROM billing_config;
```

```php
$results = $query->listBillingConfig(
    criteria: (new BillingConfigCriteria())
        ->whereActiveEq(1)
        ->whereCountryIdIn(164, 165)
        ->orderByEndNum('DESC'),
    limit: 20,
    offset: 0,
);
$total = $query->listBillingConfigCount(
    criteria: (new BillingConfigCriteria())->whereActiveEq(1)
);
```

### [2.6.2] — symfony/yaml migration

- **YAML parsing migrated to `symfony/yaml`** — the hand-written subset-YAML parser (`parseYaml`, `parseList`, `parseNestedMap`, etc.) has been replaced with `symfony/yaml`, the standard PHP YAML library. This eliminates a persistent source of subtle parsing bugs — at least 4 bugs in recent versions were caused by edge cases in the custom parser.
- **`symfony/yaml` added as a `require` dependency** in `composer.json` — users installing via Composer get the real implementation automatically.
- **`src/Config/YamlParser.php`** — the old parsing logic is preserved as a standalone fallback class, used via a thin shim (`vendor/symfony/yaml/Yaml.php`) in environments where `symfony/yaml` is not yet installed. This ensures zero breaking changes for existing installs during the transition.
- No behavior changes — the same `sqlc.yaml` configs that worked before continue to work.

### [2.6.0] — --generate-schema

- **`--generate-schema` CLI flag** — connects to a live database and generates the `schema.sql` file automatically. Eliminates the need to write or maintain `CREATE TABLE` statements by hand.

```bash
# Generate schema from live DB and write to schema.sql (as declared in sqlc.yaml)
php vendor/bin/sqlc-php --generate-schema sqlc.yaml

# Write to a custom path
php vendor/bin/sqlc-php --generate-schema --schema-output=db/schema.sql sqlc.yaml
```

- **`database:` config block** — new global and per-target option with `dsn`, `username`, `password`, `exclude_tables`, and `include_tables`.

```yaml
database:
  dsn:      "mysql:host=localhost;dbname=myapp;charset=utf8mb4"
  username: "${DB_USER}"     # ${ENV_VAR} expanded at runtime
  password: "${DB_PASS}"
  exclude_tables:
    - migrations
    - failed_jobs
    - sessions
```

- **`${ENV_VAR}` expansion** — credentials can be stored as environment variable references so the YAML file is safe to commit. Unknown variables are left unexpanded so the error is visible.
- **Engine detection from DSN** — the engine is inferred from the DSN prefix (`mysql:` → mysql, `pgsql:` → postgres).
- **MySQL support only** (v2.6.0) — uses `SHOW TABLES` + `SHOW CREATE TABLE`. PostgreSQL support comes with the Postgres engine in a future version.
- **`AUTO_INCREMENT=N` stripped** from generated DDL — prevents spurious git diffs on each re-generation.
- **Generated schema header** includes database name, timestamp, table count, and a `Do not edit manually` note.
- **`SchemaExtractorInterface`** + `MySQLSchemaExtractor` + `SchemaExtractorFactory` — new `src/SchemaExtractor/` layer.
- **YAML parser extended** — `parseList` now handles nested maps within list items (e.g. `database:` inside a `targets:` entry), enabling per-target database config.
- 26 new tests in `tests/GenerateSchemaTest.php`.

### [2.5.3] — @class annotation and class_suffix

- **`@class ClassName`** — new canonical annotation, replaces `@group`. Functionally identical: sets the PHP class name for the generated Query/Repository/… class. Using `@class` emits no warnings.
- **`@group` is deprecated** — still works for backward compatibility, but emits a warning to stderr: `@group is deprecated, use @class instead`. No behavior change.
- **`class_suffix` config option** — global or per-target option that controls the suffix appended to generated class names. Default: `Query`. Examples: `Repository` → `UserRepository`, `Service` → `UserService`.

```yaml
# Global — all targets use this suffix
class_suffix: Repository

targets:
  - namespace: "App\\Database"
    out: generated
    queries: queries.sql
    # class_suffix: Service   ← override per-target
```

```sql
-- @name GetUser
-- @class User          ← new canonical annotation (replaces @group)
-- @returns :one
SELECT * FROM users WHERE id = :id;
```

Generated: `UserRepository.php` with `class UserRepository` (and `UserRepositoryInterface` if `generate_interfaces: true`).

- **`@class` and `@group` both work together** — if both are declared, the first one wins (standard behavior).
- 22 new tests in `tests/ClassAnnotationTest.php`.

### [2.5.2] — Optional pagination limit

- **`:many-paginated` signature changed** — `$limit` is now `?int $limit = null` instead of `int $limit = 20`. Calling `->listUsers()` without arguments now returns **all rows** instead of the first 20. Pass a non-null `$limit` to activate pagination.
- **Two code paths generated** — when `$limit === null`, the method prepares the SQL without `LIMIT`/`OFFSET` and skips those bindings. When `$limit !== null`, it prepares the SQL with `LIMIT :limit OFFSET :offset` and binds all three values. Both paths bind the same user-defined WHERE params.
- **`prepared_statement_cache: true`** — each path uses a distinct cache key (`__FUNCTION__ . '_all'` and `__FUNCTION__ . '_page'`) to avoid caching the wrong statement.
- **`@counted` unaffected** — the companion `{name}Count()` method never had `$limit`/`$offset` and continues to work correctly.
- **Interface updated** — the `*Interface` method signature reflects `?int $limit = null`.
- 19 new tests in `tests/OptionalPaginationTest.php`.

### [2.5.0] — @counted pagination

- **`@counted` annotation** — adds an automatic `{name}Count(): int` companion method to any `:many-paginated` query. The count method wraps the original SQL in `SELECT COUNT(*) FROM (...) AS _count_subquery`, correctly handling `WHERE`, `GROUP BY`, `HAVING`, `JOIN`, and `@optional` params.
- **No `$limit`/`$offset` in count signature** — the companion method accepts all user-defined WHERE params but not the pagination params, since they don't affect the total count.
- **`@optional` + `@counted` works correctly** — the `_chk` tokens are bound in the count method as expected.
- **Interface includes count method** — when `generate_interfaces: true`, the `*Interface` file declares both the main paginated method and the count method.
- **`prepared_statement_cache: true` + `@counted`** — the count method also uses `$this->stmts[__FUNCTION__] ??=` caching.
- **`$limit`/`$offset` filtered from `buildParamList`** — for `:many-paginated` queries, the auto-injected pagination params no longer appear in user-facing method signatures or docblocks. They were always bound separately, but were incorrectly included in `$query->params` after `ParamResolver` processed the rewritten SQL.
- 25 new tests in `tests/CountedTest.php`.

### [2.4.0] — Watch mode

- **`--watch` flag** — starts a file-system polling loop that regenerates automatically when any watched file changes. Watched files include `sqlc.yaml`, all `schema:` files, and all `queries:` files from every target. On config change the watch list is updated automatically to reflect new files.
- **`--interval=N`** — set the polling interval in milliseconds (default: 500ms, minimum: 100ms). Example: `--watch --interval=250`.
- **`Watcher` class** — `src/Watcher.php` tracks files by `filemtime` and returns changed paths on each `poll()` call. The watch list can be replaced at runtime via `setAll()` to adapt to config changes.
- **`runGeneration()` function** — the CLI generation logic was refactored into a top-level `runGeneration(configPath, verifyMode, dryRun, diffMode, silent)` function, enabling both single-run and watch-loop invocation without code duplication.
- **`--watch` cannot be combined with `--verify`, `--dry-run`, or `--diff`** — attempting this prints an error and exits 1.
- 16 new tests in `tests/WatcherTest.php`.

### [2.3.0]

- **`:batch` return type** — executes the same query N times inside a single PDO transaction with automatic rollback. The method accepts `array $rows`, binds each row's values, and returns `int` (affected row count). Throws `\Throwable` on failure and rolls back.
- **`:transaction` return type** — groups multiple `:exec` calls from the same Query class into a single transaction method. Declare with `@calls method1,method2` to specify which methods to call in sequence. Requires `@group` annotation.
- **`@calls` annotation** — companion to `:transaction`. Lists the method names to call in the transaction, comma-separated.
- **Prepared statement caching** — opt-in per target via `prepared_statement_cache: true` in `sqlc.yaml`. Generates a `private array $stmts = []` property and uses `$this->stmts[__FUNCTION__] ??= $this->pdo->prepare(...)` for all non-IN-list methods.
- **`INSERT INTO` group inference** — `extractFromTable` now recognises `INSERT INTO table` for `:batch` queries, enabling automatic `@group` inference from the INSERT target table.
- **`NULL` literal → `mixed`** — `NULL AS alias` in SELECT is now correctly handled in `ExpressionTypeResolver` instead of falling through to the default.
- **Subquery in FROM emits warning** — `(SELECT ...)` in a SELECT expression now writes a warning to stderr instead of silently returning `mixed`.
- **Virtual table JOIN alias resolution** — columns from virtual tables accessed via aliases (`vs.order_count` where `vs` → `user_summary`) now resolve to the correct type. The `QueryAnalyzer` receives the `SchemaCatalog` to look up virtual table columns via alias.
- 26 new tests in `tests/NewFeaturesV23Test.php`.

### [2.2.0]

- **`@dto ClassName`** — overrides the auto-generated `{QueryName}Row` DTO class name. Multiple queries can share the same `@dto` name if their column shapes match. A warning is emitted when two queries with different shapes declare the same `@dto` name.
- **`@column originalName alias`** — renames a result column in the generated DTO without adding `AS` to the SQL. Works on `SELECT *` queries (forces a custom DTO), JOIN queries, and aggregate queries. Multiple `@column` annotations can be stacked.
- **`Version::VERSION` and `--version` flag** — `src/Version.php` is the single source of truth for the project version. `php vendor/bin/sqlc-php --version` (or `-v`) prints the version and exits.
- 21 new tests in `tests/NewFeaturesV22Test.php`.

### [2.1.1] — Bug fixes and DateTimeImmutable

**Bug fixes**

- **`MAX(alias.col)` / `MIN(alias.col)` / `SUM(alias.col)` resolved to `?string`** — `ExpressionTypeResolver.resolveInnerType()` received the inner expression already uppercased (e.g. `M.VOUCHER_NUMBER`) but the table alias map had lowercase keys (`m`). The lookup silently fell through to the `string` fallback. Fix: `strtolower($inner)` at the start of `resolveInnerType()`.

**DateTimeImmutable mapping**

- **`DATE`, `DATETIME`, `TIMESTAMP` now map to `\DateTimeImmutable`** — previously all three mapped to `string`, requiring a `type_override` to get proper date objects. `TIME` stays `string` — no standard PHP type for time intervals.
- **`TypeMapperInterface::fromRowCast()`** — new method that generates the correct `fromRow()` cast expression for any PHP type. Handles `\DateTimeImmutable`, backed enums (`::from()`/`::tryFrom()`), array/JSON, and all scalars.
- **`ModelGenerator`, `ResultDtoGenerator`, `EmbedGenerator`** — all three generators now delegate `fromRow()` cast generation to the mapper instead of maintaining their own hardcoded `buildCast()`. Adding support for any new PHP type in a future engine (e.g. PostgreSQL `uuid → Uuid`) only requires updating the mapper.
- Users who need `string` for date columns can use `type_overrides`:
  ```yaml
  type_overrides:
    - db_type: "DATE"
      php_type: "string"
  ```
- 14 new tests in `tests/TypeMapper/MySQLTypeMapperTest.php`.

### [2.1.0] — virtual_tables and includes

- **`virtual_tables:`** — declare tables that exist in the database but not in the schema files (views, materialized views, external tables). Columns default to `NOT NULL`; mark `nullable: true` only when needed. Virtual tables participate in column type resolution and `@group` inference like regular tables, but no `Model` class is generated for them.
- **`includes:`** — split the config into multiple YAML fragments. Each include file can contain `virtual_tables:`, `type_overrides:`, and `targets:` sections, all of which are accumulated (appended) in order before the main file's values. Scalar fields (`engine`, `language`) in include files are silently ignored — the main file always controls them.
- **Inline map syntax** in YAML — column definitions can now use `{ name: id, type: INT }` inline syntax in addition to the full multi-line block form.
- **YAML parser fix** — `parseList` now correctly handles multiple map entries that each contain a nested sub-list (e.g. multiple `virtual_tables` entries each with their own `columns:` list).
- **24 new tests** in `tests/VirtualTableTest.php`.

### [2.0.0] — Unified configuration

- **`targets:` is now required** — the `php:` block has been removed. All configuration lives under `targets:`. This eliminates the dual configuration paths and makes the schema explicit.
- **`generate_interfaces` defaults to `true`** — interfaces are now generated by default. Set `generate_interfaces: false` on a target only when not needed.
- **`engine` and `language` are global fields** — no longer nested inside `php:`. Both can be overridden per target.
- **`version: "2"`** — configs should declare `version: "2"`. Omitting it defaults to `"2"`.
- **`schema:` and `targets:` are both required** — omitting either throws a clear `RuntimeException` at startup.
- **`targets:` supports nested `queries:` lists** — the YAML parser was extended to handle two-level nesting (list of maps, each with its own sub-list), enabling per-target query file lists.
- **No behavior change** — the generation pipeline is identical. Only the config surface changed.

**Migration from v1:**

```yaml
# v1 (removed)
version: "1"
schema: schema.sql
queries: queries.sql
php:
  namespace: "App\\Database"
  out: generated
  engine: mysql
  generate_interfaces: true
  language: spanish
type_overrides:
  - db_type: "TIMESTAMP"
    php_type: "\\DateTimeImmutable"

# v2 (current)
version: "2"
schema: schema.sql
engine: mysql
language: spanish
type_overrides:
  - db_type: "TIMESTAMP"
    php_type: "\\DateTimeImmutable"
targets:
  - namespace: "App\\Database"
    out: generated
    queries: queries.sql
```

### [1.6.0] — PostgreSQL groundwork

- **`TypeMapperInterface`** — new interface (`src/TypeMapper/TypeMapperInterface.php`) that all type mappers must implement. Defines `toPhpType()` and `toPdoParam()` with their full signatures.
- **`TypeMapperFactory`** — new factory (`src/TypeMapper/TypeMapperFactory.php`) that resolves the correct mapper implementation based on `engine` in `sqlc.yaml`. Currently supports `mysql`; `postgres`/`postgresql`/`pgsql` throw a clear error pointing to v1.7.0.
- **`MySQLTypeMapper implements TypeMapperInterface`** — `MySQLTypeMapper` now explicitly implements the interface. `toPdoParam()` signature updated to accept optional `$tableName` and `$columnName` for interface compatibility.
- **Dependency injection refactor** — all consumers (`ParamResolver`, `ColumnResolver`, `ExpressionTypeResolver`, `ModelGenerator`, `QueryGenerator`) now depend on `TypeMapperInterface` instead of the concrete `MySQLTypeMapper`. Zero behaviour change.
- **CLI uses `TypeMapperFactory`** — `bin/sqlc-php` now calls `TypeMapperFactory::create($config->engine, ...)` instead of `new MySQLTypeMapper(...)` directly.
- **16 new tests** in `tests/TypeMapper/TypeMapperFactoryTest.php` covering the interface contract, factory engine resolution, error messages, and unsupported engines.

### [1.5.3]
- **`IN()` clause support** — parameters inside `IN (:param)` and `NOT IN (:param)` clauses are now automatically detected and handled. The resolver infers the element type from the column (e.g. `id IN (:ids)` → `int[] $ids`). The generated method accepts `array $ids`, validates it is non-empty, and expands placeholders at runtime using `str_replace` + `execute([...$ids])` — no manual SQL building required.
- **Multiple `IN()` params** — a single query can have any number of IN-list params, each independently expanded.
- **Mixed IN + regular params** — IN-list and named params coexist in the same query. Regular params use `bindValue()`; IN-list values are spread into `execute()`.
- **Element type inference** — the docblock annotation uses `int[]`, `string[]`, etc. inferred from the column definition.
- **`NOT IN` supported** — `NOT IN (:param)` is detected identically to `IN (:param)`.
- **28 new tests** in `tests/InListParamTest.php` covering detection, type inference, signature generation, placeholder expansion, multiple IN params, mixed queries, and all return types.

### [1.5.2] — Bug fixes

**Critical**

- **`:many-paginated` + existing `LIMIT` clause** — the analyzer now throws a `RuntimeException` if a `:many-paginated` query already contains a `LIMIT` keyword, preventing silent SQL duplication.
- **`:many-paginated` param name collision** — throws `RuntimeException` when the query has a user-defined param named `:limit` or `:offset`, which would conflict with the auto-injected pagination params.
- **Backtick-quoted table/column names ignored** — `SchemaParser` now correctly parses tables and columns wrapped in backtick identifiers (`` `user_sessions` ``, `` `session_id` ``). Previously these tables were silently skipped.
- **Overlapping `@embed` prefixes** — `ResultDtoGenerator` now sorts embed definitions by prefix length descending before assigning columns, so longer (more specific) prefixes like `role_type_` win over shorter ones like `role_`.
- **`BETWEEN` with `@optional`** — `SqlRewriter` now guards against `BETWEEN :param AND :param2` as an unsafe construct. Previously the condition was silently left unrewritten while the method signature had `= null`, causing runtime SQL errors.
- **`@embed` + `@nillable` inconsistency** — when all columns of an `@embed` group are marked `@nillable`, the parent DTO property is now `?ClassName` and `fromRow` uses a conditional `isset($row['col']) ? Cls::fromRow($row) : null` cast, preventing invalid object hydration.

**Medium**

- **`DEFAULT` with apostrophe** — `SchemaParser` now correctly parses `DEFAULT 'it''s ok'`, handling escaped single quotes inside DEFAULT string values. Previously the parser truncated at the backslash, causing subsequent columns to potentially be misread.
- **`PRIMARY KEY` implies `NOT NULL`** — columns declared as `PRIMARY KEY` or `AUTO_INCREMENT` are now marked `nullable = false` regardless of whether `NOT NULL` is written explicitly. Previously `id INT PRIMARY KEY` produced `?int $id` instead of `int $id`.
- **First `@group` wins** — if a query has multiple `@group` annotations, the first one now takes precedence. Previously the last one won.
- **`@optional` before `WHERE` throws** — the analyzer now validates that `@optional` params appear only in `WHERE` clauses. If a param appears in `SELECT` or another non-WHERE position, a `RuntimeException` is thrown at generation time instead of silently generating invalid SQL at runtime.
- **`isActive` / `hasRole` prefix resolution** — `ParamResolver` now strips common boolean prefixes (`is_`, `has_`, `can_`, `was_`, `will_`) when looking up column names, so `:isActive` correctly resolves to the `active` column's type instead of falling back to `mixed`.
- **`@embed` without prefix throws** — `QueryParser` now throws `RuntimeException` when `@embed ClassName` is declared without a prefix argument, instead of silently generating an embed that matches no columns.

**Tests** — 28 new regression tests in `tests/BugFixTest.php`.

### [1.5.1]
- **`doctrine/inflector` integration** — class name inference now uses [doctrine/inflector](https://github.com/doctrine/inflector) for accurate singularisation and PascalCase conversion. Fixes incorrect singularisation of irregular English plurals (`analyses` → `Analysis`, `matrices` → `Matrix`, `aliases` → `Alias`) that the previous built-in implementation got wrong.
- **`language` config option** — new optional global field in `sqlc.yaml`. Accepts `english` (default), `spanish`, `french`, `portuguese`, `norwegian-bokmal`, `turkish`. Can be overridden per target. Enables accurate class name inference for non-English table names without requiring `@group` annotations on every query.
- **`InflectorService`** — new class (`src/Inflector/InflectorService.php`) wrapping doctrine/inflector with a built-in English fallback for environments where the package is not installed. Transparent — no exceptions thrown when the dependency is absent.
- **`composer.json`** — added `"doctrine/inflector": "^2.0"` to `require`.
- **26 new tests** in `tests/InflectorServiceTest.php` covering all six supported languages, the fallback behaviour, Config parsing, QueryParser group inference, and EnumGenerator class naming.

### [1.5.0]
- **`@embed` — nested objects for JOIN results** — `-- @embed ClassName prefix_` groups all result columns whose alias starts with `prefix_` into a nested `readonly` value object instead of flattening them into the parent DTO. Multiple `@embed` annotations can be stacked on one query, each producing a separate file. The embedded class implements `fromRow(array $row): self` using the original prefixed column names from the flat PDO row, so no extra queries or joins are needed at runtime.
- **`EmbedDefinition`** — new value object (`src/Parser/EmbedDefinition.php`) carrying `className`, `prefix`, and helpers `propertyName()`, `matches()`, `stripPrefix()`.
- **`EmbedGenerator`** — new generator (`src/Generator/EmbedGenerator.php`) that produces the standalone `readonly class` files for each `@embed` group.
- **`ResultDtoGenerator`** updated to partition result columns into embed groups and flat remainder; the `generate()` return shape gains an `embeds` key listing generated value-object files.
- **`QueryAnalyzer`** updated so that any `@embed` on a query forces `returnsModelDirectly = false`, guaranteeing a custom DTO is always generated.
- **`QueryParser`** updated to parse `@embed ClassName prefix_` annotations; prefix is normalised (trailing underscore always present).
- **26 new tests** in `tests/EmbedTest.php` covering `EmbedDefinition`, `QueryParser`, `QueryAnalyzer`, `EmbedGenerator`, `ResultDtoGenerator`, and `QueryGenerator`.

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