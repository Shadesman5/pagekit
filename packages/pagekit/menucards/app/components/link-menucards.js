// Menucards Link Component (Placeholder for page/blog integration)
window.MenucardsLink = {
    name: 'MenucardsLink',
    
    props: ['link'],
    
    data() {
        return {
            menus: []
        };
    },
    
    created() {
        console.log('MenucardsLink component initialized');
        this.loadMenus();
    },
    
    methods: {
        loadMenus() {
            this.$http.get('/api/menucards/menu').then(response => {
                this.menus = response.data.menus || [];
            });
        }
    }
};

Vue.component('MenucardsLink', window.MenucardsLink);
