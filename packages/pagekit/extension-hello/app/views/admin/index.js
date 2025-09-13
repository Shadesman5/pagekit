const HelloAdmin = {
    el: '#hello-admin',

    mixins: [Theme.Mixins.Helper],

    data() {
        return window.$data;
    },

    theme: {
        hideEls: ['#hello-admin > div:first-child'],
        elements() {
            const vm = this;
            return {
                settings: {
                    scope: 'topmenu-left',
                    type: 'button',
                    caption: 'Settings',
                    attrs: { href: vm.$url.route('@hello/admin/settings') },
                    class: 'uk-button uk-button-primary',
                    priority: 0
                }
            };
        }
    }
};

export default HelloAdmin;

Vue.ready(HelloAdmin);
