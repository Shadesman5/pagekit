const js = require('@eslint/js');
const globals = require('globals');
const pluginVue = require('eslint-plugin-vue');
const prettier = require('eslint-config-prettier/flat');

module.exports = [
  {
    ignores: [
      '.vscode/',
      'public/',
      'storage/',
      'tmp/',
      '**/assets/**',
      '**/vendor/**',
      '**/bundle/**',
      '**/theme/**/theme.js',
      '**/theme/**/components/**'
    ]
  },

  js.configs.recommended,
  ...pluginVue.configs['flat/vue2-recommended'],

  {
    languageOptions: {
      globals: {
        ...globals.browser,
        ...globals.node,
        // Published by classic <script> tags, not by module imports: PHP loads these
        // libraries separately, so the bundles treat them as ambient.
        _: 'readonly',
        Vue: 'readonly',
        UIkit: 'readonly',
        Theme: 'readonly',
        marked: 'readonly',
        flatpickr: 'readonly',
        grecaptcha: 'readonly',
        $pagekit: 'readonly',
        $editor: 'readonly',
        CodeMirror: 'readonly'
      }
    },
    rules: {
      eqeqeq: ['error', 'smart'],
      'guard-for-in': 'error',
      'no-console': ['error', { allow: ['warn', 'error'] }],
      'no-unused-expressions': ['error', { allowShortCircuit: true, allowTernary: true }],
      'no-unused-vars': ['error', { vars: 'local', args: 'none' }],
      // Deliberate patterns across the admin JS: \x00-\xFF ranges strip non-latin
      // characters from locale format strings, hasOwnProperty guards plain data
      // objects from the API, and the translation/URL regexes over-escape on purpose.
      'no-control-regex': 'off',
      'no-prototype-builtins': 'off',
      'no-useless-escape': 'off',
      // Pagekit registers its components under the single-word names that the PHP
      // views and third-party themes reference; renaming them is a breaking change.
      'vue/multi-word-component-names': 'off',
      'vue/no-v-html': 'off',
      'vue/require-prop-types': 'off'
    }
  },

  {
    // Command-line tooling: stdout is the output channel, not a debug leftover.
    files: ['scripts/**', '.github/**', '.cursor/**', 'tests/**', 'playwright.config.js'],
    rules: { 'no-console': 'off' }
  },

  {
    files: ['tests/**/*.js', '.cursor/**/*.js', 'playwright.config.js'],
    languageOptions: {
      sourceType: 'commonjs',
      // Browser globals of the page under test, referenced inside page.evaluate callbacks.
      globals: { jQuery: 'readonly' }
    }
  },

  {
    // UIkit's component registry, not Vue's: the name is the `uk-header` element
    // contract the theme's PHP views bind to.
    files: ['packages/pagekit/theme-one/js/*.js'],
    rules: { 'vue/no-reserved-component-names': 'off' }
  },

  prettier
];
