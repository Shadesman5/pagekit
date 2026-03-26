# Step 2.1.7: QueryBuilder API Standardization

**ROADMAP:** 2.1.7. GitHub Issue: #154. Reference: `@ROADMAP.md`.

---

## CONTEXT

- **Depends on:** Step 2.1.2 (CI/CD). Can run **in parallel** with Steps 2.1.3–2.1.6.
- **Risk:** Low — internal API only, all call sites updated in same step
- **Current state:**
  - `Pagekit\Database\Query\QueryBuilder` has a public `execute()` method that wraps internal `executeQuery()` (protected)
  - `executeQuery()` internally calls `Connection::executeQuery()` for SELECT and `Connection::executeStatement()` for UPDATE/DELETE
  - ~5 call sites use `->execute()` in production code (ORM QueryBuilder, NodeModelTrait, UniqueValidator, PostModelTrait)
  - Call sites already use DBAL 3 Result API (`fetchAllAssociative`, `fetchOne`) in some places

**Goal:** Make `executeQuery()`/`executeStatement()` the public API, remove the legacy `execute()` wrapper. Align with Doctrine DBAL 3 conventions.

---

## 0. SAFETY CHECKS (CRITICAL)

**AFTER EVERY LOGICAL CHANGE:**
```bash
./app/vendor/bin/phpunit
```
**IF ANY FAILS → STOP AND FIX!**

**Before starting:** Branch from `develop` (Step 2.1.2 merged).

---

## 1. PREPARATION

### 1.1. Discovery

```bash
# Find ALL execute() call sites on QueryBuilder
rg '->execute\(' app/modules/database/ app/system/ packages/ --type php -n

# Find the Pagekit QueryBuilder class
rg 'class QueryBuilder' app/modules/database/ --type php -l

# Find ORM QueryBuilder
rg 'class QueryBuilder' app/modules/database/src/ORM/ --type php -l

# Check current visibility of executeQuery
rg 'function executeQuery' app/modules/database/src/Query/QueryBuilder.php
```

### 1.2. Understand the current API

**`Pagekit\Database\Query\QueryBuilder`** (`app/modules/database/src/Query/QueryBuilder.php`):
- `public execute($columns = ['*']): Result` — current public API, delegates to protected `executeQuery()`
- `protected executeQuery($type = 'select')` — internal, calls `Connection::executeQuery()` or `Connection::executeStatement()`

**`Pagekit\Database\ORM\QueryBuilder`** (`app/modules/database/src/ORM/QueryBuilder.php`):
- Wraps `$this->query` (the SQL QueryBuilder)
- Calls `$this->query->execute()` internally

---

## 2. MAKE `executeQuery()` AND `executeStatement()` PUBLIC

### 2.1. Split the protected method

The current `executeQuery($type)` handles both SELECT and UPDATE/DELETE via a `$type` parameter. Split into two clear public methods:

```php
public function executeQuery(): Result
{
    $sql = $this->getSQLForSelect();
    return $this->connection->executeQuery($sql, $this->params, $this->guessParamTypes($this->params));
}

public function executeStatement(): int
{
    // For UPDATE/DELETE — return affected row count
    $sql = match ($this->type) {
        'update' => $this->getSQLForUpdate(),
        'delete' => $this->getSQLForDelete(),
        default => throw new \LogicException('executeStatement() is only for UPDATE/DELETE queries'),
    };
    return $this->connection->executeStatement($sql, $this->params, $this->guessParamTypes($this->params));
}
```

**⚠️ Return types matter:**
- `executeQuery()` returns `Doctrine\DBAL\Result` (for SELECT)
- `executeStatement()` returns `int` (affected rows, for UPDATE/DELETE/INSERT)

### 2.2. Verify internal `$type` tracking

Check how the QueryBuilder tracks whether it's building a SELECT, UPDATE, or DELETE. The `executeStatement()` method needs to know which SQL to generate.

---

## 3. UPDATE ALL CALL SITES

### 3.1. Known call sites

| File | Current | New |
|------|---------|-----|
| `app/modules/database/src/ORM/QueryBuilder.php` (line ~59) | `$this->query->execute()` | `$this->query->executeQuery()` |
| `app/modules/database/src/ORM/QueryBuilder.php` (line ~104) | `$this->query->execute()` | Context-dependent: `executeQuery()` or `executeStatement()` |
| `app/system/modules/site/src/Model/NodeModelTrait.php` | `->execute()->fetchOne()` | `->executeQuery()->fetchOne()` |
| `app/system/src/Validator/Constraints/UniqueValidator.php` | `$queryBuilder->execute()` | `$queryBuilder->executeQuery()` |
| `packages/pagekit/blog/src/Model/PostModelTrait.php` | `->execute()->fetchAllAssociative()` | `->executeQuery()->fetchAllAssociative()` |

**⚠️ Each call site must be individually verified:**
- Is this a SELECT → `executeQuery()`
- Is this an UPDATE/DELETE → `executeStatement()`

### 3.2. Check for Result API usage

After `executeQuery()`, callers must use DBAL 3 Result methods:
- `->fetchAllAssociative()` (not `->fetchAll(PDO::FETCH_ASSOC)`)
- `->fetchAssociative()` (not `->fetch(PDO::FETCH_ASSOC)`)
- `->fetchOne()` (not `->fetchColumn()`)
- `->rowCount()` (only valid after `executeStatement()`)

Most call sites already use the new API. Verify each one.

---

## 4. DELETE OLD `execute()` METHOD

After all call sites are updated:

```bash
# Verify zero remaining execute() calls on QueryBuilder
rg '->execute\(' app/ packages/ --type php -n | grep -v 'executeQuery\|executeStatement\|Connection\|PDO'
```

**DELETE** the `execute()` method from `Pagekit\Database\Query\QueryBuilder`. No deprecation layer (Rule 4: DELETE OVER WRAP).

Also delete the old protected `executeQuery($type)` if it was replaced by the two new public methods.

---

## 5. UPDATE TESTS

- Update any tests that call `->execute()` on QueryBuilder
- Add tests for `executeQuery()` and `executeStatement()` if not covered
- Verify return types in tests (`Result` vs `int`)

---

## SUCCESS CRITERIA

- `executeQuery()` and `executeStatement()` are the public API
- Old `execute()` method deleted
- All call sites updated
- Return types match DBAL 3 conventions (`Result` for queries, `int` for statements)
- Standard Doctrine docs are applicable
- All PHPUnit tests pass

---

## VALIDATION CHECKLIST

- [ ] `executeQuery()` is public, returns `Result`
- [ ] `executeStatement()` is public, returns `int`
- [ ] Old `execute()` method deleted
- [ ] All call sites updated (zero `->execute()` on QueryBuilder)
- [ ] Result API methods used correctly at all call sites
- [ ] All PHPUnit tests pass
