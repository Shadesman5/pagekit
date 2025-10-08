import ProductList from './product-list.vue';

// Register component GLOBALLY first
Vue.component('product-list', ProductList);

const Products = {
    el: '#products',
    
    name: 'Products',
    
    data() {
        return _.merge({}, window.$data);
    },
    
    created() {
        console.log('Products app initialized');
    }
};

export default Products;

Vue.ready(Products);
