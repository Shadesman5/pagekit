/**
 * Compiles the LESS stylesheets of the core packages and the One theme.
 *
 * A stylesheet root is any `.less` file sitting directly in a `less/`
 * directory; it is compiled into the sibling `css/` directory, which is where
 * PHP loads the result from. Everything one level deeper is a partial of such
 * a root and is never compiled on its own.
 *
 * All three roots import uikit's LESS sources from `app/assets/uikit/`, so the
 * asset copy has to have run before this.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import chokidar from 'chokidar';
import less from 'less';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Coalesces the burst of watch events an editor emits for a single save. */
const REBUILD_DELAY = 100;

/**
 * The version lives in the PHP config, so the banner cannot drift from it.
 *
 * @returns {string}
 */
function pagekitVersion() {
  const config = fs.readFileSync(path.join(root, 'app/system/config.php'), 'utf8');
  const version = /'version'\s*=>\s*'([^']+)'/.exec(config);

  if (!version) {
    throw new Error('cannot read the Pagekit version from app/system/config.php');
  }

  return version[1];
}

/**
 * Builds the leading comment of a compiled stylesheet. Segments without data
 * are dropped rather than rendered empty.
 *
 * @param {...(string|undefined)} segments
 * @returns {string}
 */
function banner(...segments) {
  return `/*! ${segments.filter(Boolean).join(' | ')} */\n`;
}

/**
 * @param {string} dir package directory, relative to the repository root
 * @returns {Record<string, string>}
 */
function packageMeta(dir) {
  return JSON.parse(fs.readFileSync(path.join(root, dir, 'composer.json'), 'utf8'));
}

const core = {
  // uikit authors its image URLs relative to its own source tree, so they
  // have to be rewritten relative to the entry file.
  options: { compress: true, relativeUrls: true },
  banner: () => banner(`Pagekit ${pagekitVersion()}`, '(c) 2014-2020 Pagekit', 'MIT License')
};

/** @type {{ dir: string, options: object, banner: () => string }[]} */
const packages = [
  { dir: 'app/installer', ...core },
  { dir: 'app/system/modules/theme', ...core },
  {
    dir: 'packages/pagekit/theme-one',
    // The theme's own URLs are already written relative to its css/ output
    // directory; rewriting them relative to the entry would break them.
    options: { compress: true },
    banner: () => {
      const meta = packageMeta('packages/pagekit/theme-one');

      return banner(`${meta.title} ${meta.version}`, `${meta.license} License`);
    }
  }
];

/**
 * Collects the `less/*.less` roots below a directory.
 *
 * @param {string} dir absolute directory
 * @returns {string[]} absolute paths
 */
function findStyleRoots(dir) {
  const roots = [];

  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const entryPath = path.join(dir, entry.name);

    if (entry.isDirectory()) {
      if (entry.name !== 'node_modules') {
        roots.push(...findStyleRoots(entryPath));
      }
    } else if (entry.name.endsWith('.less') && path.basename(dir) === 'less') {
      roots.push(entryPath);
    }
  }

  return roots;
}

/**
 * @param {string} source absolute path of a `less/<name>.less` root
 * @returns {string} absolute path of the stylesheet it compiles to
 */
function outputPath(source) {
  const lessDir = path.dirname(source);

  return path.join(path.dirname(lessDir), 'css', `${path.basename(source, '.less')}.css`);
}

/**
 * @param {(typeof packages)[number]} pkg
 * @returns {Promise<string[]>} the stylesheets written, relative to the repository root
 */
async function compilePackage(pkg) {
  const written = [];

  for (const source of findStyleRoots(path.join(root, pkg.dir))) {
    const output = outputPath(source);
    const result = await less.render(fs.readFileSync(source, 'utf8'), {
      ...pkg.options,
      // Imports that are written relative to the package root instead of
      // to the importing file resolve through here; the Gulp pipeline
      // relied on the working directory for the same thing.
      paths: [path.join(root, pkg.dir)],
      filename: source
    });

    fs.mkdirSync(path.dirname(output), { recursive: true });
    fs.writeFileSync(output, pkg.banner() + result.css);
    written.push(path.relative(root, output));
  }

  return written;
}

/**
 * Compiles every package, reporting all failures instead of stopping at the
 * first one.
 */
export async function buildStyles() {
  const failures = [];

  for (const pkg of packages) {
    try {
      (await compilePackage(pkg)).forEach(output => console.log(`built ${output}`));
    } catch (error) {
      failures.push(`${pkg.dir}: ${error.message}`);
    }
  }

  if (failures.length) {
    throw new Error(
      `${failures.length} of ${packages.length} packages failed:\n  ${failures.join('\n  ')}`
    );
  }
}

/**
 * Recompiles a package whenever one of its LESS files changes. A broken
 * stylesheet is reported and the watcher stays up.
 */
export function watchStyles() {
  for (const pkg of packages) {
    const dirs = findStyleRoots(path.join(root, pkg.dir)).map(source => path.dirname(source));
    let timer;

    chokidar.watch(dirs, { ignoreInitial: true }).on('all', (event, file) => {
      if (!file.endsWith('.less')) {
        return;
      }

      clearTimeout(timer);
      timer = setTimeout(async () => {
        try {
          (await compilePackage(pkg)).forEach(output => console.log(`rebuilt ${output}`));
        } catch (error) {
          console.error(`failed ${pkg.dir}: ${error.message}`);
        }
      }, REBUILD_DELAY);
    });

    console.log(`watching ${pkg.dir}`);
  }
}
