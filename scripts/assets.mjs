/**
 * Copies the runtime assets Pagekit serves out of `node_modules`.
 *
 * These packages are not bundled: PHP registers them as plain script and link
 * tags below `app/assets/` and the editor module's `app/assets/`, so the whole
 * published distribution has to sit at those paths. Existing files are
 * overwritten in place - the destinations also hold committed assets (the
 * TinyMCE skin) that the copies must leave alone.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/**
 * @typedef {object} AssetCopy
 * @property {string}  package  name of the `node_modules` package
 * @property {string}  dest     destination directory, relative to the repository root
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
  { package: 'tinymce', dest: 'app/system/modules/editor/app/assets/tinymce' },
  { package: 'marked', dest: 'app/system/modules/editor/app/assets/marked' },
  // The fork is published under a capitalized name, which matters on Linux.
  { package: 'Codemirror', dest: 'app/system/modules/editor/app/assets/codemirror' }
];

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
  const from = path.join(root, 'node_modules', copy.package);
  const to = path.join(root, copy.dest);

  if (!fs.existsSync(from)) {
    throw new Error(`missing package: node_modules/${copy.package}`);
  }

  fs.mkdirSync(to, { recursive: true });

  if (copy.files) {
    fs.readdirSync(from)
      .filter(file => copy.files.test(file))
      .forEach(file => fs.copyFileSync(path.join(from, file), path.join(to, file)));

    return;
  }

  // Package managers link dependencies instead of copying them, so the tree
  // has to be dereferenced on the way out.
  fs.cpSync(from, to, { recursive: true, dereference: true, filter: isServed });
}

/**
 * Copies every asset package. A missing package stops the copy: nothing that
 * follows can compile or run against an incomplete `app/assets/`.
 */
export function copyAssets() {
  for (const copy of copies) {
    copyAsset(copy);
    console.log(`copied ${copy.package} to ${copy.dest}`);
  }
}
