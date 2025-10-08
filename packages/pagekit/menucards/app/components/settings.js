// Menucards Settings (Placeholder)
window.Settings = {
    el: '#settings',
    
    data() {
        return {
            settings: {}
        };
    },
    
    created() {
        console.log('Menucards settings initialized');
    }
};

Vue.ready(window.Settings);
