# Pagekit Selector Reference

## Current Selectors (Legacy → Modern)

### Login Page (`app/system/modules/theme/views/login.php`)

| Element | Current Selector | Recommended `data-testid` |
|---------|------------------|---------------------------|
| Username input | `input[name="credentials[username]"]` | `login-username-input` |
| Password input | `input[name="credentials[password]"]` | `login-password-input` |
| Login button | `.js-login button` | `login-submit-button` |
| Remember checkbox | `input[name="remember_me"]` | `login-remember-checkbox` |
| Reset password link | `a[uk-toggle]` | `login-reset-link` |
| Login form | `.js-login` | `login-form` |
| Reset form | `form[action*="resetpassword"]` | `reset-form` |

### Modal Login (`app/system/modules/user/app/components/modal-login.vue`)

| Element | Current Selector | Recommended `data-testid` |
|---------|------------------|---------------------------|
| Modal | `ref="login"` | `modal-login` |
| Username input | `v-model="credentials.username"` | `modal-login-username` |
| Password input | `v-model="credentials.password"` | `modal-login-password` |
| Submit button | `button.uk-button-primary` | `modal-login-submit` |
| Remember checkbox | `v-model="remember"` | `modal-login-remember` |

### Dashboard (`app/system/modules/dashboard/views/index.php`)

| Element | Current Selector | Recommended `data-testid` |
|---------|------------------|---------------------------|
| Dashboard container | `.dashboard` | `dashboard-container` |
| Widget panels | `.pk-panel` | `dashboard-widget-[name]` |
| Add widget button | TBD | `dashboard-add-widget` |

### User Management (`app/system/modules/user/views/admin/`)

| Page | Element | Recommended `data-testid` |
|------|---------|---------------------------|
| user-index | User list | `user-list` |
| user-index | Add user button | `user-add-button` |
| user-index | User row | `user-row-[id]` |
| user-edit | Name input | `user-edit-name` |
| user-edit | Email input | `user-edit-email` |
| user-edit | Save button | `user-edit-save` |
| role-index | Role list | `role-list` |
| role-index | Add role button | `role-add-button` |

### Site/Pages (`app/system/modules/site/views/admin/`)

| Page | Element | Recommended `data-testid` |
|------|---------|---------------------------|
| index | Page tree | `page-tree` |
| index | Add page button | `page-add-button` |
| edit | Title input | `page-edit-title` |
| edit | Slug input | `page-edit-slug` |
| edit | Content editor | `page-edit-content` |
| edit | Save button | `page-edit-save` |

### Blog (`packages/pagekit/blog/views/admin/`)

| Page | Element | Recommended `data-testid` |
|------|---------|---------------------------|
| post-index | Post list | `blog-post-list` |
| post-index | Add post button | `blog-post-add` |
| post-edit | Title input | `blog-post-title` |
| post-edit | Content editor | `blog-post-content` |
| post-edit | Save button | `blog-post-save` |
| comment-index | Comment list | `blog-comment-list` |

### Finder/Media (`app/system/modules/finder/views/`)

| Element | Recommended `data-testid` |
|---------|---------------------------|
| File list | `finder-file-list` |
| Upload button | `finder-upload-button` |
| Create folder | `finder-create-folder` |
| File item | `finder-file-[name]` |
| Folder item | `finder-folder-[name]` |

### Settings (`app/system/modules/settings/views/`)

| Section | Element | Recommended `data-testid` |
|---------|---------|---------------------------|
| System | Tab | `settings-system-tab` |
| Locale | Tab | `settings-locale-tab` |
| Mail | Tab | `settings-mail-tab` |
| Cache | Tab | `settings-cache-tab` |
| All | Save button | `settings-save-button` |

## Routes Reference

```
User Module:
  /user              → @user (login, profile)
  /user/profile      → @user/profile
  /user/registration → @user/registration
  /user/resetpassword → @user/resetpassword
  /api/user          → @user/api (CRUD)
  /api/user/role     → @user/api/role

Admin Routes:
  /admin             → Dashboard
  /admin/login       → Login page
  /admin/user        → User management
  /admin/site        → Page management
  /admin/settings    → System settings
  /admin/finder      → Media manager

Blog Module:
  /admin/blog        → Post management
  /admin/blog/post   → Edit post
  /admin/blog/comment → Comment management
  /api/blog/post     → Post API
  /api/blog/comment  → Comment API
```

## CSS Class Patterns

### Safe to Use (`.js-*` prefix)

```css
.js-login        /* Login form container */
.js-toggle       /* Toggle visibility elements */
.js-user         /* User-related actions */
```

### Avoid (UIkit classes)

```css
.uk-button       /* Styling only */
.uk-input        /* Styling only */
.uk-modal        /* Use state: .uk-modal.uk-open */
.uk-alert-*      /* Use for assertions, not selection */
```

## Vue Component Refs

Some Vue components expose refs that can be used:

```javascript
// In modal-login.vue
this.$refs.login    // Modal instance
this.$refs.password // Password input

// Usage in test (via page.evaluate if needed)
await page.evaluate(() => {
  const vm = document.querySelector('.modal-login').__vue__;
  return vm.$refs.login;
});
```

## Recommended Implementation Order

Priority for adding `data-testid`:

1. **Critical Path** - Login, Dashboard, basic navigation
2. **Content Management** - Pages, Blog posts
3. **User Management** - Users, Roles, Permissions
4. **Settings** - System configuration
5. **Advanced** - Widgets, Menus, Finder

## Migration Script

To find elements needing `data-testid`:

```bash
# PHP views with forms but no data-testid
grep -r "<form" app/system/modules/*/views/*.php | \
  xargs -I {} sh -c 'grep -L "data-testid" {}'

# Vue components with inputs but no data-testid  
grep -rl "v-model" --include="*.vue" | \
  xargs -I {} sh -c 'grep -L "data-testid" {}'
```
