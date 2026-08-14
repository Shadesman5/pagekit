const { on } = UIkit.util;

export default {
  data() {
    return {
      pkg: {},
      updatePkg: {},
      output: '',
      status: 'loading',
      warnings: [],
      options: () => ({
        bgClose: false,
        escClose: false
      }),
      showOutput: false
    };
  },

  created() {
    this.$mount();
  },

  methods: {
    init(request) {
      const vm = this;

      this.open();

      return vm.setOutput(request.responseText);
    },

    setOutput(output) {
      this.showOutput = true;

      const lines = output.split('\n');
      const match = lines[lines.length - 1].match(/^status=(success|error)$/);

      if (match) {
        this.status = match[1];
        lines.pop();
      }

      // A step that failed without failing the operation says so on a line of
      // its own. It is shown as what it is instead of being left in the log,
      // and the whole response is re-read on every progress event, so the set
      // is rebuilt rather than added to.
      const warnings = [];

      this.output = lines
        .filter(line => {
          const warning = line.match(/^warning=(.+)$/);

          if (warning) {
            warnings.push(warning[1]);
          }

          return !warning;
        })
        .join('\n');

      this.warnings = warnings;
    },

    open() {
      if (this.$refs.output.opened) return;

      this.$refs.output.open();
      on(this.$refs.output.modal.$el, 'hidden', this.onClose);
    },

    close() {
      this.$refs.output.close();
    },

    onClose() {
      if (this.cb) {
        this.cb(this);
      }

      this.$destroy();
    }
  },

  watch: {
    status() {
      if (this.status !== 'loading') {
        // TODO
        // this.$refs.output.modal.$options.props.bgClose = true;
        // this.$refs.output.modal.$options.props.escClose = true;
      }
    }
  }
};
