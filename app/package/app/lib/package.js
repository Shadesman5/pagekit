import InstallInstance from './install.vue';
import UninstallInstance from './uninstall.vue';
import UpdateInstance from './update.vue';

const Install = Vue.extend(InstallInstance);
const Uninstall = Vue.extend(UninstallInstance);
const Update = Vue.extend(UpdateInstance);

export default {
  methods: {
    queryUpdates(packages, success) {
      const pkgs = {};
      const options = { emulateJSON: true };

      _.each(packages, pkg => {
        pkgs[pkg.name] = pkg.version;
      });

      return this.$http
        .post(`${this.api}/api/package/update`, { packages: JSON.stringify(pkgs) }, options)
        .then(success, this.error);
    },

    enable(pkg) {
      return this.$http.post('admin/system/package/enable', { name: pkg.name }).then(response => {
        // Check if response contains an error (even with 200 status)
        if (response.data && response.data.error) {
          this.$notify(response.data.error, 'danger');
          return;
        }

        this.$notify(this.$trans('"%title%" enabled.', { title: pkg.title }));
        Vue.set(pkg, 'enabled', true);
        document.location.assign(
          this.$url(
            `admin/system/package/${pkg.type === 'pagekit-theme' ? 'themes' : 'extensions'}`
          )
        );
      }, this.error);
    },

    disable(pkg) {
      return this.$http.post('admin/system/package/disable', { name: pkg.name }).then(response => {
        const warnings = (response.data && response.data.warnings) || [];

        this.$notify(this.$trans('"%title%" disabled.', { title: pkg.title }));
        Vue.set(pkg, 'enabled', false);

        // A step of the package's own that did not finish. The page is not
        // reloaded over it: what the reload is for - the menu the extension is
        // out of - is worth less than the line telling the administrator there
        // is something in the log to look at, which a reload would discard.
        if (warnings.length) {
          warnings.forEach(warning => this.$notify(warning, 'warning'));

          return;
        }

        document.location.reload();
      }, this.error);
    },

    install(pkg, packages, onClose, packagist) {
      const install = new Install({ parent: this });

      return install.install(pkg, packages, onClose, packagist);
    },

    update(pkg, updates, onClose, packagist) {
      const update = new Update({ parent: this });

      return update.update(pkg, updates, onClose, packagist);
    },

    // Opens the staged removal: what it does is confirmed there, not here. What
    // it leaves behind is the installation's answer rather than the modal's, so
    // it is handed over with the package.
    uninstall(pkg, packages) {
      const uninstall = new Uninstall({ parent: this });

      return uninstall.uninstall(pkg, packages, this.keepsSnapshots);
    },

    error(response) {
      // Handle different error response formats
      let message = 'An error occurred';

      if (response && response.data) {
        if (typeof response.data === 'string') {
          message = response.data;
        } else if (response.data.error) {
          message = response.data.error;
        } else if (response.data.message) {
          message = response.data.message;
        }
      } else if (typeof response === 'string') {
        message = response;
      }

      this.$notify(message, 'danger');
    }
  }
};
