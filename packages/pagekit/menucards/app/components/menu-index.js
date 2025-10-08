import MenuList from './menu-list.vue';

const Menus = {
    el: '#menus',
    
    name: 'Menus',
    
    components: {
        MenuList
    },
    
    data() {
        return _.merge({
            config: {}
        }, window.$data);
    },
    
    created() {
        console.log('Menus app initialized');
    }
};

export default Menus;

Vue.ready(Menus);
