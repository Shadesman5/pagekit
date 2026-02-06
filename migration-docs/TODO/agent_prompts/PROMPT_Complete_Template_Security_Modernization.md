TASK: Complete Template Security Modernization (Gold Standard Approach)

=================================================================================
CONTEXT: Position in Overall Modernization Plan
=================================================================================

This is Step 1.13.5 (Template Security Hardening) of the Pagekit modernization plan.
- ✅ Previous steps (1.1-1.13) are completed and merged
- ⏳ This step focuses ONLY on template security (eval() removal, CSP, data-attributes)
- ⏳ Future steps (1.14, 2.0+, etc.) will follow - DO NOT attempt them here
- 📋 Your task: Complete ONLY this specific security hardening step

Focus: This is ONE focused task. Complete it thoroughly, but don't expand scope.

=================================================================================
PHILOSOPHY: "Spielwiese" - Doing It Right The First Time
=================================================================================

WHY GOLD STANDARD DIRECTLY (No intermediate steps)?

Context:
- This is version 1.0.x - development/modernization phase
- NOT for production customers yet
- Version 2.0.0 will be the first production-ready release
- We have time to do it right!!!

Decision: Skip intermediate solutions (like nonce), go directly to best practice
- Avoids refactoring twice (nonce now → data-attributes later)
- "Spielwiese" allows experimentation with modern approaches
- Version 2.0 gets perfect security from day one

THIS STEP: Complete security modernization to industry best practices!

### AGGRESSIVE MODERNIZATION RULES (FROM STEP 1.13)

