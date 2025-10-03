/**
 * Menu edit Vue app - Complex editing with categories and products
 */
const MenuEdit = {

    el: '#menu-edit',

    data() {
        return _.merge({
            menu: {
                id: 0,
                title: '',
                slug: '',
                description: '',
                status: 0,
                categories: []
            },
            allProducts: [],
            currentCategoryIndex: null,
            newProduct: {}
        }, window.$data);
    },

    created() {
        // Debug: Component created
        console.log('[Menucards] Menu edit component created with menu_id:', this.menu_id);
        
        if (this.menu_id) {
            this.load();
        }
        
        this.loadAllProducts();
    },

    methods: {

        /**
         * Load menu with categories and products
         */
        load() {
            console.log('[Menucards] Loading menu:', this.menu_id);
            
            this.$http.get('api/menucards/menu/' + this.menu_id).then(function (res) {
                this.$set('menu', res.data);
                console.log('[Menucards] Menu loaded with', (res.data.categories || []).length, 'categories');
            }, function (res) {
                console.error('[Menucards] Error loading menu:', res);
                this.$notify(res.data, 'danger');
            });
        },

        /**
         * Load all products for selector
         */
        loadAllProducts() {
            console.log('[Menucards] Loading all products');
            
            this.$http.get('api/menucards/product', { params: { page: 0 } }).then(function (res) {
                this.$set('allProducts', res.data.products);
                console.log('[Menucards] Loaded', res.data.products.length, 'products for selector');
            }, function (res) {
                console.error('[Menucards] Error loading products:', res);
            });
        },

        /**
         * Save menu with all categories
         */
        save() {
            console.log('[Menucards] Saving menu:', this.menu);
            
            this.$http.post('api/menucards/menu' + (this.menu.id ? '/' + this.menu.id : ''), { menu: this.menu }).then(function (res) {
                console.log('[Menucards] Menu saved:', res.data);
                this.$notify('Menu saved.');
                
                // Reload to get updated data with IDs
                if (res.data.menu && res.data.menu.id) {
                    this.menu_id = res.data.menu.id;
                    this.$set('menu', res.data.menu);
                }
            }, function (res) {
                console.error('[Menucards] Error saving menu:', res);
                this.$notify(res.data, 'danger');
            });
        },

        /**
         * Add new category
         */
        addCategory() {
            console.log('[Menucards] Adding new category');
            
            if (!this.menu.categories) {
                this.$set('menu.categories', []);
            }
            
            this.menu.categories.push({
                title: 'New Category',
                description: '',
                priority: this.menu.categories.length,
                products: []
            });
        },

        /**
         * Remove category
         */
        removeCategory(index) {
            console.log('[Menucards] Removing category at index:', index);
            this.menu.categories.splice(index, 1);
        },

        /**
         * Show product selector modal
         */
        showProductSelector(categoryIndex) {
            console.log('[Menucards] Show product selector for category:', categoryIndex);
            this.currentCategoryIndex = categoryIndex;
            this.$refs.productmodal.open();
        },

        /**
         * Show product creator modal (CRITICAL WORKFLOW)
         */
        showProductCreator(categoryIndex) {
            console.log('[Menucards] Show product creator for category:', categoryIndex);
            this.currentCategoryIndex = categoryIndex;
            this.newProduct = {
                name: '',
                description: '',
                price: null,
                allergens: ''
            };
            this.$refs.creatormodal.open();
        },

        /**
         * Add existing product to category
         */
        addProductToCategory(product) {
            console.log('[Menucards] Adding product to category:', product.id, this.currentCategoryIndex);
            
            if (this.currentCategoryIndex === null) return;
            
            const category = this.menu.categories[this.currentCategoryIndex];
            
            if (!category.products) {
                this.$set('menu.categories[' + this.currentCategoryIndex + '].products', []);
            }
            
            // Check if product already in category
            const exists = category.products.find(p => p.id === product.id);
            if (exists) {
                this.$notify('Product already in category', 'warning');
                return;
            }
            
            category.products.push(product);
            this.$refs.productmodal.close();
        },

        /**
         * Create new product and add to category (CRITICAL WORKFLOW)
         */
        createAndAddProduct(product) {
            console.log('[Menucards] Creating new product and adding to category:', product);
            
            // Step 1: Create product in global database
            this.$http.post('api/menucards/product', { product: product }).then(function (res) {
                console.log('[Menucards] Product created:', res.data.product);
                
                const newProduct = res.data.product;
                
                // Step 2: Add to current category
                if (this.currentCategoryIndex !== null) {
                    const category = this.menu.categories[this.currentCategoryIndex];
                    
                    if (!category.products) {
                        this.$set('menu.categories[' + this.currentCategoryIndex + '].products', []);
                    }
                    
                    category.products.push(newProduct);
                    console.log('[Menucards] Product added to category');
                }
                
                // Step 3: Update all products list
                this.allProducts.push(newProduct);
                
                this.$notify('Product created and added to category.');
                this.$refs.creatormodal.close();
                
            }, function (res) {
                console.error('[Menucards] Error creating product:', res);
                this.$notify(res.data, 'danger');
            });
        },

        /**
         * Remove product from category
         */
        removeProductFromCategory(categoryIndex, productIndex) {
            console.log('[Menucards] Removing product from category:', categoryIndex, productIndex);
            this.menu.categories[categoryIndex].products.splice(productIndex, 1);
        }

    },

    components: {

        /**
         * Product selector modal
         */
        'product-selector-modal': {
            props: ['products'],
            template: `
                <div>
                    <div class="uk-modal-header">
                        <h2>{{ 'Select Product' | trans }}</h2>
                    </div>
                    <div class="uk-overflow-auto" style="max-height: 400px;">
                        <table class="uk-table uk-table-hover">
                            <thead>
                                <tr>
                                    <th>{{ 'Name' | trans }}</th>
                                    <th class="uk-text-right">{{ 'Price' | trans }}</th>
                                    <th class="uk-text-center">{{ 'Action' | trans }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="product in products" :key="product.id">
                                    <td>{{ product.name }}</td>
                                    <td class="uk-text-right">{{ product.price | currency }}</td>
                                    <td class="uk-text-center">
                                        <button class="uk-button uk-button-small uk-button-primary" @click="select(product)">
                                            {{ 'Add' | trans }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="uk-modal-footer uk-text-right">
                        <button class="uk-button uk-button-link uk-modal-close" type="button">{{ 'Cancel' | trans }}</button>
                    </div>
                </div>
            `,
            methods: {
                select(product) {
                    this.$parent.$emit('select', product);
                }
            }
        },

        /**
         * Product creator modal (CRITICAL COMPONENT)
         */
        'product-creator-modal': {
            props: [],
            data() {
                return {
                    product: {
                        name: '',
                        description: '',
                        price: null,
                        allergens: ''
                    }
                };
            },
            template: `
                <div>
                    <div class="uk-modal-header">
                        <h2>{{ 'Create New Product' | trans }}</h2>
                    </div>
                    <div class="uk-form uk-form-stacked">
                        <div class="uk-form-row">
                            <label class="uk-form-label">{{ 'Name' | trans }} *</label>
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
                        <button class="uk-button uk-button-primary" type="button" @click="save">{{ 'Create & Add' | trans }}</button>
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

    },

    filters: {
        currency(value) {
            if (!value) return '-';
            return parseFloat(value).toFixed(2) + ' €';
        }
    }

};

export default MenuEdit;

Vue.ready(MenuEdit);
