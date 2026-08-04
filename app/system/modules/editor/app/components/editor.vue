<template>
  <div :class="['pk-editor', { 'uk-invisible': !ready }]">
    <textarea
      ref="editor"
      v-model="content"
      autocomplete="off"
      :style="{ height: height + 'px' }"
      :class="{ 'uk-invisible': !show }"
    />
  </div>
</template>

<script>
// Utils
import ImagePicker from './image-picker.vue';
import VideoPicker from './video-picker.vue';
import LinkPicker from './link-picker.vue';

// Codemirror
import EditorCode from './editor-code';

// HTMLEditor
import EditorHtml from './htmleditor/editor-html';
import EditorHtmlPluginLink from './htmleditor/link';
import EditorHtmlPluginImage from './htmleditor/image';
import EditorHtmlPluginVideo from './htmleditor/video';
import EditorHtmlPluginUrl from './htmleditor/url';

const VEditor = {
  components: {
    'editor-textarea': {
      created() {
        this.$emit('ready');
        this.$set(this.$parent, 'show', true);
      }
    },

    'editor-code': EditorCode
  },

  props: ['type', 'value', 'options'],

  data() {
    return {
      editor: {},
      height: 500,
      show: false,
      ready: false,
      // TODO
      content: this.value
    };
  },

  // TODO
  editors: {
    html: {
      'editor-html': EditorHtml,
      'plugin-link': EditorHtmlPluginLink,
      'plugin-image': EditorHtmlPluginImage,
      'plugin-video': EditorHtmlPluginVideo,
      'plugin-url': EditorHtmlPluginUrl
    },
    code: { 'editor-html': EditorCode }
  },

  computed: {
    editorType() {
      return this.type || window.$pagekit.editor.editor || 'textarea';
    }
  },

  watch: {
    value(content) {
      this.$set(this, 'content', content);
    },

    content(content) {
      this.$emit('input', content);
      this.$emit('editor-update', content);
    }
  },

  created() {
    this.createEditor();
    this.$on('hook:mounted', this.init);
  },

  methods: {
    createEditor() {
      const editors = Object.keys(this.$options.editors);

      if (editors.indexOf(this.editorType) !== -1) {
        _.extend(this.$options.components, this.$options.editors[this.editorType]);
      }
    },

    init() {
      if (this.options && this.options.height) {
        this.height = this.options.height;
      }

      if (this.$el.hasAttributes()) {
        const attrs = this.$el.attributes;

        for (let i = attrs.length - 1; i >= 0; i--) {
          if (attrs[i].name !== 'class') {
            this.$refs.editor.setAttribute(attrs[i].name, attrs[i].value);
            this.$el.removeAttribute(attrs[i].name);
          }
        }
      }

      const components = this.$options.components;
      const type = `editor-${this.type}`;
      const self = this;
      const EditorComponent =
        components[type] || components['editor-html'] || components['editor-textarea'];

      const Editor = Vue.extend(EditorComponent);

      new Editor({ parent: this }).$on('ready', function () {
        _.forIn(
          self.$options.components,
          Component => {
            if (Component.plugin) {
              const Plugin = Vue.extend(Component);
              new Plugin({ parent: self });
            }
          },
          this
        );
      });
    }
  },

  utils: {
    'image-picker': Vue.extend(ImagePicker),
    'video-picker': Vue.extend(VideoPicker),
    'link-picker': Vue.extend(LinkPicker)
  }
};

Vue.component('VEditor', resolve => {
  resolve(VEditor);
});

export default VEditor;
</script>
