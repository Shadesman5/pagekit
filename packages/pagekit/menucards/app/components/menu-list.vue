<template>
    <div>
        <div class="uk-margin uk-flex uk-flex-space-between uk-flex-wrap" data-uk-margin>
            <div class="uk-flex uk-flex-middle uk-flex-wrap" data-uk-margin>
                <h2 class="uk-margin-remove">{{ 'Menu Cards' | trans }}</h2>
                <div class="uk-margin-left">
                    <span class="uk-badge">{{ menus.length }}</span>
                </div>
            </div>
            <div class="uk-position-relative">
                <button class="uk-button uk-button-primary" @click="showCreateMenuModal">
                    <i class="uk-icon-plus"></i> {{ 'Add Menu' | trans }}
                </button>
            </div>
        </div>

        <!-- Menus List -->
        <div class="uk-grid uk-grid-medium" data-uk-grid-margin>
            <div v-if="menus.length === 0" class="uk-width-1-1">
                <div class="uk-alert">{{ 'No menus found. Create your first menu card!' | trans }}</div>
            </div>
            
            <div v-for="menu in menus" :key="menu.id" class="uk-width-1-1">
                <div class="uk-panel uk-panel-box">
                    <!-- Menu Header -->
                    <div class="uk-panel-title uk-flex uk-flex-space-between">
                        <div>
                            <strong>{{ menu.title }}</strong>
                            <span class="uk-badge" :class="menu.status ? 'uk-badge-success' : 'uk-badge-danger'">
                                {{ menu.status ? 'Published' : 'Draft' | trans }}
                            </span>
                        </div>
                        <div>
                            <button class="uk-button uk-button-small" @click="addCategory(menu)">
                                <i class="uk-icon-plus"></i> Add Category
                            </button>
                            <button class="uk-button uk-button-small uk-button-primary" @click="editMenu(menu)">
                                <i class="uk-icon-pencil"></i>
                            </button>
                            <button class="uk-button uk-button-small uk-button-danger" @click="deleteMenu(menu)">
                                <i class="uk-icon-trash"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="uk-margin-top">
                        <p class="uk-text-muted">{{ menu.description }}</p>
                        <p class="uk-text-small uk-text-muted">Slug: /menucard/{{ menu.slug }}</p>
                    </div>

                    <!-- Categories -->
                    <div v-if="menuCategories[menu.id]" class="uk-margin-top">
                        <h4>Categories</h4>
                        <div v-for="category in menuCategories[menu.id]" :key="category.id" 
                             class="uk-panel uk-panel-box uk-panel-box-secondary uk-margin-small-top">
                            <div class="uk-flex uk-flex-space-between uk-flex-middle">
                                <strong>{{ category.title }}</strong>
                                <div>
                                    <!-- CRITICAL: Context-Aware Product Creation Button -->
                                    <button class="uk-button uk-button-small uk-button-success" 
                                            @click="showCreateProductInContext(category)">
                                        <i class="uk-icon-magic"></i> {{ 'Create Product Here' | trans }}
                                    </button>
                                    <button class="uk-button uk-button-small" @click="attachExistingProduct(category)">
                                        <i class="uk-icon-link"></i> {{ 'Add Existing' | trans }}
                                    </button>
                                    <button class="uk-button uk-button-small uk-button-danger" 
                                            @click="deleteCategory(category)">
                                        <i class="uk-icon-trash"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Products in Category -->
                            <div v-if="category.products && category.products.length > 0" class="uk-margin-small-top">
                                <table class="uk-table uk-table-condensed">
                                    <tbody>
                                        <tr v-for="product in category.products" :key="product.id">
                                            <td>{{ product.name }}</td>
                                            <td class="uk-text-right">€{{ product.price }}</td>
                                            <td class="uk-text-right uk-table-width-minimum">
                                                <button class="uk-button uk-button-small uk-button-danger" 
                                                        @click="detachProduct(category, product)">
                                                    <i class="uk-icon-chain-broken"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else class="uk-margin-small-top uk-text-muted uk-text-small">
                                No products in this category yet.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Menu Create/Edit Modal -->
        <v-modal v-model="showMenuModal" :large="true">
            <div class="uk-modal-header">
                <h3>{{ editingMenu ? 'Edit Menu' : 'Create Menu' | trans }}</h3>
            </div>
            <div class="uk-modal-body">
                <form class="uk-form uk-form-stacked">
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Title' | trans }}</label>
                        <div class="uk-form-controls">
                            <input type="text" class="uk-width-1-1" v-model="menuForm.title" required>
                        </div>
                    </div>
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Slug' | trans }}</label>
                        <div class="uk-form-controls">
                            <input type="text" class="uk-width-1-1" v-model="menuForm.slug">
                        </div>
                    </div>
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Description' | trans }}</label>
                        <div class="uk-form-controls">
                            <textarea class="uk-width-1-1" rows="3" v-model="menuForm.description"></textarea>
                        </div>
                    </div>
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Status' | trans }}</label>
                        <div class="uk-form-controls">
                            <select class="uk-width-1-1" v-model="menuForm.status">
                                <option :value="0">Draft</option>
                                <option :value="1">Published</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="uk-modal-footer uk-text-right">
                <button class="uk-button uk-button-link uk-modal-close" @click="closeMenuModal">
                    {{ 'Cancel' | trans }}
                </button>
                <button class="uk-button uk-button-primary" @click="saveMenu">
                    {{ 'Save' | trans }}
                </button>
            </div>
        </v-modal>

        <!-- Category Create Modal -->
        <v-modal v-model="showCategoryModal">
            <div class="uk-modal-header">
                <h3>{{ 'Add Category' | trans }}</h3>
            </div>
            <div class="uk-modal-body">
                <form class="uk-form uk-form-stacked">
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Title' | trans }}</label>
                        <div class="uk-form-controls">
                            <input type="text" class="uk-width-1-1" v-model="categoryForm.title" required>
                        </div>
                    </div>
                </form>
            </div>
            <div class="uk-modal-footer uk-text-right">
                <button class="uk-button uk-button-link uk-modal-close" @click="closeCategoryModal">
                    {{ 'Cancel' | trans }}
                </button>
                <button class="uk-button uk-button-primary" @click="saveCategory">
                    {{ 'Save' | trans }}
                </button>
            </div>
        </v-modal>

        <!-- CRITICAL: Context-Aware Product Creation Modal -->
        <v-modal v-model="showContextProductModal" :large="true">
            <div class="uk-modal-header">
                <h3>
                    <i class="uk-icon-magic"></i> 
                    {{ 'Create Product for' | trans }}: <strong>{{ contextCategory ? contextCategory.title : '' }}</strong>
                </h3>
            </div>
            <div class="uk-modal-body">
                <div class="uk-alert uk-alert-success">
                    <i class="uk-icon-info-circle"></i> 
                    {{ 'This product will be created globally and automatically added to this category.' | trans }}
                </div>
                <form class="uk-form uk-form-stacked">
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Product Name' | trans }}</label>
                        <div class="uk-form-controls">
                            <input type="text" class="uk-width-1-1" v-model="contextProductForm.name" required>
                        </div>
                    </div>
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Price' | trans }}</label>
                        <div class="uk-form-controls">
                            <input type="number" step="0.01" class="uk-width-1-1" 
                                   v-model="contextProductForm.price" required>
                        </div>
                    </div>
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Description' | trans }}</label>
                        <div class="uk-form-controls">
                            <textarea class="uk-width-1-1" rows="3" 
                                      v-model="contextProductForm.description"></textarea>
                        </div>
                    </div>
                    <div class="uk-form-row">
                        <label class="uk-form-label">{{ 'Image URL' | trans }}</label>
                        <div class="uk-form-controls">
                            <input type="text" class="uk-width-1-1" v-model="contextProductForm.image">
                        </div>
                    </div>
                </form>
            </div>
            <div class="uk-modal-footer uk-text-right">
                <button class="uk-button uk-button-link uk-modal-close" @click="closeContextProductModal">
                    {{ 'Cancel' | trans }}
                </button>
                <button class="uk-button uk-button-success" @click="createProductInContext">
                    <i class="uk-icon-magic"></i> {{ 'Create & Add to Category' | trans }}
                </button>
            </div>
        </v-modal>

        <!-- Attach Existing Product Modal -->
        <v-modal v-model="showAttachProductModal">
            <div class="uk-modal-header">
                <h3>{{ 'Attach Existing Product' | trans }}</h3>
            </div>
            <div class="uk-modal-body">
                <div v-if="availableProducts.length === 0" class="uk-alert">
                    {{ 'No products available. Create a product first.' | trans }}
                </div>
                <div v-else>
                    <select class="uk-width-1-1" v-model="selectedProductId" size="10">
                        <option v-for="product in availableProducts" :key="product.id" :value="product.id">
                            {{ product.name }} - €{{ product.price }}
                        </option>
                    </select>
                </div>
            </div>
            <div class="uk-modal-footer uk-text-right">
                <button class="uk-button uk-button-link uk-modal-close" @click="closeAttachProductModal">
                    {{ 'Cancel' | trans }}
                </button>
                <button class="uk-button uk-button-primary" @click="attachSelectedProduct" 
                        :disabled="!selectedProductId">
                    {{ 'Attach' | trans }}
                </button>
            </div>
        </v-modal>
    </div>
