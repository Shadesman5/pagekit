<template>
  <div>
    <p v-if="!failed && !impact" class="uk-text-muted">
      {{ 'Checking what else depends on this package.' | trans }}
    </p>
    <p v-else-if="failed" class="uk-text-danger">
      {{ 'What else depends on this package could not be read, so it stays as it is.' | trans }}
    </p>
    <template v-else>
      <p :class="{ 'uk-text-danger': blocked }">{{ blockersText }}</p>
      <p>{{ orphansText }}</p>
      <p>{{ dataRiskText }}</p>
    </template>
  </div>
</template>

<script>
// Blockers, orphans and the data-risk hint are the whole answer. An empty list
// is still an answer, so each one is a sentence either way.
export default {
  props: {
    impact: {
      type: Object,
      default: null
    },
    failed: {
      type: Boolean,
      default: false
    }
  },

  computed: {
    blocked() {
      return this.rawBlockers() > 0;
    },

    blockersText() {
      const blockers = this.names(this.impact.blockers);

      if (this.impact.blockers.length === 0) {
        return this.$trans('No enabled module requires this package.');
      }

      if (blockers.length === 1) {
        return this.$trans('"%blocker%" requires this package, so it cannot be switched off.', {
          blocker: blockers[0]
        });
      }

      if (blockers.length > 1) {
        return this.$trans('"%blockers%" require this package, so it cannot be switched off.', {
          blockers: blockers.join(', ')
        });
      }

      return this.$trans('This package cannot be switched off.');
    },

    orphansText() {
      const orphans = this.names(this.impact.orphans);

      if (orphans.length === 0) {
        return this.$trans('No module would be left with nothing requiring it.');
      }

      if (orphans.length === 1) {
        return this.$trans(
          '"%orphan%" would be left with nothing enabled that requires it. It stays installed.',
          { orphan: orphans[0] }
        );
      }

      return this.$trans(
        '"%orphans%" would be left with nothing enabled that requires them. They stay installed.',
        { orphans: orphans.join(', ') }
      );
    },

    dataRiskText() {
      const risk = this.impact.dataRisk;
      const parts = [];
      const nodes = this.names(risk.nodes);
      const tables = this.names(risk.tables);

      if (risk.migrations) {
        parts.push(this.$trans('This package has database migrations.'));
      }

      if (risk.config) {
        parts.push(this.$trans('This package has saved settings.'));
      }

      if (nodes.length) {
        parts.push(this.$trans('Node types: %nodes%.', { nodes: nodes.join(', ') }));
      }

      if (tables.length) {
        parts.push(
          this.$trans('Tables: %tables%. They are left as they are.', {
            tables: tables.join(', ')
          })
        );
      }

      if (!parts.length) {
        return this.$trans('No migrations, saved settings, node types, or tables were found.');
      }

      return parts.join(' ');
    }
  },

  methods: {
    rawBlockers() {
      const blockers = this.impact && this.impact.blockers;

      return Array.isArray(blockers) ? blockers.length : 0;
    },

    names(values) {
      if (!Array.isArray(values)) {
        return [];
      }

      return values.filter(name => typeof name === 'string' && name !== '');
    }
  }
};
</script>
