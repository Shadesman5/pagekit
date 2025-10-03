/**
 * Menucards list Vue app
 */
const MenucardsList = {

    el: '#menucards',

    data() {
        return _.merge({
            menus: [],
            pages: 0,
            count: 0,
            editingMenu: {},
            config: {
                filter: {
                    search: '',
                    order: 'title asc',
                    status: ''
                },
                page: 0
            }
        }, window.$data);
    },

    created() {
        // Debug: Component created
        console.log('[Menucards] Menucards list component created');
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

    methods: {

        /**
         * Load menus from API
         */
        load() {
            console.log('[Menucards] Loading menus...', this.config);
            
            this.$http.get('api/menucards/menu', { params: this.config }).then(function (res) {
                const data = res.data;
                
                this.$set('menus', data.menus);
                this.$set('pages', data.pages);
                this.$set('count', data.count);
                
                console.log('[Menucards] Loaded ' + data.menus.length + ' menus');
            }, function (res) {
                console.error('[Menucards] Error loading menus:', res);
                this.$notify(res.data, 'danger');
            });
        },

        /**
         * Add new menu
         */
        addMenu() {
            console.log('[Menucards] Add menu clicked');
            
            this.editingMenu = {
                title: '',
                slug: '',
                description: '',
                status: 0
            };
            
            this.$refs.editmodal.open();
        },

        /**
         * Save menu (quick save from modal)
         */
        saveMenu(menu) {
            console.log('[Menucards] Saving menu:', menu);
            
            this.$http.post('api/menucards/menu' + (menu.id ? '/' + menu.id : ''), { menu: menu }).then(function (res) {
                console.log('[Menucards] Menu saved:', res.data);
                this.$notify('Menu saved.');
                this.$refs.editmodal.close();
                this.load();
            }, function (res) {
                console.error('[Menucards] Error saving menu:', res);
                this.$notify(res.data, 'danger');
            });
        },

        /**
         * Remove menu
         */
        removeMenu(menu) {
            console.log('[Menucards] Removing menu:', menu.id);
            
            this.$http.delete('api/menucards/menu/' + menu.id).then(function () {
                console.log('[Menucards] Menu removed');
                this.$notify('Menu deleted.');
                this.load();
            }, function (res) {
                console.error('[Menucards] Error removing menu:', res);
                this.$notify(res.data, 'danger');
            });
        },

        /**
         * Get status text
         */
        getStatusText(status) {
            const statuses = {
                0: this.$trans('Unpublished'),
                1: this.$trans('Published'),
                2: this.$trans('Draft')
            };
            return statuses[status] || this.$trans('Unknown');
        }

    },

    components: {

        /**
         * Menu edit modal component (simple version)
         */
        'menu-edit-modal': {
            props: ['menu'],
            template: `
                <div>
                    <div class="uk-modal-header">
                        <h2 v-if="menu.id">{{ 'Edit Menu' | trans }}</h2>
                        <h2 v-else>{{ 'Add Menu' | trans }}</h2>
                    </div>
                    <div class="uk-form uk-form-stacked">
                        <div class="uk-form-row">
                            <label class="uk-form-label">{{ 'Title' | trans }}</label>
                            <div class="uk-form-controls">
                                <input type="text" class="uk-width-1-1" v-model="menu.title" required>
                            </div>
                        </div>
                        <div class="uk-form-row">
                            <label class="uk-form-label">{{ 'Slug' | trans }}</label>
                            <div class="uk-form-controls">
                                <input type="text" class="uk-width-1-1" v-model="menu.slug">
                            </div>
                        </div>
                        <div class="uk-form-row">
                            <label class="uk-form-label">{{ 'Description' | trans }}</label>
                            <div class="uk-form-controls">
                                <textarea class="uk-width-1-1" rows="3" v-model="menu.description"></textarea>
                            </div>
                        </div>
                        <div class="uk-form-row">
                            <label class="uk-form-label">{{ 'Status' | trans }}</label>
                            <div class="uk-form-controls">
                                <select class="uk-width-1-1" v-model="menu.status" number>
                                    <option :value="0">{{ 'Unpublished' | trans }}</option>
                                    <option :value="1">{{ 'Published' | trans }}</option>
                                    <option :value="2">{{ 'Draft' | trans }}</option>
                                </select>
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
                    if (!this.menu.title) {
                        this.$notify('Menu title is required', 'danger');
                        return;
                    }
                    this.$parent.$emit('save', this.menu);
                }
            }
        }

    }

};

export default MenucardsList;

Vue.ready(MenucardsList);
