<template>
  <v-modal ref="output" :options="options">
    <div class="uk-modal-header uk-flex uk-flex-middle">
      <h2 class="uk-h4">{{ heading }}</h2>
    </div>

    <div v-if="stage == 'confirm'" class="uk-modal-body">
      <p v-if="keepsSnapshots">
        {{
          'A snapshot is taken first: the files of %title% %version%, and the database as it stands now.'
            | trans({ title: pkg.title, version: pkg.version })
        }}
      </p>
      <p>
        {{
          'The package is taken out of this installation. It leaves this list, the site stops loading it, and its pages go to the trash. Its database tables are left as they are.'
            | trans
        }}
      </p>
      <p v-if="keepsSnapshots">
        {{
          'All of that can be put back from the snapshot until the snapshot is purged, which happens when its retention window runs out or when you purge it by hand.'
            | trans
        }}
      </p>
      <p v-else class="uk-text-danger">
        {{
          'This installation keeps no snapshots, so nothing is put aside first and none of it can be undone.'
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
        <span v-if="keepsSnapshots">
          {{
            'Successfully removed. %title% is kept in a snapshot and can be restored from the snapshots page.'
              | trans({ title: pkg.title })
          }}
        </span>
        <span v-else>
          {{
            'Successfully removed. This installation keeps no snapshots, so %title% was not kept anywhere and cannot be restored.'
              | trans({ title: pkg.title })
          }}
        </span>
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
        v-show="status == 'success' && keepsSnapshots"
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
      packages: null,
      // Whether this installation puts a removed package aside. Both stages are
      // about what is left afterwards, so both of them turn on it - and an
      // installation that keeps no snapshots may not be told it can restore.
      keepsSnapshots: false,
      // Whether the removal has been asked for. Leaving the confirm stage is
      // what takes the button away, and the page it is on is only redrawn on
      // the next tick, so without this a second click lands on a button that is
      // already gone and starts the whole snapshot-and-remove pipeline a second
      // time against a package the first one is halfway through removing.
      removing: false
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
    uninstall(pkg, packages, keepsSnapshots) {
      this.$set(this, 'pkg', pkg);
      this.$set(this, 'packages', packages);
      this.keepsSnapshots = Boolean(keepsSnapshots);

      this.open();
    },

    confirm() {
      if (this.removing) {
        return;
      }

      const self = this;

      this.removing = true;
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
