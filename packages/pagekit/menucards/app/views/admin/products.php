<?php $view->script('product-list', 'menucards:app/bundle/products.js', ['vue', 'uikit']); ?>

<div id="products" v-cloak>
    
    <div class="uk-margin uk-flex uk-flex-space-between uk-flex-wrap" data-uk-margin>
        <div class="uk-flex uk-flex-middle uk-flex-wrap" data-uk-margin>
            
            <h2 class="uk-margin-remove">{{ 'Products' | trans }}</h2>

            <div class="uk-margin-left" v-show="selected.length">
                <ul class="uk-subnav pk-subnav-icon">
                    <li><a class="pk-icon-delete pk-icon-hover" :title="'Delete' | trans" data-uk-tooltip="{delay: 500}" @click="removeProducts" v-confirm="'Delete selected products?' | trans"></a></li>
                </ul>
            </div>

            <div class="pk-search">
                <div class="uk-search">
                    <input class="uk-search-field" type="search" v-model="config.filter.search" debounce="300">
                </div>
            </div>

        </div>
        <div data-uk-margin>
            
            <button class="uk-button uk-button-primary" type="button" @click="addProduct">{{ 'Add Product' | trans }}</button>

        </div>
    </div>

    <div class="uk-overflow-auto">
        <table class="uk-table uk-table-hover uk-table-middle">
            <thead>
                <tr>
                    <th class="pk-table-width-minimum"><input type="checkbox" v-check-all:selected.literal="input[name=id]" number></th>
                    <th class="pk-table-min-width-200" v-order:name="config.filter.order">{{ 'Name' | trans }}</th>
                    <th class="pk-table-width-100 uk-text-center">{{ 'Price' | trans }}</th>
                    <th class="pk-table-width-200">{{ 'Description' | trans }}</th>
                    <th class="pk-table-width-100">{{ 'Allergens' | trans }}</th>
                    <th class="pk-table-width-100 uk-text-center">{{ 'Actions' | trans }}</th>
                </tr>
            </thead>
            <tbody>
                <tr class="check-item" v-for="product in products" :key="product.id">
                    <td><input type="checkbox" name="id" :value="product.id" number></td>
                    <td>
                        <a @click="editProduct(product)">{{ product.name }}</a>
                    </td>
                    <td class="uk-text-center">
                        <span v-if="product.price">{{ product.price | currency }}</span>
                        <span v-else>-</span>
                    </td>
                    <td>
                        <div class="uk-text-truncate" v-if="product.description">{{ product.description }}</div>
                        <span v-else>-</span>
                    </td>
                    <td>
                        <span v-if="product.allergens">{{ product.allergens }}</span>
                        <span v-else>-</span>
                    </td>
                    <td class="uk-text-center">
                        <ul class="uk-subnav pk-subnav-icon">
                            <li><a class="pk-icon-edit pk-icon-hover" :title="'Edit' | trans" data-uk-tooltip="{delay: 500}" @click="editProduct(product)"></a></li>
                            <li><a class="pk-icon-delete pk-icon-hover" :title="'Delete' | trans" data-uk-tooltip="{delay: 500}" @click="removeProduct(product)" v-confirm="'Delete product?' | trans"></a></li>
                        </ul>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <h3 class="uk-h1 uk-text-muted uk-text-center" v-show="products && !products.length">{{ 'No products found.' | trans }}</h3>

    <v-pagination :page.sync="config.page" :pages="pages" v-show="pages > 1"></v-pagination>

    <!-- Product Edit Modal -->
    <v-modal v-ref:editmodal large>
        <product-edit-modal :product="editingProduct" @save="saveProduct" @cancel="$refs.editmodal.close()"></product-edit-modal>
    </v-modal>

</div>
