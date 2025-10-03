<?php $view->script('menucard-list', 'menucards:app/bundle/menucards.js', ['vue', 'uikit']); ?>

<div id="menucards" v-cloak>
    
    <div class="uk-margin uk-flex uk-flex-space-between uk-flex-wrap" data-uk-margin>
        <div class="uk-flex uk-flex-middle uk-flex-wrap" data-uk-margin>
            
            <h2 class="uk-margin-remove">{{ 'Menucards' | trans }}</h2>

            <div class="pk-search">
                <div class="uk-search">
                    <input class="uk-search-field" type="search" v-model="config.filter.search" debounce="300">
                </div>
            </div>

        </div>
        <div data-uk-margin>
            
            <button class="uk-button uk-button-primary" type="button" @click="addMenu">{{ 'Add Menu' | trans }}</button>

        </div>
    </div>

    <div class="uk-overflow-auto">
        <table class="uk-table uk-table-hover uk-table-middle">
            <thead>
                <tr>
                    <th class="pk-table-min-width-200" v-order:title="config.filter.order">{{ 'Title' | trans }}</th>
                    <th class="pk-table-width-150">{{ 'Slug' | trans }}</th>
                    <th class="pk-table-width-100 uk-text-center">{{ 'Status' | trans }}</th>
                    <th class="pk-table-width-100 uk-text-center">{{ 'Categories' | trans }}</th>
                    <th class="pk-table-width-150 uk-text-center">{{ 'Modified' | trans }}</th>
                    <th class="pk-table-width-100 uk-text-center">{{ 'Actions' | trans }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="menu in menus" :key="menu.id">
                    <td>
                        <a :href="$url.route('@menucards/admin/menu', { id: menu.id })">{{ menu.title }}</a>
                    </td>
                    <td>
                        <code>{{ menu.slug }}</code>
                    </td>
                    <td class="uk-text-center">
                        <span class="uk-badge" :class="{
                            'uk-badge-success': menu.status == 1,
                            'uk-badge-danger': menu.status == 0,
                            'uk-badge-warning': menu.status == 2
                        }">{{ getStatusText(menu.status) }}</span>
                    </td>
                    <td class="uk-text-center">
                        {{ menu.categories ? menu.categories.length : 0 }}
                    </td>
                    <td class="uk-text-center">
                        {{ menu.modified | date }}
                    </td>
                    <td class="uk-text-center">
                        <ul class="uk-subnav pk-subnav-icon">
                            <li><a class="pk-icon-edit pk-icon-hover" :title="'Edit' | trans" data-uk-tooltip="{delay: 500}" :href="$url.route('@menucards/admin/menu', { id: menu.id })"></a></li>
                            <li><a class="pk-icon-copy pk-icon-hover" :title="'View' | trans" data-uk-tooltip="{delay: 500}" :href="'/menucard/' + menu.slug" target="_blank"></a></li>
                            <li><a class="pk-icon-delete pk-icon-hover" :title="'Delete' | trans" data-uk-tooltip="{delay: 500}" @click="removeMenu(menu)" v-confirm="'Delete menu?' | trans"></a></li>
                        </ul>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <h3 class="uk-h1 uk-text-muted uk-text-center" v-show="menus && !menus.length">{{ 'No menus found.' | trans }}</h3>

    <v-pagination :page.sync="config.page" :pages="pages" v-show="pages > 1"></v-pagination>

    <!-- Menu Edit Modal -->
    <v-modal ref="editmodal">
        <menu-edit-modal :menu="editingMenu" @save="saveMenu" @cancel="$refs.editmodal.close()"></menu-edit-modal>
    </v-modal>

</div>
