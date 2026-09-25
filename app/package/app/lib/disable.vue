<template>
  <v-modal ref="modal" :options="options">
    <div class="uk-modal-header uk-flex uk-flex-middle">
      <h2 class="uk-h4">
        {{ 'Disable %title%?' | trans({ title: pkg.title }) }}
      </h2>
    </div>

    <div class="uk-modal-body">
      <p>{{ 'The site stops loading it. Its files stay installed.' | trans }}</p>
      <package-impact :impact="impact" :failed="impactFailed" />
      <div v-if="switching" class="uk-alert uk-flex uk-flex-middle uk-background-muted">
        <v-loader />
        <span class="uk-margin-small-left">{{
          'Disabling %title%.' | trans({ title: pkg.title })
        }}</span>
      </div>
    </div>

    <div v-if="!switching" class="uk-modal-footer uk-text-right">
      <a class="uk-button uk-button-text uk-margin-right" @click.prevent="close">{{
        'Cancel' | trans
      }}</a>
      <a v-if="canProceed" class="uk-button uk-button-danger" @click.prevent="confirm">{{
        'Disable' | trans
      }}</a>
    </div>
  </v-modal>
</template>

<script>
import ImpactQuery from './impact-query';
import PackageImpact from './impact.vue';

const { on } = UIkit.util;

export default {
  components: {
    'package-impact': PackageImpact
  },

  mixins: [ImpactQuery],

  data() {
    return {
      pkg: {},
      switching: false,
      onDismiss: null,
      options: () => ({
        bgClose: false,
        escClose: false
      })
    };
  },

  created() {
    this.$mount();
  },

  methods: {
    ask(pkg, onDismiss) {
      this.$set(this, 'pkg', pkg);
      this.onDismiss = onDismiss;
      this.readImpact(pkg.name);
      this.open();
    },

    open() {
      if (this.$refs.modal.opened) {
        return;
      }

      this.$refs.modal.open();
      on(this.$refs.modal.modal.$el, 'hidden', this.onClose);
    },

    close() {
      this.$refs.modal.close();
    },

    onClose() {
      if (this.onDismiss) {
        this.onDismiss();
      }

      this.$destroy();
    },

    confirm() {
      if (this.switching || !this.canProceed) {
        return;
      }

      this.switching = true;

      return this.$parent.commitDisable(this.pkg).then(() => {
        this.close();
      });
    }
  }
};
</script>
