<template>
    <div>
        <div class="uk-margin uk-flex uk-flex-space-between uk-flex-wrap" data-uk-margin>
            <div class="uk-flex uk-flex-middle uk-flex-wrap" data-uk-margin>
                <h2 class="uk-margin-remove">{{ 'Products' | trans }}</h2>
                <div class="uk-margin-left">
                    <span class="uk-badge">{{ products.length }}</span>
                </div>
            </div>
            <div class="uk-position-relative">
                <button class="uk-button uk-button-primary" @click="showCreateModal">
                    <i class="uk-icon-plus"></i> {{ 'Add Product' | trans }}
                </button>
            </div>
        </div>

        <!-- Products Table -->
        <div class="uk-overflow-auto">
            <table class="uk-table uk-table-hover uk-table-middle">
                <thead>
                    <tr>
                        <th class="pk-table-min-width-200">{{ 'Name' | trans }}</th>
                        <th class="pk-table-width-100 uk-text-center">{{ 'Price' | trans }}</th>
                        <th class="pk-table-width-200">{{ 'Description' | trans }}</th>
                        <th class="pk-table-width-100 uk-text-center">{{ 'Actions' | trans }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="products.length === 0">
                        <td colspan="4" class="uk-text-center">{{ 'No products found.' | trans }}</td>
                    </tr>
                    <tr v-for="product in products" :key="product.id">
                        <td>
                            <strong>{{ product.name }}</strong>
                        </td>
                        <td class="uk-text-center">
                            <span class="uk-badge uk-badge-success">€{{ product.price }}</span>
                        </td>
                        <td>
                            <span class="uk-text-muted">{{ product.description | truncate 50 }}</span>
                        </td>
                        <td class="uk-text-center">
                            <button class="uk-button uk-button-small uk-button-primary" 
                                    @click="editProduct(product)">
                                <i class="uk-icon-pencil"></i>
                            </button>
                            <button class="uk-button uk-button-small uk-button-danger" 
                                    @click="deleteProduct(product)">
                                <i class="uk-icon-trash"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Create/Edit Product Modal -->
        <v-modal v-model="showModal" :large="true">
            <div class="uk-modal-header">
                <h3>{{ editingProduct ? 'Edit Product' : 'Create Product' | trans }}</h3>
            </div>
            <div class="uk-modal-body">
                <form class="uk-form uk-form-stacked">
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Name' | trans }}</label>
                        <div class="uk-form-controls">
                            <input type="text" class="uk-width-1-1" v-model="form.name" required>
                        </div>
                    </div>
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Price' | trans }}</label>
                        <div class="uk-form-controls">
                            <input type="number" step="0.01" class="uk-width-1-1" v-model="form.price" required>
                        </div>
                    </div>
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Description' | trans }}</label>
                        <div class="uk-form-controls">
                            <textarea class="uk-width-1-1" rows="4" v-model="form.description"></textarea>
                        </div>
                    </div>
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Image URL' | trans }}</label>
                        <div class="uk-form-controls">
                            <input type="text" class="uk-width-1-1" v-model="form.image">
                        </div>
                    </div>
                </form>
            </div>
            <div class="uk-modal-footer uk-text-right">
                <button class="uk-button uk-button-link uk-modal-close" @click="closeModal">
                    {{ 'Cancel' | trans }}
                </button>
                <button class="uk-button uk-button-primary" @click="saveProduct">
                    {{ 'Save' | trans }}
                </button>
            </div>
        </v-modal>
    </div>
</template>

<script>
export default {
    name: 'ProductList',

    data() {
        return {
            products: [],
            showModal: false,
            editingProduct: null,
            form: {
                name: '',
                price: 0,
                description: '',
                image: ''
            }
        };
    },

    created() {
        console.log('ProductList component created');
        this.loadProducts();
    },

    methods: {
        loadProducts() {
            console.log('Loading products...');
            this.$http.get('/api/menucards/product').then(response => {
                this.products = response.data.products || [];
                console.log(`Loaded ${this.products.length} products`);
            }).catch(error => {
                console.error('Error loading products:', error);
                this.$notify('Failed to load products', 'danger');
            });
        },

        showCreateModal() {
            console.log('Opening create product modal');
            this.editingProduct = null;
            this.form = {
                name: '',
                price: 0,
                description: '',
                image: ''
            };
            this.showModal = true;
        },

        editProduct(product) {
            console.log('Editing product:', product.id);
            this.editingProduct = product;
            this.form = {
                name: product.name,
                price: product.price,
                description: product.description,
                image: product.image
            };
            this.showModal = true;
        },

        saveProduct() {
            console.log('Saving product...');
            
            // Validation
            if (!this.form.name || this.form.price <= 0) {
                this.$notify('Please fill all required fields', 'warning');
                return;
            }

            const url = this.editingProduct 
                ? `/api/menucards/product/${this.editingProduct.id}` 
                : '/api/menucards/product';
            
            const method = 'post';
            
            this.$http[method](url, { product: this.form }).then(response => {
                console.log('Product saved successfully');
                this.$notify(response.data.message, 'success');
                this.closeModal();
                this.loadProducts();
            }).catch(error => {
                console.error('Error saving product:', error);
                this.$notify('Failed to save product', 'danger');
            });
        },

        deleteProduct(product) {
            console.log('Deleting product:', product.id);
            
            if (!confirm('Are you sure you want to delete this product?')) {
                return;
            }

            this.$http.delete(`/api/menucards/product/${product.id}`).then(response => {
                console.log('Product deleted successfully');
                this.$notify(response.data.message, 'success');
                this.loadProducts();
            }).catch(error => {
                console.error('Error deleting product:', error);
                this.$notify('Failed to delete product', 'danger');
            });
        },

        closeModal() {
            this.showModal = false;
            this.editingProduct = null;
        }
    },

    filters: {
        truncate(value, length) {
            if (!value) return '';
            if (value.length <= length) return value;
            return value.substring(0, length) + '...';
        }
    }
};
</script>