</template>

<script>
export default {
    name: 'MenuList',

    data() {
        return {
            menus: [],
            menuCategories: {},
            showMenuModal: false,
            showCategoryModal: false,
            showContextProductModal: false,
            showAttachProductModal: false,
            editingMenu: null,
            currentMenu: null,
            contextCategory: null,
            menuForm: {
                title: '',
                slug: '',
                description: '',
                status: 0
            },
            categoryForm: {
                title: ''
            },
            contextProductForm: {
                name: '',
                price: 0,
                description: '',
                image: ''
            },
            availableProducts: [],
            selectedProductId: null
        };
    },

    created() {
        console.log('MenuList component created');
        this.loadMenus();
    },

    methods: {
        loadMenus() {
            console.log('Loading menus...');
            this.$http.get('/api/menucards/menu').then(response => {
                this.menus = response.data.menus || [];
                console.log(`Loaded ${this.menus.length} menus`);
                
                // Load categories for each menu
                this.menus.forEach(menu => {
                    this.loadMenuCategories(menu.id);
                });
            }).catch(error => {
                console.error('Error loading menus:', error);
                this.$notify('Failed to load menus', 'danger');
            });
        },

        loadMenuCategories(menuId) {
            console.log(`Loading categories for menu ${menuId}`);
            this.$http.get(`/api/menucards/menu/${menuId}`).then(response => {
                this.$set(this.menuCategories, menuId, response.data.categories || []);
                console.log(`Loaded ${response.data.categories.length} categories for menu ${menuId}`);
            }).catch(error => {
                console.error(`Error loading categories for menu ${menuId}:`, error);
            });
        },

        showCreateMenuModal() {
            console.log('Opening create menu modal');
            this.editingMenu = null;
            this.menuForm = {
                title: '',
                slug: '',
                description: '',
                status: 0
            };
            this.showMenuModal = true;
        },

        editMenu(menu) {
            console.log('Editing menu:', menu.id);
            this.editingMenu = menu;
            this.menuForm = {
                title: menu.title,
                slug: menu.slug,
                description: menu.description,
                status: menu.status
            };
            this.showMenuModal = true;
        },

        saveMenu() {
            console.log('Saving menu...');
            
            if (!this.menuForm.title) {
                this.$notify('Please enter a title', 'warning');
                return;
            }

            const url = this.editingMenu 
                ? `/api/menucards/menu/${this.editingMenu.id}` 
                : '/api/menucards/menu';
            
            this.$http.post(url, { menu: this.menuForm }).then(response => {
                console.log('Menu saved successfully');
                this.$notify(response.data.message, 'success');
                this.closeMenuModal();
                this.loadMenus();
            }).catch(error => {
                console.error('Error saving menu:', error);
                this.$notify('Failed to save menu', 'danger');
            });
        },

        deleteMenu(menu) {
            console.log('Deleting menu:', menu.id);
            
            if (!confirm(`Are you sure you want to delete the menu "${menu.title}"?`)) {
                return;
            }

            this.$http.delete(`/api/menucards/menu/${menu.id}`).then(response => {
                console.log('Menu deleted successfully');
                this.$notify(response.data.message, 'success');
                this.loadMenus();
            }).catch(error => {
                console.error('Error deleting menu:', error);
                this.$notify('Failed to delete menu', 'danger');
            });
        },

        addCategory(menu) {
            console.log('Adding category to menu:', menu.id);
            this.currentMenu = menu;
            this.categoryForm = { title: '' };
            this.showCategoryModal = true;
        },

        saveCategory() {
            console.log('Saving category...');
            
            if (!this.categoryForm.title) {
                this.$notify('Please enter a category title', 'warning');
                return;
            }

            this.$http.post(`/api/menucards/menu/${this.currentMenu.id}/category`, {
                category: this.categoryForm
            }).then(response => {
                console.log('Category saved successfully');
                this.$notify(response.data.message, 'success');
                this.closeCategoryModal();
                this.loadMenuCategories(this.currentMenu.id);
            }).catch(error => {
                console.error('Error saving category:', error);
                this.$notify('Failed to save category', 'danger');
            });
        },

        deleteCategory(category) {
            console.log('Deleting category:', category.id);
            
            if (!confirm(`Are you sure you want to delete the category "${category.title}"?`)) {
                return;
            }

            this.$http.delete(`/api/menucards/menu/category/${category.id}`).then(response => {
                console.log('Category deleted successfully');
                this.$notify(response.data.message, 'success');
                this.loadMenuCategories(category.menu_id);
            }).catch(error => {
                console.error('Error deleting category:', error);
                this.$notify('Failed to delete category', 'danger');
            });
        },

        /**
         * CRITICAL FEATURE: Context-Aware Product Creation
         * Opens modal to create product directly from category view
         */
        showCreateProductInContext(category) {
            console.log('Opening context-aware product creation for category:', category.id);
            this.contextCategory = category;
            this.contextProductForm = {
                name: '',
                price: 0,
                description: '',
                image: ''
            };
            this.showContextProductModal = true;
        },

        /**
         * CRITICAL FEATURE: Create Product and Auto-Attach to Category
         * This is the key innovation - product created globally + auto-assigned
         */
        createProductInContext() {
            console.log('Creating product in context of category:', this.contextCategory.id);
            
            // Validation
            if (!this.contextProductForm.name || this.contextProductForm.price <= 0) {
                this.$notify('Please fill all required fields', 'warning');
                return;
            }

            // Step 1: Create product globally
            this.$http.post('/api/menucards/product', { 
                product: this.contextProductForm 
            }).then(response => {
                console.log('Product created successfully:', response.data.product.id);
                const newProduct = response.data.product;

                // Step 2: Attach product to current category
                return this.$http.post(
                    `/api/menucards/menu/category/${this.contextCategory.id}/product/${newProduct.id}`
                );
            }).then(response => {
                console.log('Product attached to category successfully');
                this.$notify('Product created and added to category!', 'success');
                
                // Step 3: Reload category to show new product WITHOUT full page reload
                this.loadMenuCategories(this.contextCategory.menu_id);
                this.closeContextProductModal();
            }).catch(error => {
                console.error('Error in context product creation:', error);
                this.$notify('Failed to create product', 'danger');
            });
        },

        attachExistingProduct(category) {
            console.log('Attaching existing product to category:', category.id);
            this.contextCategory = category;
            
            // Load all products
            this.$http.get('/api/menucards/product').then(response => {
                this.availableProducts = response.data.products || [];
                this.selectedProductId = null;
                this.showAttachProductModal = true;
            }).catch(error => {
                console.error('Error loading products:', error);
                this.$notify('Failed to load products', 'danger');
            });
        },

        attachSelectedProduct() {
            if (!this.selectedProductId) return;

            console.log(`Attaching product ${this.selectedProductId} to category ${this.contextCategory.id}`);
            
            this.$http.post(
                `/api/menucards/menu/category/${this.contextCategory.id}/product/${this.selectedProductId}`
            ).then(response => {
                console.log('Product attached successfully');
                this.$notify(response.data.message, 'success');
                this.closeAttachProductModal();
                this.loadMenuCategories(this.contextCategory.menu_id);
            }).catch(error => {
                console.error('Error attaching product:', error);
                this.$notify('Failed to attach product', 'danger');
            });
        },

        detachProduct(category, product) {
            console.log(`Detaching product ${product.id} from category ${category.id}`);
            
            if (!confirm(`Remove "${product.name}" from this category?`)) {
                return;
            }

            this.$http.delete(
                `/api/menucards/menu/category/${category.id}/product/${product.id}`
            ).then(response => {
                console.log('Product detached successfully');
                this.$notify(response.data.message, 'success');
                this.loadMenuCategories(category.menu_id);
            }).catch(error => {
                console.error('Error detaching product:', error);
                this.$notify('Failed to detach product', 'danger');
            });
        },

        closeMenuModal() {
            this.showMenuModal = false;
            this.editingMenu = null;
        },

        closeCategoryModal() {
            this.showCategoryModal = false;
            this.currentMenu = null;
        },

        closeContextProductModal() {
            this.showContextProductModal = false;
            this.contextCategory = null;
        },

        closeAttachProductModal() {
            this.showAttachProductModal = false;
            this.contextCategory = null;
            this.selectedProductId = null;
        }
    }
};
</script>
