import WidgetMixin from '../mixins/widget-mixin';
import WidgetSettings from '../templates/widget-settings.html?raw';

export default {
  name: 'template-settings',

  mixins: [WidgetMixin],

  template: WidgetSettings
};
