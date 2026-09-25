// A failed read or a payload that is not the three-part answer leaves the
// proceed control out, the same as a non-empty blockers list. The server still
// refuses if that control is reached.
export default {
  data() {
    return {
      impact: null,
      impactFailed: false
    };
  },

  computed: {
    canProceed() {
      return this.answer(this.impact) && this.impact.blockers.length === 0;
    }
  },

  methods: {
    readImpact(name) {
      this.impact = null;
      this.impactFailed = false;

      return this.$http.post('admin/system/package/impact', { name }).then(
        response => {
          const data = response && response.data;

          if (!this.answer(data)) {
            this.impactFailed = true;
            return;
          }

          this.impact = data;
        },
        () => {
          this.impactFailed = true;
        }
      );
    },

    answer(data) {
      const risk = data && data.dataRisk;

      return Boolean(
        data &&
        Array.isArray(data.blockers) &&
        Array.isArray(data.orphans) &&
        risk &&
        typeof risk.migrations === 'boolean' &&
        typeof risk.config === 'boolean' &&
        Array.isArray(risk.nodes) &&
        Array.isArray(risk.tables)
      );
    }
  }
};
