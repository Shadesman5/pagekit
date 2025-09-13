window.$settings = {
    el: '#settings',

    data() {
        return {
            config: window.$data.config
        };
    },

    methods: {
        save() {
            this.$http
                .post('admin/system/settings/config', { name: 'hello', config: this.config })
                .then(() => this.$notify('Settings saved.'))
                .catch(res => this.$notify(res.data, 'danger'));
        }
    }
};

window.addEventListener('load', () => {
    new Vue(window.$settings);
});
