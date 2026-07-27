#!/usr/bin/env node
/**
 * Pagekit E2E Test Codebase Analyzer
 *
 * Scans the codebase to identify:
 * - Forms and inputs in PHP views
 * - Vue components with interactive elements
 * - Routes and their controllers
 * - Missing data-testid attributes
 *
 * Usage: node scripts/analyze-codebase.js [module]
 * Example: node scripts/analyze-codebase.js user
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const ROOT = process.cwd();

// Analysis results
const results = {
  modules: [],
  forms: [],
  vueComponents: [],
  routes: [],
  missingTestIds: []
};

/**
 * Find all module index.php files
 */
function findModules() {
  const patterns = [
    'app/modules/*/index.php',
    'app/system/modules/*/index.php',
    'packages/pagekit/*/index.php'
  ];

  const modules = [];

  patterns.forEach(pattern => {
    try {
      const cmd =
        process.platform === 'win32'
          ? `dir /s /b ${pattern.replace(/\//g, '\\')} 2>nul`
          : `find . -path "./${pattern}" -type f 2>/dev/null`;

      const output = execSync(cmd, { cwd: ROOT, encoding: 'utf8' });
      output
        .split('\n')
        .filter(Boolean)
        .forEach(file => {
          const normalized = file
            .replace(/\\/g, '/')
            .replace(ROOT.replace(/\\/g, '/'), '')
            .replace(/^\.?\//, '');
          modules.push(normalized);
        });
    } catch {
      // Pattern not found
    }
  });

  return modules;
}

/**
 * Parse module index.php for routes
 */
function parseModuleRoutes(indexPath) {
  try {
    const content = fs.readFileSync(path.join(ROOT, indexPath), 'utf8');
    const routes = [];

    // Simple regex to find routes array
    const routeMatch = content.match(/'routes'\s*=>\s*\[([\s\S]*?)\]/);
    if (routeMatch) {
      const routePatterns = routeMatch[1].matchAll(
        /'([^']+)'\s*=>\s*\[[\s\S]*?'name'\s*=>\s*'([^']+)'/g
      );
      for (const match of routePatterns) {
        routes.push({
          path: match[1],
          name: match[2],
          module: indexPath.split('/').slice(-2, -1)[0]
        });
      }
    }

    return routes;
  } catch {
    return [];
  }
}

/**
 * Find forms in PHP views
 */
function findFormsInViews(modulePath) {
  const viewsPath = modulePath.replace('index.php', 'views');
  const forms = [];

  try {
    if (!fs.existsSync(path.join(ROOT, viewsPath))) return forms;

    const files = fs.readdirSync(path.join(ROOT, viewsPath), { recursive: true });

    files.forEach(file => {
      if (!file.endsWith('.php')) return;

      const filePath = path.join(viewsPath, file);
      const content = fs.readFileSync(path.join(ROOT, filePath), 'utf8');

      // Find forms
      const formMatches = content.matchAll(/<form[^>]*>/gi);
      for (const match of formMatches) {
        const hasTestId = match[0].includes('data-testid');
        const actionMatch = match[0].match(/action="([^"]*)"/);

        forms.push({
          file: filePath,
          action: actionMatch ? actionMatch[1] : 'N/A',
          hasTestId
        });
      }

      // Find inputs without data-testid
      const inputMatches = content.matchAll(/<input[^>]+>/gi);
      for (const match of inputMatches) {
        if (!match[0].includes('data-testid') && !match[0].includes('type="hidden"')) {
          const nameMatch = match[0].match(/name="([^"]*)"/);
          results.missingTestIds.push({
            file: filePath,
            type: 'input',
            name: nameMatch ? nameMatch[1] : 'unknown',
            suggested: generateTestId(filePath, nameMatch ? nameMatch[1] : 'input')
          });
        }
      }
    });
  } catch {
    // Views folder not found
  }

  return forms;
}

/**
 * Find Vue components with interactive elements
 */
