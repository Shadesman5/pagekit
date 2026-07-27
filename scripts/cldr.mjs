#!/usr/bin/env node

/**
 * Regenerates the CLDR data Pagekit ships with.
 *
 * For every locale directory below `app/system/languages/` this writes the
 * display names of languages and territories plus the date and number formats,
 * falling back from the full locale (`de_DE`) to the language (`de`) to `en`.
 * The territory containment map used by the intl module is rebuilt as well.
 *
 * The results are committed, so this runs on demand after a CLDR upgrade
 * rather than as part of the build.
 *
 * Usage: node scripts/cldr.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const sources = {
  supplemental: path.join(root, 'node_modules/cldr-core/supplemental'),
  names: path.join(root, 'node_modules/cldr-localenames-modern/main'),
  formats: path.join(root, 'node_modules/vue-intl/dist/locales')
};

const targets = {
  intl: path.join(root, 'app/system/modules/intl/data'),
  languages: path.join(root, 'app/system/languages')
};

/**
 * @param {string} file
 * @returns {unknown}
 */
function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

/**
 * @param {string} file
 * @param {unknown} data
 */
function writeJson(file, data) {
  fs.writeFileSync(file, JSON.stringify(data));
}

/**
 * Returns the first candidate locale that has the requested file.
 *
 * @param {string} dir
 * @param {string[]} candidates
 * @param {(locale: string) => string} file names the file a candidate would live in
 * @returns {string|null}
 */
function resolveLocale(dir, candidates, file) {
  return candidates.find(locale => fs.existsSync(path.join(dir, file(locale)))) ?? null;
}

/**
 * The intl module resolves a territory to its parent regions through this map.
 */
function buildTerritoryContainment() {
  const containment = readJson(path.join(sources.supplemental, 'territoryContainment.json'))
    .supplemental.territoryContainment;
  const data = {};

  Object.keys(containment).forEach(territory => {
    data[territory] = containment[territory]._contains;
  });

  writeJson(path.join(targets.intl, 'territoryContainment.json'), data);
  console.log('wrote app/system/modules/intl/data/territoryContainment.json');
}

/**
 * @param {string} locale directory name below `app/system/languages`
 */
function buildLocale(locale) {
  // Directory names are POSIX locales (`de_DE`), CLDR uses BCP 47 (`de-DE`).
  const id = locale.replace('_', '-');
  const language = id.substring(0, id.indexOf('-'));
  const candidates = [id, language, 'en'];

  ['languages', 'territories'].forEach(name => {
    const source = resolveLocale(
      sources.names,
      candidates,
      candidate => `${candidate}/${name}.json`
    );

    if (source) {
      const names = readJson(path.join(sources.names, source, `${name}.json`));

      writeJson(
        path.join(targets.languages, locale, `${name}.json`),
        names.main[source].localeDisplayNames[name]
      );
    }
  });

  // vue-intl publishes its locale data under lowercase names.
  const formats = resolveLocale(
    sources.formats,
    [id.toLowerCase(), language, 'en'],
    candidate => `${candidate}.json`
  );

  if (formats) {
    fs.copyFileSync(
      path.join(sources.formats, `${formats}.json`),
      path.join(targets.languages, locale, 'formats.json')
    );
  }
}

buildTerritoryContainment();

const locales = fs
  .readdirSync(targets.languages, { withFileTypes: true })
  .filter(entry => entry.isDirectory())
  .map(entry => entry.name);

locales.forEach(buildLocale);

console.log(`wrote ${locales.length} locales below app/system/languages`);
