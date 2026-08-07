<template>
  <div>
    <div class="uk-margin uk-flex uk-flex-middle uk-flex-between uk-flex-wrap">
      <div>
        <h2 class="uk-h3 uk-margin-remove">
          {{ 'Misc' | trans }}
        </h2>
      </div>
      <div class="uk-margin-small">
        <button class="uk-button uk-button-primary" type="submit">
          {{ 'Save' | trans }}
        </button>
      </div>
    </div>

    <h3 class="uk-h4 uk-margin-small">
      {{ 'Editor Settings' | trans }}
    </h3>

    <div class="uk-margin-small">
      <label for="form-user-editor" class="uk-form-label">{{ 'Default editor' | trans }}</label>
      <div class="uk-form-controls">
        <select id="form-user-editor" v-model="type" class="uk-select uk-form-width-large">
          <option v-for="(e, i) in $options.editors" :key="i" :value="e.value">
            {{ e.name }}
          </option>
        </select>
      </div>
    </div>
  </div>
</template>

<script>
import SettingsMixin from '../mixins/settings-mixin';

export default {
  mixins: [SettingsMixin],

  section: {
    label: 'Misc',
    icon: 'cog',
    priority: 100
  },

  data() {
    return _.extend(
      {
        type: window.$pagekit.editor.editor || ''
      },
      window.$system
    );
  },

  // TODO
  editors: {
    html: {
      name: 'HTML',
      value: 'html'
    },
    codemirror: {
      name: 'Codemirror',
      value: 'code'
    }
  },

  events: {
    'settings-save': function ($event, settings) {
      const option = { 'system/editor': { editor: this.type } };
      _.extend(settings.options, option);
    }
  }
};
</script>