function findVueComponents() {
  const components = [];

  try {
    const cmd =
      process.platform === 'win32'
        ? 'dir /s /b *.vue 2>nul'
        : 'find . -name "*.vue" -type f 2>/dev/null';

    const output = execSync(cmd, { cwd: ROOT, encoding: 'utf8' });
    const files = output.split('\n').filter(Boolean);

    files.forEach(file => {
      const normalized = file
        .replace(/\\/g, '/')
        .replace(ROOT.replace(/\\/g, '/'), '')
        .replace(/^\.?\//, '');

      try {
        const content = fs.readFileSync(path.join(ROOT, normalized), 'utf8');

        const hasVModel = content.includes('v-model');
        const hasClick = content.includes('@click') || content.includes('v-on:click');
        const hasSubmit = content.includes('@submit') || content.includes('v-on:submit');
        const hasTestId = content.includes('data-testid');

        if (hasVModel || hasClick || hasSubmit) {
          components.push({
            file: normalized,
            hasVModel,
            hasClick,
            hasSubmit,
            hasTestId,
            name: path.basename(normalized, '.vue')
          });

          // Check for missing test IDs on interactive elements
          if (!hasTestId && (hasVModel || hasSubmit)) {
            results.missingTestIds.push({
              file: normalized,
              type: 'vue-component',
              name: path.basename(normalized, '.vue'),
              suggested: generateTestId(normalized, path.basename(normalized, '.vue'))
            });
          }
        }
      } catch {
        // File read error
      }
    });
  } catch {
    // Find command failed
  }

  return components;
}

/**
 * Generate suggested data-testid
 */
function generateTestId(filePath, elementName) {
  const parts = filePath.split('/');
  const module = parts.find((p, i) => parts[i - 1] === 'modules') || 'app';
  const cleanName = elementName
    .replace(/\[|\]/g, '-')
    .replace(/[^a-z0-9-]/gi, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
    .toLowerCase();

  return `${module}-${cleanName}`;
}

/**
 * Main analysis
 */
function analyze() {
  console.log('🔍 Analyzing Pagekit codebase for E2E test coverage...\n');

  // Find modules
  const modules = findModules();
  console.log(`📦 Found ${modules.length} modules\n`);

  // Analyze each module
  modules.forEach(mod => {
    const routes = parseModuleRoutes(mod);
    results.routes.push(...routes);

    const forms = findFormsInViews(mod);
    results.forms.push(...forms);
  });

  // Find Vue components
  results.vueComponents = findVueComponents();

  // Output results
  console.log('📊 ANALYSIS RESULTS\n');
  console.log('─'.repeat(50));

  console.log('\n🛣️  ROUTES FOUND:', results.routes.length);
  results.routes.slice(0, 10).forEach(r => {
    console.log(`   ${r.path} → ${r.name} (${r.module})`);
  });
  if (results.routes.length > 10) {
    console.log(`   ... and ${results.routes.length - 10} more`);
  }

  console.log('\n📝 FORMS FOUND:', results.forms.length);
  results.forms.forEach(f => {
    const status = f.hasTestId ? '✅' : '⚠️';
    console.log(`   ${status} ${f.file}`);
  });

  console.log('\n🎨 VUE COMPONENTS (interactive):', results.vueComponents.length);
  results.vueComponents.slice(0, 10).forEach(c => {
    const status = c.hasTestId ? '✅' : '⚠️';
    const features = [c.hasVModel && 'v-model', c.hasClick && '@click', c.hasSubmit && '@submit']
      .filter(Boolean)
      .join(', ');
    console.log(`   ${status} ${c.name} (${features})`);
  });
  if (results.vueComponents.length > 10) {
    console.log(`   ... and ${results.vueComponents.length - 10} more`);
  }

  console.log('\n⚠️  MISSING data-testid:', results.missingTestIds.length);
  results.missingTestIds.slice(0, 15).forEach(m => {
    console.log(`   ${m.file}`);
    console.log(`      Suggested: data-testid="${m.suggested}"`);
  });
  if (results.missingTestIds.length > 15) {
    console.log(`   ... and ${results.missingTestIds.length - 15} more`);
  }

  console.log('\n' + '─'.repeat(50));
  console.log('📋 RECOMMENDATIONS:');
  console.log('   1. Add data-testid to forms and inputs in PHP views');
  console.log('   2. Add data-testid to Vue components with v-model');
  console.log('   3. Create test specs for each route');
  console.log('   4. Use selectors.md as reference for naming');

  // Write JSON report
  const reportPath = path.join(ROOT, 'tests/e2e/analysis-report.json');
  fs.writeFileSync(reportPath, JSON.stringify(results, null, 2));
  console.log(`\n💾 Full report saved to: ${reportPath}`);
}

// Run
analyze();
