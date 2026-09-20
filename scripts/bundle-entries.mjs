/**
 * Bundle manifest for the Pagekit JS build.
 *
 * Every group maps a module directory to the entries it ships. Each entry is
 * built on its own into `<dir>/app/bundle/<name>.js`; the paths are a runtime
 * contract (PHP registers the bundles by that exact path via `$view->script()`).
 *
 * `global` mirrors the classic script-tag global a group publishes. Only
 * `Links` is read across bundles (`window.Links.default.components[...]`), but
 * every historical global is kept so no bundle silently changes its surface.
 */

/** Bare imports left out of the bundles, resolved from script-tag globals at runtime. */
export const externals = {
  vue: 'Vue',
  uikit: 'UIkit',
  'uikit-util': 'UIkit.util'
};

/** Import prefixes shared by app and package sources. */
export const aliases = {
  '@installer': 'app/installer',
  '@system': 'app/system'
};

const groups = [
  {
    dir: 'app/installer',
    entries: {
      installer: 'app/views/installer.vue',
      extensions: 'app/views/extensions.js',
      marketplace: 'app/views/marketplace.js',
      snapshots: 'app/views/snapshots.js',
      themes: 'app/views/themes.js',
      update: 'app/views/update.js'
    }
  },
  {
    dir: 'app/modules/debug',
    global: 'Debugbar',
    entries: {
      debugbar: 'app/debugbar.js'
    }
  },
  {
    dir: 'app/system',
    entries: {
      vue: 'app/vue.js'
    }
  },
  {
    dir: 'app/system/modules/cache',
    entries: {
      settings: 'app/components/settings.vue'
    }
  },
  {
    dir: 'app/system/modules/captcha',
    global: 'Captcha',
    entries: {
      'captcha-interceptor': 'app/interceptor.js'
    }
  },
  {
    dir: 'app/system/modules/dashboard',
    entries: {
      index: 'app/views/index.js'
    }
  },
  {
    dir: 'app/system/modules/editor',
    global: 'Editor',
    entries: {
      editor: 'app/components/editor.vue'
    }
  },
  {
    dir: 'app/system/modules/finder',
    global: 'Finder',
    entries: {
      'panel-finder': 'app/components/panel-finder.vue',
      'link-storage': 'app/components/link-storage.vue'
    }
  },
  {
    dir: 'app/system/modules/info',
    entries: {
      info: 'app/views/info.js'
    }
  },
  {
    dir: 'app/system/modules/mail',
    entries: {
      settings: 'app/components/settings.vue'
    }
  },
  {
    dir: 'app/system/modules/settings',
    entries: {
      settings: 'app/settings.js'
    }
  },
  {
    dir: 'app/system/modules/site',
    global: 'Links',
    entries: {
      'panel-link': 'app/components/panel-link.vue'
    }
  },
  {
    dir: 'app/system/modules/site',
    entries: {
      edit: 'app/views/edit.js',
      index: 'app/views/index.js',
      'input-link': 'app/components/input-link.vue',
      'input-tree': 'app/components/input-tree.vue',
      'link-page': 'app/components/link-page.vue',
      'node-page': 'app/components/node-page.vue',
      'node-meta': 'app/components/node-meta.vue',
      settings: 'app/views/settings.js',
      'widget-menu': 'app/components/widget-menu.vue',
      'widget-text': 'app/components/widget-text.vue'
    }
  },
  {
    dir: 'app/system/modules/theme',
    entries: {
      login: 'app/views/login.js',
      theme: 'app/views/theme.js'
    }
  },
  {
    dir: 'app/system/modules/user',
    entries: {
      interceptor: 'app/interceptor.js',
      registration: 'app/views/registration.js',
      'reset-confirm': 'app/views/reset-confirm.js',
      profile: 'app/views/profile.js',
      'permission-index': 'app/views/admin/permission-index.js',
      'role-index': 'app/views/admin/role-index.js',
      settings: 'app/views/admin/settings.js',
      'user-edit': 'app/views/admin/user-edit.js',
      'user-index': 'app/views/admin/user-index.js',
      'widget-login': 'app/components/widget-login.vue',
      'widget-user': 'app/components/widget-user.vue',
      'link-user': 'app/components/link-user.vue'
    }
  },
  {
    dir: 'app/system/modules/widget',
    entries: {
      widgets: 'app/widgets.js',
      edit: 'app/views/edit.js',
      index: 'app/views/index.js'
    }
  },
  {
    dir: 'packages/pagekit/blog',
    entries: {
      'comment-index': 'app/views/admin/comment-index.js',
      'post-edit': 'app/views/admin/post-edit.js',
      'post-index': 'app/views/admin/post-index.js',
      settings: 'app/views/admin/settings.js',
      comments: 'app/views/comments.js',
      post: 'app/views/post.js',
      posts: 'app/views/posts.js',
      'link-blog': 'app/components/link-blog.vue',
      'post-meta': 'app/components/post-meta.vue'
    }
  },
  {
    dir: 'packages/pagekit/theme-one',
    entries: {
      'node-theme': 'app/components/node-theme.vue',
      'site-theme': 'app/components/site-theme.vue',
      'widget-theme': 'app/components/widget-theme.vue'
    }
  }
];

/**
 * @typedef {object} BundleEntry
 * @property {string}      dir     module directory, relative to the repository root
 * @property {string}      name    bundle name, without extension
 * @property {string}      input   entry source, relative to `dir`
 * @property {string}      output  emitted file, relative to `dir`
 * @property {string|null} global  IIFE global the bundle publishes, if any
 */

/** @type {BundleEntry[]} */
export const entries = groups.flatMap(group =>
  Object.entries(group.entries).map(([name, input]) => ({
    dir: group.dir,
    name,
    input,
    output: `app/bundle/${name}.js`,
    global: group.global ?? null
  }))
);
