import Package from '../lib/package';
import PackageUpload from './package-upload.vue';
import PackageDetails from './package-details.vue';

export default {
  mixins: [Package, Theme.Mixins.Helper],

  data() {
    return _.extend(
      {
        package: {},
        view: false,
        updates: null,
        search: this.$session.get(`${this.$options.name}.search`, ''),
        status: '',
        // Whether a removal from this page can be undone. The server answers it
        // per page; a page rendered without an answer is one whose removals are
        // final, which is the reading that promises nothing.
        keepsSnapshots: false
      },
      window.$data
    );
  },

  theme: {
    hideEls: ['#extensions > div:first-child', '#themes > div:first-child'],
    elements() {
      const vm = this;
      return {
        search: {
          scope: 'navbar-right',
          type: 'search',
          class: 'uk-text-small',
          domProps: { value: () => vm.search || '' },
          on: {
            input(e) {
              !vm.search && vm.$set(vm, 'search', '');
              vm.search = e.target.value;
            }
          }
        }
      };
    }
  },

  mounted() {
    this.load();
  },

  watch: {
    search(search) {
      this.$session.set(`${this.$options.name}.search`, search);
    }
  },

  methods: {
    load() {
      this.$set(this, 'status', 'loading');

      if (this.packages) {
        this.queryUpdates(this.packages).then(
          res => {
            // Handle response safely (API might be unavailable)
            if (res && res.data && res.data.packages) {
              this.$set(
                this,
                'updates',
                res.data.packages.length ? _.keyBy(res.data.packages, 'name') : null
              );
            } else {
              this.$set(this, 'updates', null);
            }
            this.$set(this, 'status', '');
          },
          () => {
            // API unavailable or blocked by CSP - this is expected
            this.$set(this, 'updates', null);
            this.$set(this, 'status', '');
          }
        );
      }
    },

    icon(pkg) {
      if (pkg.extra && pkg.extra.icon) {
        return `${pkg.url}/${pkg.extra.icon}`;
      }
      return this.$url('app/system/assets/images/placeholder-icon.svg');
    },

    image(pkg) {
      if (pkg.extra && pkg.extra.image) {
        return `${pkg.url}/${pkg.extra.image}`;
      }
      return this.$url('app/system/assets/images/placeholder-800x600.svg');
    },

    details(pkg) {
      this.$set(this, 'package', pkg);
      this.$refs.details.open();
    },

    settings(pkg) {
      if (!pkg.settings) {
        return;
      }

      let view;
      let options;

      _.forIn(this.$options.components, (component, name) => {
        options = component.options || {};

        if (options.settings && pkg.settings === name) {
          view = name;
        }
      });

      if (view) {
        this.$set(this, 'package', pkg);
        this.$set(this, 'view', view);
        this.$refs.settings.open();
      } else {
        window.location = pkg.settings;
      }
    },

    empty(packages) {
      return this.filterBy(packages, this.search, 'title').length === 0;
    }
  },

  components: {
    'package-upload': PackageUpload,
    'package-details': PackageDetails
  }
};
