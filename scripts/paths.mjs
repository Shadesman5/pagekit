/**
 * Filesystem layout of the build.
 *
 * Module and package sources sit outside the webroot; everything a browser may
 * request is published below `public/` under the same relative path, so the URL
 * of every bundle, asset and stylesheet is the path it is published under.
 */

import path from 'node:path';
import { fileURLToPath } from 'node:url';

/** Repository root. */
export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** The webroot: the only directory a webserver exposes. */
export const webroot = path.join(root, 'public');

/**
 * @param {string} target served path, relative to the webroot
 * @returns {string} absolute path to write it to
 */
export function published(target) {
  return path.join(webroot, target);
}
