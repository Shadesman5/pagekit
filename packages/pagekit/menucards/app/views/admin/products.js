/**
 * Products management Vue app
 */
const ProductList = {

    el: '#products',

    data() {
        return _.merge({
            products: [],
            pages: 0,
            count: 0,
            selected: [],
            editingProduct: {},
            config: {
                filter: {
                    search: '',
                    order: 'name asc'
                },
                page: 0
            }
        }, window.$data);
    },

    created() {
        // Debug: Component created
        console.log('[Menucards] Products component created');
        this.load();
    },

    watch: {
        'config.page': 'load',
        'config.filter': {
            handler(val) {
                if (this.config.page) {
                    this.config.page = 0;
                } else {
                    this.load();
                }
            },
            deep: true
        }
    },

    computed: {
        // Filtered products based on selection
        filteredProducts() {
            return this.products;
        }
    },

    methods: {

        /**
         * Load products from API
         */
        load() {
            console.log('[Menucards] Loading products...', this.config);
            
            this.$http.get('api/menucards/product', { params: this.config }).then(function (res) {
                const data = res.data;
                
                this.$set('products', data.products);
                this.$set('pages', data.pages);
                this.$set('count', data.count);
                
                console.log('[Menucards] Loaded ' + data.products.length + ' products');
            }, function (res) {
                console.error('[Menucards] Error loading products:', res);
                this.$notify(res.data, 'danger');
            });
        },

        /**
         * Add new product
         */
        addProduct() {
            console.log('[Menucards] Add product clicked');
            
            this.editingProduct = {
                name: '',
                description: '',
                price: null,
                allergens: '',
                image: ''
            };
            
            this.$refs.editmodal.open();
        },

        /**
         * Edit existing product
         */
        editProduct(product) {
            console.log('[Menucards] Edit product:', product.id);
            
            this.editingProduct = _.cloneDeep(product);
            this.$refs.editmodal.open();
        },

        /**
         * Save product (create or update)
         */
        saveProduct(product) {
            console.log('[Menucards] Saving product:', product);
            
            this.$http.post('api/menucards/product' + (product.id ? '/' + product.id : ''), { product: product }).then(function (res) {
                console.log('[Menucards] Product saved:', res.data);
                this.$notify('Product saved.');
                this.$refs.editmodal.close();
                this.load();
            }, function (res) {
                console.error('[Menucards] Error saving product:', res);
                this.$notify(res.data, 'danger');
            });
        },

        /**
         * Remove single product
         */
        removeProduct(product) {
            console.log('[Menucards] Removing product:', product.id);
            
            this.$http.delete('api/menucards/product/' + product.id).then(function () {
                console.log('[Menucards] Product removed');
                this.$notify('Product deleted.');
                this.load();
            }, function (res) {
                console.error('[Menucards] Error removing product:', res);
                this.$notify(res.data, 'danger');
            });
        },

        /**
         * Remove multiple products
         */
        removeProducts() {
            console.log('[Menucards] Bulk removing products:', this.selected);
            
            this.$http.post('api/menucards/product/bulk', { ids: this.selected }).then(function () {
                console.log('[Menucards] Products removed');
                this.$notify('Products deleted.');
                this.selected = [];
                this.load();
            }, function (res) {
                console.error('[Menucards] Error bulk removing:', res);
                this.$notify(res.data, 'danger');
            });
        }

    },

    components: {

        /**
         * Product edit modal component
         */
        'product-edit-modal': {
            props: ['product'],
            template: `
                <div>
                    <div class="uk-modal-header">
                        <h2 v-if="product.id">{{ 'Edit Product' | trans }}</h2>
                        <h2 v-else>{{ 'Add Product' | trans }}</h2>
                    </div>
                    <div class="uk-form uk-form-stacked">
                        <div class="uk-form-row">
                            <label class="uk-form-label">{{ 'Name' | trans }}</label>
                            <div class="uk-form-controls">
                                <input type="text" class="uk-width-1-1" v-model="product.name" required>
                            </div>
                        </div>
                        <div class="uk-form-row">
                            <label class="uk-form-label">{{ 'Price' | trans }}</label>
                            <div class="uk-form-controls">
                                <input type="number" step="0.01" class="uk-width-1-1" v-model="product.price">
                            </div>
                        </div>
                        <div class="uk-form-row">
                            <label class="uk-form-label">{{ 'Description' | trans }}</label>
                            <div class="uk-form-controls">
                                <textarea class="uk-width-1-1" rows="3" v-model="product.description"></textarea>
                            </div>
                        </div>
                        <div class="uk-form-row">
                            <label class="uk-form-label">{{ 'Allergens' | trans }}</label>
                            <div class="uk-form-controls">
                                <input type="text" class="uk-width-1-1" v-model="product.allergens" placeholder="Gluten, Lactose, Nuts">
                            </div>
                        </div>
                    </div>
                    <div class="uk-modal-footer uk-text-right">
                        <button class="uk-button uk-button-link uk-modal-close" type="button">{{ 'Cancel' | trans }}</button>
                        <button class="uk-button uk-button-primary" type="button" @click="save">{{ 'Save' | trans }}</button>
                    </div>
                </div>
            `,
            methods: {
                save() {
                    if (!this.product.name) {
                        this.$notify('Product name is required', 'danger');
                        return;
                    }
                    this.$parent.$emit('save', this.product);
                }
            }
        }

    }

};

export default ProductList;

Vue.ready(ProductList);
