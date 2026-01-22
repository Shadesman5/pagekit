# PR: Template Security Hardening (Step 1.13.5)

## Summary

This PR implements enhanced security for Pagekit's template system, achieving Content Security Policy compliance without `unsafe-inline` in script-src.

**Note:** `'unsafe-eval'` is still required because Vue.js 2.x uses `new Function()` for runtime template compilation. This will be addressed in Step 3.2.5 (Template Pre-compilation).

## Changes

### Security Improvements

1. **PHP eval() Removal**
   - Removed `eval()` from `app/modules/view/src/PhpEngine.php`
   - Removed `eval()` from `app/modules/view/src/Engine/PhpEngine.php`
   - All templates must now be file-based (no string execution)

2. **JSON Data Container (Replaces Inline Scripts)**
   - `DataHelper` now outputs `<script type="application/json">` instead of `<script>var...`
   - Created `config-loader.js` to read JSON and expose as global variables
   - Backward compatible: `$pagekit`, `$debugbar` globals still work

3. **Content Security Policy**
   - Hardened CSP in `.htaccess`
   - No `unsafe-inline` in script-src
   - `unsafe-eval` required for Vue.js (documented for Step 3.2.5)

4. **Modern Security Headers**
   - Added `Cross-Origin-Embedder-Policy: credentialless`
   - Added `Cross-Origin-Opener-Policy: same-origin`
   - Added `Cross-Origin-Resource-Policy: same-origin`
   - Upgraded `Referrer-Policy` to `strict-origin-when-cross-origin`

5. **Inline Script Migration**
   - Migrated `CaptchaListener` to use DataHelper
   - Migrated `Editor` module to use DataHelper
   - `ScriptHelper` now blocks inline scripts with warning

### Files Changed

| File | Change |
|------|--------|
| `app/modules/view/src/PhpEngine.php` | Remove eval() |
| `app/modules/view/src/Engine/PhpEngine.php` | Remove eval() |
| `app/modules/view/src/Helper/DataHelper.php` | JSON data container |
| `app/modules/view/src/Helper/ScriptHelper.php` | Block inline scripts |
| `app/system/app/lib/config-loader.js` | NEW - Read JSON config |
| `app/system/modules/view/index.php` | Register config-loader |
| `app/system/modules/captcha/src/CaptchaListener.php` | Migrate to DataHelper |
| `app/system/modules/editor/index.php` | Migrate to DataHelper |
| `.htaccess` | Strict CSP + security headers |

### Documentation

- Created `migration-docs/branches/feature-template-security-hardening.md`
- Updated `CHANGELOG-2025.md`
- Created `PROMPT_Vue_Template_Precompilation.md` for Step 3.2.5

## Testing

- [x] PHP server starts without errors
- [x] Installer wizard works (fresh install)
- [x] Admin login works
- [x] Dashboard loads with Vue components
- [x] No CSP violations for inline scripts
- [x] `$pagekit` global accessible in browser console

## Breaking Changes (Internal Only)

- String template execution no longer supported (was dead code)
- Inline `<script>` tags with executable JavaScript blocked by CSP

## Future Work (Step 3.2.5)

To achieve true Gold Standard CSP (no `unsafe-eval`):
- Pre-compile all Vue template strings during build
- Convert `template: '...'` to render functions or SFCs
- Remove `unsafe-eval` from CSP

## Related

- Closes Step 1.13.5 in modernization plan
- Prepares for Step 3.2.5 (Template Pre-compilation)
