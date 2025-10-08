import MenuList from './menu-list.vue';

window.Menus = {
    el: '#menus',
    
    name: 'Menus',
    
    components: {
        MenuList
    },
    
    created() {
        console.log('Menus app initialized');
    }
};

Vue.ready(window.Menus);
