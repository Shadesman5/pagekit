import settings from '../components/widget-settings.vue';
import visibility from '../components/widget-visibility.vue';
import TemplateSettings from '../components/template-settings';
import { ValidationObserver, VInput } from '../../../../app/components/validation.vue';

const WidgetEdit = {
  name: 'widget',

  el: '#widget-edit',

  mixins: [window.Widgets, Theme.Mixins.Helper],

  provide: {
    $components: {
      'template-settings': TemplateSettings,
      'v-input': VInput
    }
  },

  theme: {
    hideEls: ['#widget-edit > div:first-child'],
    elements() {
      const vm = this;
      return {
        title: {
          scope: 'breadcrumbs',
          type: 'caption',
          caption: () => {
            const { trans } = this.$options.filters;
            return vm.widget.id && trans ? trans('Edit Widget') : trans('Add Widget');
          }
        },
        savewidget: {
          scope: 'topmenu-left',
          type: 'button',
          caption: 'Save',
          class: 'uk-button tm-button-success',
          spinner: () => vm.processing,
          on: { click: () => vm.submit() },
          priority: 1
        },
        close: {
          scope: 'topmenu-left',
          type: 'button',
          caption: () => (vm.widget.id ? 'Close' : 'Cancel'),
          class: 'uk-button uk-button-text',
          attrs: { href: () => vm.$url.route('/admin/site/widget') },
          disabled: () => vm.processing,
          priority: 0
        }
      };
    }
  },

  data() {
    return _.merge({ sections: [], active: 0, processing: false }, window.$data);
  },

  created() {
    let sections = [];
    const type = _.kebabCase(this.widget.type);
    let active;

    _.forIn(this.$options.components, (component, name) => {
      if (component.section) {
        sections.push(_.extend({ name, priority: 0 }, component.section));
      }
    });

    sections = _.sortBy(
      sections.filter(section => {
        const { name } = section;
        active = name.match(/\.[^.]/) && !name.match(/\s/) ? name.match(/(.*(?=\.))\.(.*)/) : null;

        if (active === null) {
          return !_.find(sections, { name: `${type}.${section.name}` });
        }

        return active[1] === type;
      }, this),
      'priority'
    );

    this.$set(this, 'sections', sections);
  },

  mounted() {
    this.tab = UIkit.tab(this.$refs.tab, {
      connect: this.$theme.getDomElement(this.$refs.content)
    });

    const vm = this;

    UIkit.util.on(this.tab.connects, 'show', (e, tab, sel) => {
      if (tab !== vm.tab) return false;
      for (let i = 0; i < Object.keys(tab.toggles).length; i++) {
        const index = Object.keys(tab.toggles)[i];
        if (tab.toggles[index].parentNode.classList.contains('uk-active')) {
          vm.active = index;
          break;
        }
      }
    });

    this.$watch('active', function (active) {
      this.tab.show(active);
    });

    this.$state('active');

    // set position from get param
    if (!this.widget.id) {
      const match = new RegExp('[?&]position=([^&]*)').exec(location.search);
      this.widget.position = (match && decodeURIComponent(match[1].replace(/\+/g, ' '))) || '';
    }
  },

  methods: {
    async submit() {
      const isValid = await this.$refs.observer.validate();
      if (isValid) {
        this.processing = true;
        this.save();
      }
    },

    save() {
      const vm = this;

      this.$trigger('widget-save', { widget: this.widget });

      this.$resource('api/site/widget{/id}')
        .save({ id: this.widget.id }, { widget: this.widget })
        .then(
          function (res) {
            const { data } = res;

            vm.$trigger('saved:widget');

            if (data && data.widget) {
              if (!vm.widget.id) {
                window.history.replaceState(
                  {},
                  '',
                  vm.$url.route('admin/site/widget/edit', { id: data.widget.id })
                );
              }

              vm.$set(vm, 'widget', data.widget);
              vm.$notify('Widget saved.');
            } else {
              console.error('Invalid response from server:', data);
              vm.$notify('Widget saved but response was invalid.', 'warning');
            }

            setTimeout(() => {
              vm.processing = false;
            }, 500);
          },
          function (res) {
            vm.$notify(res.data || 'An error occurred', 'danger');
            vm.processing = false;
          }
        );
    },

    cancel() {
      // TODO
      this.$trigger('widget-cancel');
    }
  },

  components: {
    settings,
    visibility,
    ValidationObserver
  }
};

export default WidgetEdit;

Vue.ready(WidgetEdit);
