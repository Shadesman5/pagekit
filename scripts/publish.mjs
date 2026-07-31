/**
 * Publishes the servable files a module or package ships with its sources, and
 * links the media storage into the webroot.
 *
 * The compiled outputs - bundles, stylesheets, copied packages - are written
 * into `public/` by the builder that produces them. What is left are the files
 * that are committed ready to serve: icons, images, fonts and the scripts PHP
 * registers without bundling them. They are copied under the same relative
 * path, so their URL does not change; the sources beside them (LESS, PHP,
 * views, translations) never enter the webroot.
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

import chokidar from 'chokidar';

import { assetDests } from './assets.mjs';
import { published, root } from './paths.mjs';

/** Coalesces the burst of watch events an editor emits for a single save. */
const REPUBLISH_DELAY = 100;

/** Directories of a module or package that are served whole. */
const SERVED_DIRS = ['assets', 'css', 'js', 'images', 'fonts'];

/** Directories below `app/` that are served whole: the bundle output and the copied packages. */
const SERVED_APP_DIRS = ['bundle', 'assets'];

/** Everything else below `app/` is served only if a script or link tag can point at it. */
const SERVED_APP_FILES = /\.(js|css)$/;

/** Icons a module or package ships in its root: menu entries and package thumbnails. */
const SERVED_ROOT_FILES = /\.(ico|jpe?g|png|svg)$/;

/**
 * Sources and server-side data, none of which a browser may request. A
 * `vendor` directory is not one of them: Composer's sits at the root of a tree,
 * which is never walked, while one below a served directory holds a front-end
 * library the module vendored to ship it.
 */
const PRIVATE_DIRS = new Set(['languages', 'less', 'node_modules', 'src', 'views']);

/** Copy destinations of `copyAssets`: leftovers of an earlier build, never a source. */
const copied = new Set(assetDests);

/**
 * @param {string} dir directory relative to the repository root
 * @returns {string[]} its subdirectories, relative to the repository root
 */
function subdirectories(dir) {
  return fs
    .readdirSync(path.join(root, dir), { withFileTypes: true })
    .filter(entry => entry.isDirectory())
    .map(entry => `${dir}/${entry.name}`);
}

/**
 * The trees that ship servable files: the installer and the core, the
 * framework and system modules, and the packages below their vendor.
 *
 * @returns {string[]} paths relative to the repository root
 */
function trees() {
  return [
    'app/installer',
    'app/system',
    ...subdirectories('app/modules'),
    ...subdirectories('app/system/modules'),
    ...subdirectories('packages').flatMap(subdirectories)
  ];
}

/**
 * Collects the files below a directory, leaving out what is never served and
 * what an earlier build left behind.
 *
 * @param {string} dir directory relative to the repository root
 * @returns {string[]} paths relative to the repository root
 */
function walk(dir) {
  const files = [];

  for (const entry of fs.readdirSync(path.join(root, dir), { withFileTypes: true })) {
    const target = `${dir}/${entry.name}`;

    // Dotfiles are editor and tooling state that happens to sit in a served
    // directory; a copy destination holds what an earlier build put there.
    if (entry.name.startsWith('.') || copied.has(target)) {
      continue;
    }

    if (entry.isDirectory()) {
      if (!PRIVATE_DIRS.has(entry.name)) {
        files.push(...walk(target));
      }
    } else if (!entry.name.endsWith('.php')) {
      files.push(target);
    }
  }

  return files;
}

/**
 * The `app/` sources are mostly bundle input, so only the directories the
 * runtime loads from are served whole.
 *
 * @param {string} app `app` directory of a tree, relative to the repository root
 * @returns {string[]} the files it serves, relative to the repository root
 */
function servedAppFiles(app) {
  const files = [];

  for (const entry of fs.readdirSync(path.join(root, app), { withFileTypes: true })) {
    const target = `${app}/${entry.name}`;

    if (!entry.isDirectory()) {
      if (SERVED_APP_FILES.test(entry.name)) {
        files.push(target);
      }
    } else if (SERVED_APP_DIRS.includes(entry.name)) {
      files.push(...walk(target));
    } else {
      files.push(...walk(target).filter(file => SERVED_APP_FILES.test(file)));
    }
  }

  return files;
}

/**
 * @param {string} tree module or package directory, relative to the repository root
 * @returns {string[]} the files it serves, relative to the repository root
 */
function servedFiles(tree) {
  const files = [];

  for (const entry of fs.readdirSync(path.join(root, tree), { withFileTypes: true })) {
    const target = `${tree}/${entry.name}`;

    if (!entry.isDirectory()) {
      if (SERVED_ROOT_FILES.test(entry.name)) {
        files.push(target);
      }
    } else if (entry.name === 'app') {
      files.push(...servedAppFiles(target));
    } else if (SERVED_DIRS.includes(entry.name)) {
      files.push(...walk(target));
    }
  }

  return files;
}

/**
 * @returns {string[]} the directories a change can reach a published file
 *   through, relative to the repository root
 */
function servedDirs() {
  return trees()
    .flatMap(tree => ['app', ...SERVED_DIRS].map(dir => `${tree}/${dir}`))
    .filter(dir => fs.existsSync(path.join(root, dir)));
}

/**
 * Publishes every servable file of every tree.
 */
export function publishStatics() {
  const files = trees().flatMap(servedFiles);

  for (const file of files) {
    const to = published(file);

    fs.mkdirSync(path.dirname(to), { recursive: true });
    fs.copyFileSync(path.join(root, file), to);
  }

  console.log(`published ${files.length} files`);
}

/**
 * Publishes everything once, then republishes on every change below a served
 * directory. The pass is a plain file copy, so a full run is cheaper than
 * tracking which file an event belongs to.
 */
export function watchStatics() {
  const dirs = servedDirs();
  let timer;

  publishStatics();

  chokidar
    .watch(dirs, {
      ignoreInitial: true,
      ignored: target =>
        PRIVATE_DIRS.has(path.basename(target)) ||
        copied.has(path.relative(root, target).split(path.sep).join('/'))
    })
    .on('all', () => {
      clearTimeout(timer);
      timer = setTimeout(publishStatics, REPUBLISH_DELAY);
    });

  console.log(`watching ${dirs.length} directories`);
}

/**
 * Links the storage into the webroot: uploads are served from `/storage` while
 * the directory the application writes to stays outside it.
 *
 * A link that cannot be created only costs the media its URL, so it is
 * reported rather than failing the build - it can be created by hand.
 */
export function linkStorage() {
  const link = published('storage');

  if (fs.lstatSync(link, { throwIfNoEntry: false })) {
    return;
  }

  fs.mkdirSync(path.dirname(link), { recursive: true });

  try {
    // Junctions are the only link Windows creates without elevated rights, and
    // they resolve absolute targets only.
    if (process.platform === 'win32') {
      fs.symlinkSync(path.join(root, 'storage'), link, 'junction');
    } else {
      fs.symlinkSync('../storage', link, 'dir');
    }

    console.log('linked public/storage');
  } catch (error) {
    console.warn(`cannot link public/storage: ${error.message}`);
  }
}
