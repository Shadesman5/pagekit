import { closest, trigger } from 'uikit-util';

export default {
  name: 'editor-code',

  created() {
    const baseURL = `${$editor.root_url}/app/assets/codemirror`;

    this.$asset({
      css: [`${baseURL}/show-hint.css`, `${baseURL}/codemirror.css`],
      js: [`${baseURL}/codemirror.min.js`]
    }).then(this.init);
  },

  methods: {
    init() {
      const self = this;
      const $el = this.$parent.$refs.editor;

      this.editor = CodeMirror.fromTextArea(
        $el,
        _.extend(
          {
            mode: 'htmlmixed',
            dragDrop: false,
            autoCloseTags: true,
            matchTags: true,
            autoCloseBrackets: true,
            matchBrackets: true,
            indentUnit: 4,
            indentWithTabs: false,
            tabSize: 4,
            lineNumbers: true,
            lineWrapping: true,
            extraKeys: {
              F11(cm) {
                cm.setOption('fullScreen', !cm.getOption('fullScreen'));
              },
              Esc(cm) {
                if (cm.getOption('fullScreen')) cm.setOption('fullScreen', false);
              }
            }
          },
          this.$parent.options
        )
      );

      this.editor.setSize(null, this.$parent.height - 2);

      this.editor.refresh();

      this.$parent.ready = true;

      this.editor.on('change', () => {
        self.editor.save();
        trigger($el, 'input');
      });

      this.$watch('$parent.content', function (value) {
        if (value !== this.editor.getValue()) {
          this.editor.setValue(value);
          this.editor.refresh();
        }
      });

      this.observe($el);

      this.$emit('ready');
    },

    observe(el) {
      const vm = this;
      const element = closest(el, '.uk-switcher>*');

      if (!element) return;

      const observer = new MutationObserver(() => {
        if (element.style.display !== 'none') {
          vm.editor.refresh();
        }
      });

      observer.observe(element, { attributes: true, childList: true });
    }
  }
};
