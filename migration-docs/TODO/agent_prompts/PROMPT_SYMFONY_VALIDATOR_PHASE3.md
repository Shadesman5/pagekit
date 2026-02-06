# Symfony Validator Migration - Phase 3: Extension/Package Migration

## Task

**Self-discover AND migrate** all remaining entities and controllers in **packages** to Symfony Validator.

### Prerequisites

**1. Pull latest changes first:**
```bash
git pull origin cursor/symfony-validation-hybrid-mode-6b05
```

**2. Review previous work:**
- Phase 1: `app/system/modules/user` (User, Role) ✅
- Phase 2: `app/system/modules/site`, `app/system/modules/widget` (Node, Page, Widget) ✅
- See: `migration-docs/branches/VALIDATION_SYSTEM.md`

### Discovery & Migration

**Search in:** `packages/pagekit/` (all extension packages, e.g., `blog`, etc.)

**1. Self-discover:**
- Entities without `#[Assert\...]` attributes (required fields, formats, length, enums, unique)
- Controllers with `App::abort(400, ...)` or manual validation (no `ValidatesRequestTrait`)

**2. Migrate (same pattern as Phase 2):**
- Add `#[Assert\...]` attributes to entity properties
- Extract validation messages to `app/system/languages/en_US/validation.php`
- Replace manual validation in controllers with `ValidatesRequestTrait`
- Remove old manual validation code (Rule #4: DELETE OVER WRAP)
- Follow same validation patterns as Phase 1 + 2

**3. Safety checks (after each change):**
- `curl http://localhost:8000` (must return 200, not 500)
- `curl http://localhost:8000/admin` (must load)

### Output
 (document what was migrated)

**Update:** `CHANGELOG-2025.md` with Phase 3 changes

### References

- `migration-docs/branches/VALIDATION_SYSTEM.md`
- `app/system/src/Controller/ValidatesRequestTrait.php`
- `app/system/languages/en_US/validation.php`
- Phase 2 example: `app/system/modules/site/src/Model/Node.php`
