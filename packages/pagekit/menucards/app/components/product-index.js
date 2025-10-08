import ProductList from './product-list.vue';

window.Products = {
    el: '#products',
    
    name: 'Products',
    
    components: {
        ProductList
    },
    
    created() {
        console.log('Products app initialized');
    }
};

Vue.ready(window.Products);
