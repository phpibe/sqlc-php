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

Clone or unzip the project, then run the CLI from your project root:

```bash
php /path/to/sqlc-php/bin/sqlc-php sqlc.yaml
```

---

## Configuration — `sqlc.yaml`

```yaml
version: "1"
schema:  schema.sql    # path to your CREATE TABLE file(s)
queries: queries.sql   # path to your annotated SQL queries

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

### Type override precedence

| Priority | Rule | Description |
|---|---|---|
| 1 | `column` | Exact `table.column` match — wins over everything |
| 2 | `db_type` | Matches any column whose SQL type matches |
| 3 | Default | Built-in SQL → PHP type mapping |

---

## Annotating queries

Every query must have three annotations, written as SQL comments:

```sql
-- @name  MethodName     required — generates the PHP method name (camelCase)
-- @group ClassName      optional — groups methods into a class; inferred from the FROM table if omitted
-- @returns :many        required — :many | :one | :opt | :exec
```

An optional `@param` annotation lets you override the inferred type of a named parameter:

```sql
-- @param paramName table.column
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
public function getUser(?int $id): User          // throws RuntimeException if missing

/** @return User|null */
public function getUserByEmail(string $email): ?User   // returns null if missing
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
        public int                    $total_users,   // COUNT → int, never null
        public ?int                   $total_active,  // SUM   → ?int (null on empty set)
        public ?float                 $avg_role,      // AVG   → ?float
        public ?\DateTimeImmutable    $last_signup,   // MAX   → nullable, type from column
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
    public function getUser(?int $id): User { ... }         // :one — throws

    /** @return User|null */
    public function getUserByEmail(string $email): ?User { ... }  // :opt — nullable

    public function deleteUser(?int $id): void { ... }      // :exec
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
```

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
| Config | `tests/Config/ConfigTest.php` | YAML parsing, defaults, type_overrides, TypeOverride matching |
| Param Resolver | `tests/Resolver/ParamResolverTest.php` | WHERE/SET resolution, camelCase→snake, fallback |
| Expression Resolver | `tests/Resolver/ExpressionTypeResolverTest.php` | COUNT/SUM/AVG/MIN/MAX/COALESCE/CAST/CASE alias and type |
| Analyzer | `tests/Analyzer/QueryAnalyzerTest.php` | Full pipeline: model detection, JOIN DTOs, aggregates |
| Generator | `tests/Generator/GeneratorTest.php` | Generated code structure, docblock indentation, PDO bindings |

---
