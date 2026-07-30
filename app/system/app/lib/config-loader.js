/**
 * Pagekit Config Loader — CSP-compliant configuration bootstrap
 *
 * Reads server-rendered config from a JSON script container (no inline JS).
 * Enables strict Content Security Policy without 'unsafe-inline' in script-src.
 *
 * How it works:
 * 1. Server renders: <script id="pagekit-data" type="application/json">{"data":...}</script>
 * 2. Browser does NOT execute type="application/json" (data, not code)
 * 3. This loader parses the JSON and exposes named keys on window
 * 4. Classic-script consumers read window.$pagekit (and other keys) synchronously
 *
 * IMPORTANT: This script MUST run synchronously before other scripts.
 * The pagekit-data element must appear BEFORE this script in the HTML.
 *
 * The JSON container + loader is the current CSP delivery path.
 * Window globals for config remain until Pinia owns that state.
 */
(function () {
  'use strict';

  /**
   * Initialize configuration from JSON container — SYNCHRONOUS
   */
  function initConfig() {
    var configElement = document.getElementById('pagekit-data');

    if (!configElement) {
      // pagekit-data must be rendered BEFORE this script in the HTML
      if (console && console.warn) {
        console.warn(
          '[Pagekit] Config element #pagekit-data not found. Make sure it appears before config-loader.js in the HTML.'
        );
      }
      return false;
    }

    try {
      var content = configElement.textContent || configElement.innerText;
      if (!content || content.trim() === '') {
        return false;
      }

      var config = JSON.parse(content);

      // Expose named config keys on window for classic-script consumers ($pagekit, …).
      // Must run synchronously before dependent scripts.
      // TODO: Must be refactored in Step 3.3.4 (Pinia State Management)
      if (config.data && typeof config.data === 'object') {
        Object.keys(config.data).forEach(function (key) {
          window[key] = config.data[key];
        });
      }

      return true;
    } catch (e) {
      if (console && console.error) {
        console.error('[Pagekit] Failed to parse configuration:', e);
      }
      return false;
    }
  }

  // Execute IMMEDIATELY and SYNCHRONOUSLY
  // The pagekit-data script tag MUST appear before this script in the HTML
  var success = initConfig();

  // Debug API only — no console output
  window.PagekitConfigLoader = {
    init: initConfig,
    wasSuccessful: function () {
      return success;
    }
  };
})();
