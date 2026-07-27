/**
 * Pagekit Config Loader - CSP-Compliant Configuration Reader
 *
 * Reads configuration from JSON script container (no inline scripts needed).
 * This enables strict Content Security Policy without 'unsafe-inline'.
 *
 * How it works:
 * 1. Server renders: <script id="pagekit-data" type="application/json">{"data":...}</script>
 * 2. Browser does NOT execute type="application/json" (it's data, not code)
 * 3. This loader reads the JSON and exposes data as global variables
 * 4. Result: Backward compatible with existing code ($pagekit, etc.)
 *
 * IMPORTANT: This script MUST run synchronously before other scripts!
 * The pagekit-data element must appear BEFORE this script in the HTML.
 *
 * @since 1.0.x (Template Security Modernization)
 */
(function () {
  'use strict';

  /**
   * Initialize configuration from JSON container - SYNCHRONOUS
   */
  function initConfig() {
    var configElement = document.getElementById('pagekit-data');

    if (!configElement) {
      // This is a problem - the pagekit-data element should exist
      // It must be rendered BEFORE this script in the HTML
      if (console && console.warn) {
        console.warn(
          '[Pagekit] Config element #pagekit-data not found. Make sure it appears before config-loader.js in the HTML.'
        );
      }
      return false;
    }

    try {
      // Parse JSON content from script tag
      var content = configElement.textContent || configElement.innerText;
      if (!content || content.trim() === '') {
        return false;
      }

      var config = JSON.parse(content);

      // Expose data as global variables (backward compatibility)
      // This MUST happen synchronously before other scripts run!
      if (config.data && typeof config.data === 'object') {
        Object.keys(config.data).forEach(function (key) {
          // Set on window object for global access
          window[key] = config.data[key];
        });
      }

      return true;
    } catch (e) {
      // Log error but don't break the page
      if (console && console.error) {
        console.error('[Pagekit] Failed to parse configuration:', e);
      }
      return false;
    }
  }

  // Execute IMMEDIATELY and SYNCHRONOUSLY
  // The pagekit-data script tag MUST appear before this script in the HTML
  var success = initConfig();

  // Expose for debugging (only the API, no console output)
  window.PagekitConfigLoader = {
    init: initConfig,
    wasSuccessful: function () {
      return success;
    }
  };
})();
