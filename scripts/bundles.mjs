/**
 * Builds the entries of the bundle manifest with Vite.
 *
 * One `vite build` per entry: Rollup cannot emit `iife` for a multi-input build,
 * and the bundles are classic `<script>` tags, so each one has to be a
 * self-contained IIFE. Bundles are published as `<module>/app/bundle/<name>.js`,
 * which is the path PHP registers them under.
 */

import { createRequire } from 'node:module';
import { availableParallelism } from 'node:os';
import path from 'node:path';

import vue from '@vitejs/plugin-vue2';
import { build } from 'vite';

import { aliases, entries, externals } from './bundle-entries.mjs';
import { published, root } from './paths.mjs';

const require = createRequire(import.meta.url);

/** Holds the exports of a bundle that publishes no global; never read. */
const LOCAL_IIFE_NAME = '__pagekitBundle';

const resolveAliases = [
  ...Object.entries(aliases).map(([find, target]) => ({
    find,
    replacement: path.join(root, target)
  })),
  // vue-nestable 2.6 declares a `module` entry it never ships, and both of its
  // unbundled builds require the undeclared `vue-runtime-helpers`. The bundled
  // UMD build is the only self-contained one.
  {
    find: /^vue-nestable$/,
    replacement: require.resolve('vue-nestable/dist/index.umd.min.js')
  }
];

/** Every entry is a full Vite build, so the pool is capped even on large machines. */
function defaultConcurrency() {
  return Math.min(availableParallelism(), 8);
}

/**
 * @param {import('./bundle-entries.mjs').BundleEntry} entry
 * @param {boolean} watch
 * @returns {import('vite').InlineConfig}
 */
function viteConfig(entry, watch) {
  return {
    configFile: false,
    root,
    mode: 'production',
    logLevel: 'warn',
    clearScreen: false,
    publicDir: false,
    // Classic scripts have no `process`; libraries branching on it need the
    // constant folded away before minification.
    define: { 'process.env.NODE_ENV': JSON.stringify('production') },
    resolve: { alias: resolveAliases },
    plugins: [vue()],
    build: {
      // UIkit 3.5 browser floor (Safari 11.1 lacks full ES2018 regex support).
      target: 'es2017',
      // The published bundle directory, which mirrors the module path below
      // the webroot; the entry sources stay outside it.
      outDir: path.dirname(published(path.join(entry.dir, entry.output))),
      // Every entry of a module writes into the same bundle directory.
      emptyOutDir: false,
      modulePreload: false,
      reportCompressedSize: false,
      sourcemap: false,
      minify: 'esbuild',
      watch: watch ? {} : null,
      rollupOptions: {
        input: path.join(root, entry.dir, entry.input),
        external: Object.keys(externals),
        // Entry exports must survive: an SFC only gets its render function
        // attached by the (pure-annotated) normalizer call that produces
        // the default export, and components register themselves before
        // that call runs.
        preserveEntrySignatures: 'strict',
        output: {
          format: 'iife',
          name: entry.global ?? LOCAL_IIFE_NAME,
          // Mirrors the `{ default, __esModule }` shape the classic
          // bundles exposed, which cross-bundle registrations rely on.
          exports: entry.global ? 'named' : 'auto',
          globals: externals,
          entryFileNames: path.basename(entry.output),
          inlineDynamicImports: true,
          // An IIFE with exports needs a name to assign them to, which
          // would be a new global for bundles that never published one.
          // An extra function scope keeps that assignment local.
          banner: entry.global ? '' : '(function(){',
          footer: entry.global ? '' : '})();'
        }
      }
    }
  };
}

/**
 * Fails on anything but the single expected bundle file: stray CSS or asset
 * emits would land in the bundle directory and go unnoticed otherwise.
 *
 * @param {string} expected
 * @param {import('rollup').RollupOutput} result
 */
function assertSingleBundle(expected, result) {
  const unexpected = result.output
    .map(output => output.fileName)
    .filter(fileName => fileName !== expected);

  if (unexpected.length) {
    throw new Error(`Unexpected build output: ${unexpected.join(', ')}`);
  }
}

/**
 * @param {import('./bundle-entries.mjs').BundleEntry} entry
 */
async function buildEntry(entry) {
  assertSingleBundle(path.basename(entry.output), await build(viteConfig(entry, false)));
}

/**
 * Starts a watcher and resolves once its first build has finished, so watchers
 * come up one after another instead of all compiling at once. A first build
 * that fails rejects: there is no bundle for the watcher to keep up to date.
 *
 * @param {import('./bundle-entries.mjs').BundleEntry} entry
 * @param {import('rollup').RollupWatcher[]} started collects the watchers that
 *   came up, so a failed startup can shut them down again
 */
async function watchEntry(entry, started) {
  const bundle = path.join(entry.dir, entry.output);
  const watcher = await build(viteConfig(entry, true));
  let firstBuild = true;

  started.push(watcher);

  return new Promise((resolve, reject) => {
    watcher.on('event', event => {
      const failed = event.code === 'ERROR';

      if (!failed && event.code !== 'END') {
        return;
      }

      if (firstBuild) {
        firstBuild = false;

        if (failed) {
          reject(event.error);
        } else {
          resolve();
        }
      } else if (failed) {
        console.error(`failed ${bundle}: ${event.error.message}`);
      } else {
        console.log(`rebuilt ${bundle}`);
      }
    });
  });
}

/**
 * @param {import('./bundle-entries.mjs').BundleEntry[]} queue
 * @param {number} concurrency
 * @param {(entry: import('./bundle-entries.mjs').BundleEntry) => Promise<void>} task
 * @returns {Promise<string[]>} failure messages, one per failed entry
 */
async function runPool(queue, concurrency, task) {
  const pending = [...queue];
  const failures = [];

  const worker = async () => {
    for (let entry = pending.shift(); entry; entry = pending.shift()) {
      try {
        await task(entry);
        console.log(`built ${path.join(entry.dir, entry.output)}`);
      } catch (error) {
        failures.push(`${path.join(entry.dir, entry.output)}: ${error.message}`);
      }
    }
  };

  await Promise.all(Array.from({ length: Math.min(concurrency, pending.length) }, worker));

  return failures;
}

/**
 * @param {(entry: import('./bundle-entries.mjs').BundleEntry) => Promise<void>} task
 * @param {number} concurrency
 * @param {string} done past participle for the summary line
 */
async function run(task, concurrency, done) {
  const started = Date.now();
  const failures = await runPool(entries, concurrency, task);

  if (failures.length) {
    throw new Error(
      `${failures.length} of ${entries.length} bundles failed:\n  ${failures.join('\n  ')}`
    );
  }

  const seconds = ((Date.now() - started) / 1000).toFixed(1);

  console.log(`\n${entries.length} bundles ${done} in ${seconds}s`);
}

/**
 * @param {number} [concurrency]
 */
export async function buildBundles(concurrency = defaultConcurrency()) {
  await run(buildEntry, concurrency, 'built');
}

/**
 * Watchers stay resident, so they are started one after another rather than
 * pooled. If any entry fails to come up, the ones that did are shut down again
 * rather than holding the process open around an incomplete set of bundles.
 */
export async function watchBundles() {
  /** @type {import('rollup').RollupWatcher[]} */
  const started = [];

  try {
    await run(entry => watchEntry(entry, started), 1, 'watched');
  } catch (error) {
    await Promise.all(started.map(watcher => watcher.close()));

    throw error;
  }
}
