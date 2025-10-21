<template>
  <div class="uk-form-horizontal">
    <div class="uk-margin">
      <label for="form-url" class="uk-form-label">{{ 'Url' | trans }}</label>
      <div class="uk-form-controls">
        <input-link
          id="form-url"
          v-model="node.link"
          name="link"
          class-name="uk-form-width-large"
          required="Invalid url."
        />
      </div>
    </div>

    <div class="uk-margin">
      <label for="form-type" class="uk-form-label">{{ 'Type' | trans }}</label>

      <div class="uk-form-controls">
        <select
          id="form-type"
          v-model="behavior"
          class="uk-form-width-large uk-select"
          :disabled="isExternalUrl"
        >
          <option value="link">
            {{ 'Link' | trans }}
          </option>
          <option value="alias">
            {{ 'URL Alias' | trans }}
          </option>
          <option value="redirect">
            {{ 'Redirect' | trans }}
          </option>
        </select>
        <div v-if="isExternalUrl" class="uk-text-muted uk-text-small uk-margin-small-top">
          <span uk-icon="icon: info; ratio: 0.8"></span>
          {{ 'External URLs can only be used with type "Link".' | trans }}
        </div>
      </div>
    </div>

    <component :is="'template-settings'" v-model="node" :roles="roles" />
  </div>
</template>

<script>
import NodeMixin from '../mixins/node-mixin';

export default {
  mixins: [NodeMixin],

  section: {
    label: 'Settings',
    priority: 0,
    active: 'link'
  },

  computed: {
    isExternalUrl() {
      // Check if URL is external (starts with http://, https://, or //)
      return this.node.link && /^(https?:)?\/\//.test(this.node.link);
    },

    behavior: {
      get() {
        // Force "link" type for external URLs
        if (this.isExternalUrl) {
          return 'link';
        }

        if (this.node.data.alias) {
          return 'alias';
        }
        if (this.node.data.redirect) {
          return 'redirect';
        }

        return 'link';
      },

      set(type) {
        // Prevent setting alias/redirect for external URLs
        if (this.isExternalUrl && type !== 'link') {
          return;
        }

        this.$set(
          this.node,
          'data',
          _.extend(this.node.data, {
            alias: type === 'alias',
            redirect: type === 'redirect' ? this.node.link : false
          })
        );
      }
    }
  },

  created() {
    if (this.behavior === 'redirect') {
      this.node.link = this.node.data.redirect;
    }

    if (!this.node.id) {
      this.node.status = 1;
    }
  },

  events: {
    'node-save': function (event, data) {
      if (this.behavior === 'redirect') {
        data.node.data.redirect = data.node.link;
      }
    }
  }
};
</script>
