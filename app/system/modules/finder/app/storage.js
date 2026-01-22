/**
 * Storage page Vue initialization
 * 
 * This file replaces the inline script in storage.php for CSP compliance.
 * It initializes a Vue instance on the #storage element.
 */

Vue.ready(function() {
    if (document.getElementById('storage')) {
        new Vue({
            name: 'storage',
            el: '#storage'
        });
    }
});
