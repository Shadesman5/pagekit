# TASK: Vue.js Template Pre-compilation (Gold Standard CSP)

=================================================================================
CONTEXT: Position in Overall Modernization Plan
=================================================================================

This is Step 3.2.5 (Template Pre-compilation) of the Pagekit modernization plan.
- ✅ Previous steps (1.1-1.13.5, 3.1-3.2) should be completed
- ✅ Step 1.13.5 implemented CSP with `unsafe-eval` (required for Vue runtime templates)
- ⏳ This step removes `unsafe-eval` by pre-compiling ALL Vue templates
- 📋 Your task: Pre-compile all Vue template strings to achieve true Gold Standard CSP

Focus: This is ONE focused task. Complete it thoroughly, but don't expand scope.

=================================================================================
PHILOSOPHY: "Spielwiese" - Doing It Right The First Time
=================================================================================

WHY REMOVE `unsafe-eval`?

Context:
- Step 1.13.5 implemented strict CSP but requires `unsafe-eval`
- Vue.js 2.x uses `new Function()` for runtime template compilation
- Components with `template: '...'` strings trigger this requirement
- True Gold Standard CSP = NO `unsafe-inline` AND NO `unsafe-eval`

Current State (from 1.13.5):
```apache
script-src 'self' 'unsafe-eval' https://www.google.com/recaptcha/ ...
```

Target State (after this step):
```apache
script-src 'self' https://www.google.com/recaptcha/ ...
```

Decision: Pre-compile ALL template strings during build process!

=================================================================================
AGGRESSIVE MODERNIZATION RULES
=================================================================================

1. NO COMPATIBILITY LAYERS – do not keep old & new behavior in parallel.
2. NO ADAPTERS – update all call sites instead of adding wrappers.
3. BREAKING CHANGES ALLOWED INTERNALLY – as long as public behavior (HTTP/API) stays the same.
4. DELETE OVER WRAP – if old logic conflicts with the new security model, delete it.
5. LEGACY HACKS MUST BE MARKED – use `// TODO: Must be refactored later` for unavoidable hacks.
6. HONEST COMMENTS – Comments must reflect reality. Mark backward compatibility clearly.

=================================================================================
PROBLEM ANALYSIS
=================================================================================

Components with runtime template strings that require `unsafe-eval`:

1. **Installer** (`app/installer/app/views/installer.vue`):
   - `installer-steps` component: `template: '<validation-observer ...>...'`
   - `buttons` component: `template: '<div class="uk-card-footer">...'`

2. **Admin Components** (search for `template:` in .vue and .js files):
   ```bash
   grep -r "template:" app/system/app/ --include="*.vue" --include="*.js"
   grep -r "template:" app/modules/ --include="*.vue" --include="*.js"
   grep -r "template:" packages/ --include="*.vue" --include="*.js"
   ```

3. **Potential locations**:
   - `app/system/app/components/`
   - `app/system/modules/*/app/`
   - `packages/pagekit/*/app/`

=================================================================================
PHASE 1: Audit All Template Strings
=================================================================================

1.1. Find ALL components with runtime templates:
   ```bash
   # Find template strings in Vue components
   grep -rn "template:\s*['\`\"]" app/ packages/ --include="*.vue" --include="*.js"
   
   # Find template strings in compiled bundles (already compiled = OK)
   grep -rn "template:\s*['\`\"]" app/*/bundle/ packages/*/bundle/
   ```

