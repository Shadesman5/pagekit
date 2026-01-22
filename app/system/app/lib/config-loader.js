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
 * @since 1.0.x (Template Security Modernization)
 */
(function() {
    'use strict';

    var initialized = false;

    /**
     * Initialize configuration from JSON container
     */
    function initConfig() {
        if (initialized) {
            return;
        }

        var configElement = document.getElementById('pagekit-data');
        
        if (!configElement) {
            // No config element yet - might be called too early
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
            if (config.data && typeof config.data === 'object') {
                Object.keys(config.data).forEach(function(key) {
                    // Set on window object for global access
                    window[key] = config.data[key];
                });
            }

            initialized = true;
            return true;

        } catch (e) {
            // Log error but don't break the page
            if (console && console.error) {
                console.error('[Pagekit] Failed to parse configuration:', e);
            }
            return false;
        }
    }

    // Try immediately (script might be after pagekit-data in DOM)
    if (!initConfig()) {
        // If not found, wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initConfig);
        } else {
            // DOM already loaded, try again with slight delay
            setTimeout(initConfig, 0);
        }
    }

    // Also expose for manual re-initialization if needed
    window.PagekitConfigLoader = {
        init: initConfig,
        isInitialized: function() { return initialized; }
    };

})();
