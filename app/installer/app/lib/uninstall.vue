<template>
  <v-modal ref="output" :options="options">
    <div class="uk-modal-header uk-flex uk-flex-middle">
      <h2 class="uk-h4">{{ heading }}</h2>
    </div>

    <div v-if="stage == 'confirm'" class="uk-modal-body">
      <p>
        {{
          'A snapshot is taken first: the files of %title% %version%, and the database as it stands now.'
            | trans({ title: pkg.title, version: pkg.version })
        }}
      </p>
      <p>
        {{
          'The package is then taken out of this installation. It leaves this list, the site stops loading it, and its pages go to the trash. Its database tables are left as they are.'
            | trans
        }}
      </p>
      <p>
        {{
          'All of that can be put back from the snapshot until the snapshot is purged, which happens when its retention window runs out or when you purge it by hand.'
            | trans
        }}
      </p>
    </div>

    <div v-else class="uk-modal-body">
      <pre v-show="showOutput" class="pk-pre uk-text-break" uk-overflow-auto v-html="output" />

      <div v-show="status == 'loading'" class="uk-alert uk-flex uk-flex-middle uk-background-muted">
        <v-loader />
        <span v-show="!showOutput" class="uk-margin-small-left">{{ 'Prepare' | trans }}...</span>
        <span v-show="showOutput" class="uk-margin-small-left"
          >{{
            'Removing %title% %version%' | trans({ title: pkg.title, version: pkg.version })
          }}...</span
        >
      </div>

      <div v-show="status == 'success'" class="uk-alert uk-alert-success uk-margin-remove">
        {{
          'Successfully removed. %title% is kept in a snapshot and can be restored from the snapshots page.'
            | trans({ title: pkg.title })
        }}
      </div>
      <div v-show="status == 'error'" class="uk-alert uk-alert-danger uk-margin-remove">
        {{ 'Error' | trans }}
      </div>

      <div v-show="warnings.length" class="uk-alert uk-alert-warning uk-margin-small-top">
        <ul class="uk-list uk-margin-remove">
          <li v-for="(warning, key) in warnings" :key="key">{{ warning }}</li>
        </ul>
      </div>
    </div>

    <div v-if="stage == 'confirm'" class="uk-modal-footer uk-text-right">
      <a class="uk-button uk-button-text uk-margin-right" @click.prevent="close">{{
        'Cancel' | trans
      }}</a>
      <a class="uk-button uk-button-danger" @click.prevent="confirm">{{ 'Remove' | trans }}</a>
    </div>

    <div v-else v-show="status != 'loading'" class="uk-modal-footer uk-text-right">
      <a class="uk-button uk-button-text uk-margin-right" @click.prevent="close">{{
        'Close' | trans
      }}</a>
      <a
        v-show="status == 'success'"
        class="uk-button uk-button-primary"
        :href="$url.route('admin/system/snapshot')"
        >{{ 'Snapshots' | trans }}</a
      >
    </div>
  </v-modal>
</template>

<script>
import Output from './output';

export default {
  mixins: [Output],

  data() {
    return {
      // Removing a package is not a click any more: the first stage says what
      // the removal does and what it leaves behind, and the second one is the
      // removal itself.
      stage: 'confirm',
      packages: null
    };
  },

  computed: {
    heading() {
      const pkg = { title: this.pkg.title, version: this.pkg.version };

      return this.stage === 'confirm'
        ? this.$trans('Remove %title% %version%?', pkg)
        : this.$trans('Removing %title% %version%', pkg);
    }
  },

  methods: {
    // Opens the confirm stage. Nothing is removed until it is confirmed.
    uninstall(pkg, packages) {
      this.$set(this, 'pkg', pkg);
      this.$set(this, 'packages', packages);

      this.open();
    },

    confirm() {
      const self = this;

      this.stage = 'progress';

      return this.$http
        .get('admin/system/package/uninstall', {
          params: { name: this.pkg.name },
          progress() {
            self.init(this);
          }
        })
        .then(
          () => {
            if (this.status === 'success' && this.packages) {
              this.packages.splice(this.packages.indexOf(this.pkg), 1);
            }
          },
          msg => {
            this.$notify(msg.data, 'danger');
            this.close();
          }
        );
    }
  }
};
</script>
