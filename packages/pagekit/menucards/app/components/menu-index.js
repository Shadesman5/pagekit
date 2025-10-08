import MenuList from './menu-list.vue';

// Register component GLOBALLY first
Vue.component('menu-list', MenuList);

const Menus = {
    el: '#menus',
    
    name: 'Menus',
    
    data() {
        return _.merge({}, window.$data);
    },
    
    created() {
        console.log('Menus app initialized');
    }
};

export default Menus;

Vue.ready(Menus);
