<?php $view->script('menu-edit', 'menucards:app/bundle/menu-edit.js', ['vue', 'uikit']); ?>

<form id="menu-edit" class="uk-form" v-cloak @submit.prevent="save">

    <div class="uk-margin uk-flex uk-flex-space-between uk-flex-wrap" data-uk-margin>
        <div data-uk-margin>

            <h2 class="uk-margin-remove" v-if="menu.id">{{ 'Edit Menu' | trans }}: {{ menu.title }}</h2>
            <h2 class="uk-margin-remove" v-else>{{ 'Add Menu' | trans }}</h2>

        </div>
        <div data-uk-margin>

            <a class="uk-button uk-margin-small-right" :href="$url.route('@menucards/admin')">{{ 'Close' | trans }}</a>
            <button class="uk-button uk-button-primary" type="submit">{{ 'Save' | trans }}</button>

        </div>
    </div>

    <div class="uk-grid" data-uk-grid-margin>
        <div class="uk-width-medium-3-4">

            <div class="uk-form-row">
                <label for="form-title" class="uk-form-label">{{ 'Title' | trans }}</label>
                <div class="uk-form-controls">
                    <input id="form-title" class="uk-width-1-1" type="text" name="title" v-model="menu.title" required>
                </div>
            </div>

            <div class="uk-form-row">
                <label for="form-slug" class="uk-form-label">{{ 'Slug' | trans }}</label>
                <div class="uk-form-controls">
                    <input id="form-slug" class="uk-width-1-1" type="text" name="slug" v-model="menu.slug">
                </div>
            </div>

            <div class="uk-form-row">
                <label for="form-description" class="uk-form-label">{{ 'Description' | trans }}</label>
                <div class="uk-form-controls">
                    <textarea id="form-description" class="uk-width-1-1" name="description" rows="5" v-model="menu.description"></textarea>
                </div>
            </div>

            <!-- Categories -->
            <div class="uk-margin-large-top">
                <h3 class="uk-h2">{{ 'Categories' | trans }}</h3>

                <div v-for="(category, index) in menu.categories" :key="category.id" class="uk-panel uk-panel-box uk-margin">
                    <div class="uk-panel-title uk-flex uk-flex-space-between">
                        <span>{{ category.title }}</span>
                        <a class="uk-text-danger" @click="removeCategory(index)" v-confirm="'Delete category?' | trans">
                            <i class="uk-icon-trash"></i>
                        </a>
                    </div>

                    <div class="uk-margin-small-top">
                        <input class="uk-width-1-1 uk-margin-small-bottom" type="text" v-model="category.title" placeholder="Category Title">
                        <textarea class="uk-width-1-1 uk-margin-small-bottom" v-model="category.description" rows="2" placeholder="Category Description"></textarea>

                        <!-- Products in Category -->
                        <div v-if="category.products && category.products.length" class="uk-margin-top">
                            <table class="uk-table uk-table-condensed">
                                <thead>
                                    <tr>
                                        <th>{{ 'Product' | trans }}</th>
                                        <th class="uk-text-right">{{ 'Price' | trans }}</th>
                                        <th class="uk-text-center uk-table-width-minimum"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(product, pIndex) in category.products" :key="product.id">
                                        <td>{{ product.name }}</td>
                                        <td class="uk-text-right">{{ product.price | currency }}</td>
                                        <td class="uk-text-center">
                                            <a class="uk-icon-hover uk-icon-trash uk-text-danger" @click="removeProductFromCategory(index, pIndex)"></a>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Add Products -->
                        <div class="uk-margin-top">
                            <button type="button" class="uk-button uk-button-small" @click="showProductSelector(index)">
                                <i class="uk-icon-plus"></i> {{ 'Add Existing Product' | trans }}
                            </button>
                            <button type="button" class="uk-button uk-button-small uk-button-primary" @click="showProductCreator(index)">
                                <i class="uk-icon-plus-circle"></i> {{ 'Create New Product' | trans }}
                            </button>
                        </div>
                    </div>
                </div>

                <button type="button" class="uk-button" @click="addCategory">
                    <i class="uk-icon-plus"></i> {{ 'Add Category' | trans }}
                </button>
            </div>

        </div>
        <div class="uk-width-medium-1-4">

            <div class="uk-panel">
                <div class="uk-form-row">
                    <label for="form-status" class="uk-form-label">{{ 'Status' | trans }}</label>
                    <div class="uk-form-controls">
                        <select id="form-status" class="uk-width-1-1" v-model="menu.status" number>
                            <option :value="0">{{ 'Unpublished' | trans }}</option>
                            <option :value="1">{{ 'Published' | trans }}</option>
                            <option :value="2">{{ 'Draft' | trans }}</option>
                        </select>
                    </div>
                </div>

                <div class="uk-form-row" v-if="menu.slug">
                    <label class="uk-form-label">{{ 'Public URL' | trans }}</label>
                    <div class="uk-form-controls">
                        <a :href="'/menucard/' + menu.slug" target="_blank">/menucard/{{ menu.slug }}</a>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Product Selector Modal -->
    <v-modal v-ref:productmodal>
        <product-selector-modal 
            :products="allProducts" 
            @select="addProductToCategory" 
            @cancel="$refs.productmodal.close()">
        </product-selector-modal>
    </v-modal>

    <!-- Product Creator Modal -->
    <v-modal v-ref:creatormodal>
        <product-creator-modal 
            @save="createAndAddProduct" 
            @cancel="$refs.creatormodal.close()">
        </product-creator-modal>
    </v-modal>

</form>
