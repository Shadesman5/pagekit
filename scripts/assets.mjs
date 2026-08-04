/**
 * Copies the runtime assets Pagekit serves out of `node_modules`.
 *
 * These packages are not bundled: PHP registers them as plain script and link
 * tags below `app/assets/` and the editor module's `app/assets/`, so the whole
 * published distribution has to sit at those paths inside the webroot.
 * Existing files are overwritten in place.
 */

import fs from 'node:fs';
import path from 'node:path';

import { published, root } from './paths.mjs';

/**
 * @typedef {object} AssetCopy
 * @property {string}  package  name of the `node_modules` package
 * @property {string}  dest     served path of the copy, relative to the webroot
 * @property {RegExp} [files]   copy only the matching files from the package root
 */

/** @type {AssetCopy[]} */
const copies = [
  { package: 'uikit', dest: 'app/assets/uikit' },
  { package: 'vue', dest: 'app/assets/vue' },
  { package: 'flatpickr', dest: 'app/assets/flatpickr' },
  // The view module script-loads the two full builds; the per-method modules
  // in the package root have no consumer.
  { package: 'lodash', dest: 'app/assets/lodash/dist', files: /^lodash.*\.js$/ },
  { package: 'marked', dest: 'app/system/modules/editor/app/assets/marked' },
  // The fork is published under a capitalized name, which matters on Linux.
  { package: 'Codemirror', dest: 'app/system/modules/editor/app/assets/codemirror' }
];

/** The paths this module owns, for anything that walks the sources they mirror. */
export const assetDests = copies.map(copy => copy.dest);

/**
 * Dotfiles are repository leftovers of a published package (linter configs,
 * directory placeholders) and are never served.
 *
 * @param {string} source
 * @returns {boolean}
 */
function isServed(source) {
  return !path.basename(source).startsWith('.');
}

/**
 * @param {AssetCopy} copy
 */
function copyAsset(copy) {
  const linked = path.join(root, 'node_modules', copy.package);
  const to = published(copy.dest);

  if (!fs.existsSync(linked)) {
    throw new Error(`missing package: node_modules/${copy.package}`);
  }

  // pnpm (and Yarn) expose packages as symlinks. On Windows, fs.cpSync refuses
  // to copy a symlink path onto an existing directory even with
  // `dereference: true`; resolve to the real package tree first.
  const from = fs.realpathSync(linked);

  fs.mkdirSync(to, { recursive: true });

  if (copy.files) {
    fs.readdirSync(from)
      .filter(file => copy.files.test(file))
      .forEach(file => fs.copyFileSync(path.join(from, file), path.join(to, file)));

    return;
  }

  fs.cpSync(from, to, { recursive: true, dereference: true, filter: isServed });
}

/**
 * Copies every asset package. A missing package stops the copy: the runtime
 * script-loads these paths and has no fallback for one that is not published.
 */
export function copyAssets() {
  for (const copy of copies) {
    copyAsset(copy);
    console.log(`copied ${copy.package} to ${copy.dest}`);
  }
}