1. NO COMPATIBILITY LAYERS – do not keep old & new behavior in parallel.
2. NO ADAPTERS – update all call sites instead of adding wrappers.
3. BREAKING CHANGES ALLOWED INTERNALLY – as long as public behavior (HTTP/API) stays the same.
4. DELETE OVER WRAP – if old logic conflicts with the new security model, delete it.
5. LEGACY HACKS MUST BE MARKED – use `// TODO: Must be refactored later` for unavoidable hacks.
6. HONEST COMMENTS – Comments must reflect reality. If something IS backward compatibility, 
   mark it clearly with `// TODO: BACKWARD COMPATIBILITY - Must be refactored later` prefix so it can be refactored per rules 1-5. 
   Don't hide backward compatibility by removing the word from comments. Explain WHY code works 
   this way, not what it replaces (unless it's temporary backward compatibility that needs removal).

=================================================================================
TEMPLATE SYSTEM CLARIFICATION
=================================================================================

Pagekit currently uses:
- ✅ 100% PHP templates (.php files) - NOT Twig!
- ✅ 0 .twig files found (TwigEngineAdapter exists but unused)
- ✅ PHP templates work great for CMS (keep them!)
- ✅ Backend uses PHP + Vue.js (hybrid approach)
- ✅ Twig optional for developers who prefer it

Decision: Keep PHP templates, they're modern and working fine!

=================================================================================
PHASE 1: eval() Dead Code Removal
=================================================================================

1.1. Analyze current eval() usage:
   `bash
   cd c:/Projekte/pagekit
   grep -n "eval(" app/modules/view/src/PhpEngine.php
   grep -n "eval(" app/modules/view/src/Engine/PhpEngine.php
   `
   Expected: Lines 168, 174 in PhpEngine.php + Line 122 in Engine/PhpEngine.php

1.2. Remove eval() from app/modules/view/src/PhpEngine.php:
   File: app/modules/view/src/PhpEngine.php
   
   Line 164-176 BEFORE:
   `php
   if (file_exists($templatePath)) {
       require $templatePath;
   } else {
       // Treat as string template
       eval('?>' . $templatePath);  // ← REMOVE THIS!
   }
   `
   
   Line 164-176 AFTER:
   `php
   if (file_exists($templatePath)) {
       require $templatePath;
   } else {
       throw new \RuntimeException(sprintf('Template file not found: %s', $templatePath));
   }
   `
   
   Do the same for the second occurrence (line ~174)

1.3. Remove eval() from app/modules/view/src/Engine/PhpEngine.php:
   File: app/modules/view/src/Engine/PhpEngine.php
   
   Line 119-125 BEFORE:
   `php
   if (isset($storage['path']) && file_exists($storage['path'])) {
       require $storage['path'];
   } elseif (isset($storage['content'])) {
       eval('?>' . $storage['content']);  // ← REMOVE THIS!
   }
   `
   
   Line 119-125 AFTER:
   `php
   if (isset($storage['path']) && file_exists($storage['path'])) {
       require $storage['path'];
   } else {
       throw new \RuntimeException('Invalid storage: path not found or content not supported');
   }
   `

1.4. Test that system still works (NO fresh install!):
   `bash
   # Test existing installation
   curl http://localhost:8000 | grep -i "pagekit"
   # Should return 200 OK with "Pagekit" in HTML
   
   # Test admin access (check status code)
   curl -I http://localhost:8000/admin
   # Should return 302 (redirect to login) or 200 (if logged in)
   
   # Test CLI
   php pagekit --version
   # Should output version number

   # If no Installation detected
   php pagekit setup
   # Should install the System without a Password, for testings.
   `

1.5. Commit changes:
   `bash
   git add app/modules/view/src/PhpEngine.php app/modules/view/src/Engine/PhpEngine.php
   git commit -m "security: remove eval() dead code from template engines"
   `
=================================================================================
PHASE 2: Data-Attributes Implementation (Gold Standard!)
=================================================================================

Goal: Replace inline <script>var $pagekit = {...}</script> with clean data-attributes
Result: NO inline JavaScript at all! Perfect CSP!

IMPORTANT: 
- ✅ <script type="application/json"> is ALLOWED (data-only, CSP-safe!)
- ❌ <script> without type or type="text/javascript" is BLOCKED by strict CSP
- ✅ Our solution uses type="application/json" (perfect for CSP!)

2.1. Update DataHelper to use data-attributes:
   File: app/modules/view/src/Helper/DataHelper.php
   
   Update render() method (line ~71):
   
   BEFORE:
   `php
   public function render(): string
   {
       $output = '';
       foreach ($this->data as $name => $value) {
           $output .= sprintf("        <script>var %s = %s;</script>\n", $name, json_encode($value, $this->encodingOptions));
       }
       return $output;
   }
   `
   
   AFTER:
   `php
   public function render(): string
   {
       // Store config in data-attribute on body tag
       // JavaScript will read this later (no inline scripts!)
       $config = [
           'data' => $this->data
       ];
       
       // Output as data-attribute for JavaScript to consume
       $encoded = htmlspecialchars(json_encode($config, $this->encodingOptions), ENT_QUOTES, 'UTF-8');
       return sprintf("        <script id=\"pagekit-data\" type=\"application/json\" data-config='%s'></script>\n", $encoded);
   }
   `
   
   Explanation:
   - NO inline JavaScript execution!
   - Config stored in data-attribute
   - JavaScript reads it later from DOM
   - Perfect for strict CSP!

2.2. Create JavaScript config loader:
   File: app/assets/js/config-loader.js (NEW!)
   
   `javascript
   /**
    * Pagekit Config Loader
    * Reads configuration from data-attributes (no inline scripts!)
    */
   (function() {
       'use strict';
       
       // Read config from data-attribute
       var configScript = document.getElementById('pagekit-data');
       if (!configScript) {
           console.warn('Pagekit config not found');
           return;
       }
       
       try {
           var config = JSON.parse(configScript.getAttribute('data-config'));
           
           // Expose data as global variables (backward compatibility)
           if (config.data) {
               Object.keys(config.data).forEach(function(key) {
                   window[key] = config.data[key];
               });
           }
       } catch (e) {
           console.error('Failed to parse Pagekit config:', e);
       }
   })();
   `

2.3. Register config-loader in View system:
   File: app/modules/view/index.php or app/system/modules/view/index.php
   
   Find the 'view.scripts' event and add:
   `php
   $scripts->register('pagekit-config', 'app/assets/js/config-loader.js', [], ['defer' => false]);
   `
   
   Make sure it loads BEFORE other scripts that need the config!

2.4. Update head template to ensure proper order:
   Scripts must load in this order:
   1. pagekit-config.js (reads data-attributes)
   2. other scripts (use the config)
   
   The View system should handle this automatically via dependency management.

2.5. Test data-attribute implementation:
   `bash
   # Check HTML output
   curl http://localhost:8000 | grep 'data-config'
   # Should show: <script id="pagekit-data" type="application/json" data-config='{"data":{"$pagekit":{...}}}'></script>
   
   # Verify NO inline scripts (except the JSON data holder)
   curl http://localhost:8000 | grep '<script>' | grep -v 'data-config' | grep -v 'src='
   # Should return NOTHING (all scripts are external or data-attributes)
   `

2.6. Test JavaScript access:
   - Open http://localhost:8000 in browser
   - Open Console (F12)
   - Type: `console.log($pagekit)`
   - Should output config object (backward compatible!)

2.7. Commit changes:
   `bash
   git add app/modules/view/src/Helper/DataHelper.php app/assets/js/config-loader.js app/modules/view/index.php
   git commit -m "security: replace inline scripts with data-attributes (gold standard CSP)"
   `

IMPORTANT: This is the CLEAN solution!
- ✅ NO inline JavaScript execution
- ✅ Perfect CSP without exceptions
- ✅ Backward compatible (global vars still work)
- ✅ Modern best practice

=================================================================================
PHASE 3: Complete Security Headers Modernization
=================================================================================

3.1. Update .htaccess with STRICT CSP (no unsafe-* needed!):
   File: .htaccess (line ~48)
   
   BEFORE:
   `apache
   Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self';"
   `
   
   AFTER:
   `apache
   # Content Security Policy - STRICT (no unsafe-inline, no unsafe-eval!)
   # Since we use data-attributes (no inline scripts), we can be very strict
   Header set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self';"
   `
   
   Changes:
   - ✅ script-src: NO unsafe-inline, NO unsafe-eval (strict!)
   - ⚠️ style-src: keeps 'unsafe-inline' for now (UIkit inline styles, can fix later)
   - ✅ object-src 'none': No Flash/plugins
   - ✅ base-uri 'self': Prevent base tag injection
   - ✅ form-action 'self': Forms only submit to same origin

3.2. Add additional modern security headers:
   File: .htaccess (after CSP section)
   
   ADD:
   `apache
   # Additional Modern Security Headers
   
   # Cross-Origin-Embedder-Policy
   Header always set Cross-Origin-Embedder-Policy "require-corp"
   
   # Cross-Origin-Opener-Policy
   Header always set Cross-Origin-Opener-Policy "same-origin"
   
   # Cross-Origin-Resource-Policy
   Header always set Cross-Origin-Resource-Policy "same-origin"
   `

3.3. Update existing security headers (review and modernize):
   File: .htaccess (existing headers section ~22-49)
   
   Review and ensure all are optimal:
   - HSTS: Already good ✅
   - X-Content-Type-Options: Already good ✅
   - X-Frame-Options: Already good ✅
   - Referrer-Policy: Consider changing to "strict-origin-when-cross-origin" (more privacy)
   - Permissions-Policy: Review and add more restrictions if needed

3.4. Test CSP in browser:
   `bash
   # Start browser with developer tools
   # Open http://localhost:8000
   # Open Developer Console (F12) → Console tab
   `
   
   Check for:
   - ❌ NO CSP violations (all clean!)
   - ✅ All scripts load from external files
   - ✅ $pagekit variable available (from data-attributes)
   - ✅ Vue.js works
   - ✅ Admin interface works

3.5. Test with CSP validator:
   Visit: https://csp-evaluator.withgoogle.com/
   Paste your CSP policy
   Check for:
   - ✅ No unsafe-inline in script-src
   - ✅ No unsafe-eval
   - ✅ Score should be good (maybe warning about style-src, that's okay for now)

3.6. Commit changes:
   `bash
   git add .htaccess
   git commit -m "security: implement strict CSP and modern security headers"
   `

RESULT: Industry-leading security headers!
- ✅ Perfect CSP (no unsafe-* in script-src)
- ✅ Modern Cross-Origin policies
- ✅ HSTS, X-Frame-Options, etc. all optimal
- ✅ Ready for security audit

=================================================================================
PHASE 4: Testing & Documentation
=================================================================================

4.1. Run E2E Tests (with fresh install):
   `bash
   # Reset to clean state (removes config.php and database)
   ./scripts/e2e-reset.sh
   
   # Start test server
   ./scripts/e2e-start.sh
   
   # Test fresh installation
   npx playwright test tests/e2e/specs/01-setup/installation.spec.js
   
   # Test admin functionality
   npx playwright test tests/e2e/specs/02-admin/login.spec.js
   
   # Stop test server
   ./scripts/e2e-stop.sh
   `

4.2. Manual Testing Checklist:
   - [ ] Admin login works (http://localhost:8000/admin)
   - [ ] Dashboard loads without errors
   - [ ] Vue.js components render correctly
   - [ ] Frontend pages load (http://localhost:8000)
   - [ ] No console errors in browser (F12)
   - [ ] Inline scripts work (check $pagekit variable exists)
   - [ ] CSP violations only for expected/external sources

4.3. Create branch documentation:
   File: migration-docs/branches/feature-template-security-hardening.md
   
   Document:
   - All changes made
   - Why eval() was removed
   - How data-attributes implementation works (no inline scripts!)
   - Why Twig migration was deferred (Twig/Vue conflict)
   - Test results

4.4. Update CHANGELOG-2025.md:
   Add entry:
   `markdown
   ## [1.0.X] - [Date]
   
   ### Security
   - Remove eval() dead code from template engines
   - Implement data-attributes for config (no inline executable scripts!)
   - Harden Content Security Policy (remove unsafe-inline, unsafe-eval from script-src)
   `

=================================================================================
SUCCESS CRITERIA (Gold Standard!)
=================================================================================

MUST HAVE ✅:
- ✅ eval() completely removed from both PhpEngine files
- ✅ Data-attributes implementation (NO inline scripts!)
- ✅ config-loader.js loads config from data-attributes
- ✅ Backward compatibility: $pagekit, $debugbar globals still work
- ✅ Strict CSP without 'unsafe-inline' and 'unsafe-eval' in script-src
- ✅ Modern security headers (COEP, COOP, CORP)
- ✅ All existing functionality works (no breaking changes!)
- ✅ No console errors in browser
- ✅ No CSP violations
- ✅ E2E tests pass (fresh install + admin + frontend)
- ✅ Documentation complete

MUST NOT ❌:
- ❌ NO intermediate solutions (nonce, etc.)
- ❌ NO PHP template format changes
- ❌ NO breaking changes to existing API
- ❌ NO regression in functionality

=================================================================================
IMPORTANT NOTES FOR AGENT
=================================================================================

1. **"Spielwiese" Philosophy (BUT: Must Work!):**
   This version (1.0.x) is for DEVELOPMENT, not production!
   - Version 2.0.0 will be first customer-ready release
   - We have time to do it RIGHT, not just fast
   - Skip intermediate solutions → Go directly to Gold Standard
   - Background Agent makes this feasible (minutes not weeks!)
   
   ⚠️ CRITICAL: "Spielwiese" means experimentation is allowed, 
   BUT system MUST be functional after each step!
   - All existing features must work
   - No broken functionality
   - Thorough testing required before commit

2. **Why Data-Attributes (Gold Standard)?**
   Best practices hierarchy:
   1. Data-attributes (this step!) → ✅ GOLD STANDARD
   2. Nonce-based inline scripts → Good compromise (SKIPPED!)
   3. unsafe-inline → Bad (old state)
   
   Decision: Skip #2, go directly to #1
   - Avoids refactoring twice
   - Perfect CSP from day one
   - Modern best practice

3. **PHP Templates & <?= Syntax:**
   - Pagekit uses 100% PHP templates (.php files) - NOT Twig!
   - 0 .twig files found (TwigEngineAdapter exists but unused)
   - <?= is MODERN (PHP 5.4+, recommended by PSR-12)
   - Backend uses PHP + Vue.js (hybrid approach)
   - Keep PHP templates - they work great!

4. **Backward Compatibility:**
   Global variables MUST still work:
   - Old: <script>var $pagekit = {...}</script>
   - New: config-loader.js sets window.$pagekit = ...
   - Result: Existing code doesn't break!

5. **Test Commands:**
   `bash
   # Fresh install test:
   ./scripts/e2e-reset.sh  # Clean state first!
   npx playwright test tests/e2e/specs/01-setup/installation.spec.js
   
   # Existing installation test:
   curl http://localhost:8000 | grep 'data-config'  # Check data-attributes
   curl http://localhost:8000 | grep '<script>' | grep -v 'data-config' | grep -v 'src='  # Should be empty!
   `

6. **CSP Testing:**
   - Browser Console (F12) → Check for CSP violations
   - Should be ZERO violations!
   - Use https://csp-evaluator.withgoogle.com/ to validate policy
   - Target: A+ rating (except style-src, that's okay)
   
   Note: "Spielwiese" = experimentation allowed, but functionality must be verified!