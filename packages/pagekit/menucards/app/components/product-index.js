import ProductList from './product-list.vue';

const Products = {
    el: '#products',
    
    name: 'Products',
    
    components: {
        ProductList
    },
    
    data() {
        return _.merge({
            config: {}
        }, window.$data);
    },
    
    created() {
        console.log('Products app initialized');
    }
};

export default Products;

Vue.ready(Products);