1.2. Categorize findings:
   - **INLINE**: `template: '...'` inside component definition → MUST FIX
   - **SFC**: `.vue` files with `<template>` section → OK (webpack compiles these)
   - **BUNDLE**: Already in bundle/*.js → Check if pre-compiled

1.3. Document all components needing migration:
   Create checklist of files to modify.

=================================================================================
PHASE 2: Convert Template Strings to SFC or Render Functions
=================================================================================

OPTION A: Convert to Single File Components (.vue)
-------------------------------------------------

For complex templates, extract to separate .vue files:

BEFORE (`installer.vue`):
```javascript
components: {
    'installer-steps': {
        props: ['current', 'steps'],
        template: `
            <validation-observer tag="div" class="tm-slider" v-slot="{ valid, passes }" slim>
                <template v-for="(step, index) in steps">
                    <transition name="slide">
                        <slot :name="step" :step="step" :valid="valid" :passes="passes" v-if="step === current" />
                    </transition>
                </template>
            </validation-observer>`,
        components: { ValidationObserver }
    }
}
```

AFTER (new file `installer-steps.vue`):
```vue
<template>
    <validation-observer tag="div" class="tm-slider" v-slot="{ valid, passes }" slim>
        <template v-for="(step, index) in steps">
            <transition name="slide">
                <slot :name="step" :step="step" :valid="valid" :passes="passes" v-if="step === current" />
            </transition>
        </template>
    </validation-observer>
</template>

<script>
import { ValidationObserver } from '@system/app/components/validation.vue';

export default {
    name: 'installer-steps',
    props: ['current', 'steps'],
    components: { ValidationObserver }
};
</script>
```

Then import in `installer.vue`:
```javascript
import InstallerSteps from './installer-steps.vue';

export default {
    components: {
        'installer-steps': InstallerSteps,
        // ...
    }
}
```

OPTION B: Convert to Render Functions
-------------------------------------

For simple templates, use render functions:

BEFORE:
```javascript
buttons: {
    props: ['prev', 'next'],
    template: `<div class="uk-card-footer">
        <a v-if="prev" @click="prev()">Back</a>
        <a v-if="next" @click="next()">Next</a>
    </div>`
}
```

AFTER:
```javascript
buttons: {
    props: ['prev', 'next'],
    render(h) {
        return h('div', { class: 'uk-card-footer' }, [
            this.prev ? h('a', { on: { click: this.prev } }, 'Back') : null,
            this.next ? h('a', { on: { click: this.next } }, 'Next') : null
        ]);
    }
}
```

=================================================================================
PHASE 3: Webpack Configuration
=================================================================================

3.1. Ensure vue-loader is configured for template compilation:
   File: `webpack.config.js`
   
   Check that vue-loader compiles templates:
   ```javascript
   module: {
       rules: [
           {
               test: /\.vue$/,
               loader: 'vue-loader',
               options: {
                   // Templates should be pre-compiled (default behavior)
                   compilerOptions: {
                       // Optional: whitespace handling
                       preserveWhitespace: false
                   }
               }
           }
       ]
   }
   ```

3.2. Verify bundle output has NO runtime template compilation:
   After rebuild, check bundles don't contain template strings:
   ```bash
   # Should NOT find template strings in bundles
   grep "template:" app/installer/app/bundle/installer.js | head -5
   ```
   
   Instead, should see `render` functions:
   ```bash
   # Should find render functions
   grep "render:function" app/installer/app/bundle/installer.js | head -5
   ```

=================================================================================
PHASE 4: Update CSP (Remove unsafe-eval)
=================================================================================

4.1. Update .htaccess:
   File: `.htaccess`
   
   BEFORE:
   ```apache
   Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-eval' https://www.google.com/recaptcha/ ...
   ```
   
   AFTER:
   ```apache
   Header set Content-Security-Policy "default-src 'self'; script-src 'self' https://www.google.com/recaptcha/ ...
   ```

4.2. Update documentation:
   - Remove notes about `unsafe-eval` requirement
   - Document that all templates are pre-compiled
   - Update `feature-template-security-hardening.md`

=================================================================================
PHASE 5: Testing
=================================================================================

5.1. Rebuild all bundles:
   ```bash
   npm run compile-js -- --mode=production
   ```

5.2. Test in browser with strict CSP:
   ```bash
   # Start server
   php -S localhost:8080 index.php
   
   # Open browser, check console for CSP violations
   # Should see ZERO violations!
   ```

5.3. Test installer (fresh install):
   ```bash
   rm config.php pagekit.db
   # Open http://localhost:8080/installer
   # Complete installation wizard
   # All steps should work without CSP errors
   ```

5.4. Test admin interface:
   - Login to admin
   - Check all Vue components render
   - No console errors

5.5. Run E2E tests:
   ```bash
   ./scripts/e2e-reset.sh
   ./scripts/e2e-start.sh
   npx playwright test
   ./scripts/e2e-stop.sh
   ```

=================================================================================
SUCCESS CRITERIA (True Gold Standard CSP!)
=================================================================================

MUST HAVE ✅:
- ✅ ALL template strings converted to SFC or render functions
- ✅ NO `template: '...'` strings in component definitions
- ✅ Bundles contain only pre-compiled render functions
- ✅ CSP without `unsafe-eval` in script-src
- ✅ All existing functionality works
- ✅ Installer wizard works completely
- ✅ Admin interface works completely
- ✅ No console errors
- ✅ No CSP violations
- ✅ E2E tests pass

MUST NOT ❌:
- ❌ NO runtime template compilation
- ❌ NO `unsafe-eval` in CSP
- ❌ NO breaking changes to user-facing functionality

=================================================================================
IMPORTANT NOTES FOR AGENT
=================================================================================

1. **Depends on Vue 2.7 Bridge (Step 3.2)**:
   Vue 2.7 has better tooling support. Ensure 3.2 is complete first.

2. **Focus on Installer First**:
   The installer is the most critical and has known template strings.
   Fix it first, then audit other components.

3. **Use SFC for Complex Templates**:
   Don't try to convert everything to render functions.
   SFCs are cleaner and webpack handles compilation.

4. **Test After Each Component**:
   After converting each component, rebuild and test.
   Don't batch all changes - find issues early.

5. **CSP Testing Command**:
   ```bash
   # Quick CSP test
   curl -I http://localhost:8080 | grep -i content-security
   # Should NOT contain 'unsafe-eval'
   ```

6. **Browser DevTools**:
   - Open Network tab, reload page
   - Check Response Headers for CSP
   - Console should show NO CSP violations

=================================================================================
REFERENCE: Components Known to Have Template Strings
=================================================================================

From Step 1.13.5 analysis:

1. `app/installer/app/views/installer.vue`:
   - `installer-steps` component (line ~458-472)
   - `buttons` component (line ~435-456)

2. Potentially others (run audit in Phase 1)

=================================================================================
EXPECTED OUTCOME
=================================================================================

After completing this task:
- Pagekit achieves TRUE Gold Standard CSP
- No `unsafe-inline` (achieved in 1.13.5)
- No `unsafe-eval` (achieved in this step)
- Maximum protection against XSS attacks
- Ready for security audit
- Modern best practices fully implemented
