const UNITS = ['B', 'kB', 'MB', 'GB', 'TB'];

const Snapshots = {
  name: 'snapshots',

  el: '#snapshots',

  data() {
    return _.merge(
      {
        snapshots: [],
        retention: 0,
        // The one a restore is being confirmed for, and whether it has been
        // applied: what this page was rendered from is from before the restore,
        // so once one has run the panel has to be read again.
        snapshot: {},
        restored: false,
        // One operation at a time: a purge racing the restore of the same
        // snapshot would decide by arrival which of the two happened.
        busy: false,
        modalOptions: () => ({
          bgClose: false,
          escClose: false
        })
      },
      window.$data
    );
  },

  computed: {
    retentionText() {
      return this.retention > 0
        ? this.$trans(
            'A snapshot is kept for %days% days, after which a purge may reclaim the disk it holds.',
            { days: this.retention }
          )
        : this.$trans('Snapshots are kept until somebody purges them.');
    }
  },

  methods: {
    title(snapshot) {
      return snapshot.title || snapshot.package || '';
    },

    // With the time of day, unlike the retention dates around it: two snapshots
    // of the same package on the same day are told apart by nothing else.
    taken(snapshot) {
      return snapshot.created ? this.$date(snapshot.created * 1000, 'short') : '';
    },

    expires(snapshot) {
      return snapshot.expires
        ? this.$date(snapshot.expires * 1000)
        : this.$trans('When purged by hand');
    },

    size(snapshot) {
      let bytes = snapshot.size || 0;
      let unit = 0;

      while (bytes >= 1024 && unit < UNITS.length - 1) {
        bytes /= 1024;
        unit += 1;
      }

      return `${this.$number(bytes, unit ? 1 : 0)} ${UNITS[unit]}`;
    },

    confirmRestore(snapshot) {
      this.$set(this, 'snapshot', snapshot);
      this.restored = false;

      this.$refs.restore.open();
    },

    closeRestore() {
      this.$refs.restore.close();
    },

    restore() {
      if (this.busy) {
        return;
      }

      this.busy = true;

      this.$http.post('admin/system/snapshot/restore', { id: this.snapshot.id }).then(response => {
        this.busy = false;

        if (!this.failed(response)) {
          this.restored = true;
        }
      }, this.error);
    },

    purge(snapshot) {
      if (this.busy) {
        return;
      }

      this.busy = true;

      this.$http.post('admin/system/snapshot/purge', { id: snapshot.id }).then(response => {
        this.busy = false;

        if (this.failed(response)) {
          return;
        }

        this.forget([snapshot.id]);
        this.$notify(
          this.$trans('The snapshot of "%title%" is gone.', { title: this.title(snapshot) })
        );
      }, this.error);
    },

    purgeExpired() {
      if (this.busy) {
        return;
      }

      this.busy = true;

      this.$http.post('admin/system/snapshot/purge-expired').then(response => {
        this.busy = false;

        if (this.failed(response)) {
          return;
        }

        const purged = response.data.purged || [];

        this.forget(purged);
        this.$notify(
          this.$transChoice(
            '{0} No snapshot has reached the end of its retention window.|{1} One expired snapshot was purged.|]1,Inf[ %count% expired snapshots were purged.',
            purged.length,
            { count: purged.length }
          )
        );
      }, this.error);
    },

    reload() {
      document.location.reload();
    },

    // A restored installation is not the one this page was rendered from, so
    // whichever way the dialog is left after a restore, the panel is read again.
    restoreClosed() {
      if (this.restored) {
        this.reload();
      }
    },

    // Takes purged snapshots out of the list. Nothing is re-read from the
    // server: what a purge changed is that these are gone.
    forget(ids) {
      this.$set(
        this,
        'snapshots',
        this.snapshots.filter(snapshot => ids.indexOf(snapshot.id) === -1)
      );
    },

    // An operation that ran and did not do what it was asked. The message is the
    // one the server chose: what actually refused is in the error log.
    failed(response) {
      const data = response.data || {};

      if (!data.error) {
        return false;
      }

      this.$notify(data.message, 'danger');

      return true;
    },

    // A request that was refused outright. Its body is an error page rather than
    // this API's answer, so there is no message in it to show.
    error() {
      this.busy = false;
      this.$notify('Whoops, something went wrong.', 'danger');
    }
  }
};

export default Snapshots;

Vue.ready(Snapshots);
