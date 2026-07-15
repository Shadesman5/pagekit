# Translation

<p class="uk-article-lead">Pagekit ships with a complete translation system based on Symfony Translator. Every string visible to a user can be localized; the active locale is selected during installation and configurable in the admin area.</p>

**Note** Pagekit distinguishes between *languages* and *locales*. A language can have several regional variants (`en_GB` vs. `en_US`), each with its own locale folder.

<ul class="uk-list">
    <li><a href="#language-files">Language files</a></li>
    <li><a href="#usage">Usage</a></li>
    <li><a href="#create-language-files-for-your-extension">Create language files for your extension</a></li>
    <li><a href="#how-a-locale-is-determined">How a locale is determined</a></li>
    <li><a href="#message-domains">Message domains</a></li>
</ul>

## Language files

Pagekit's core ships with language files for several locales:

```
/app/system/languages

  /en_US
    messages.php
    validators.php
    formats.json
    languages.json
    territories.json

  /de_DE
    messages.php
    validators.php
    formats.json
    languages.json
    territories.json

  messages.pot
```

| Path | Description |
|------|-------------|
| `messages.pot` | Master file with all translatable strings, used as a base to create localized versions. |
| `<locale>/messages.php` | Translations for the default `messages` domain. |
| `<locale>/validators.php` | Translations for the `validators` domain — used for Symfony Validator constraint messages. |
| `<locale>/formats.json` | Localized format strings. |
| `<locale>/languages.json` | Localized language names. |
| `<locale>/territories.json` | Localized territory names. |

Formats, languages and territories are sourced from the [Unicode Common Locale Data Repository](http://cldr.unicode.org/).

A translation file is a simple PHP array mapping the source string to its localized version (`de_DE/messages.php`):

```php
return [
    'No database connection.' => 'Keine Datenbankverbindung.',
];
```

`validators.php` follows the same shape and is used by `Pagekit\System\ValidatorServiceProvider` to translate constraint messages. Constraint messages live under the `validators` domain by Symfony convention — see the [Symfony documentation on validation translations](https://symfony.com/doc/6.4/validation/translations.html).

## Usage

In PHP files, call the global `__()` function:

```php
echo __('Save');
```

In Vue templates, use the `trans` filter:

```vue
{{ 'Save' | trans }}
```

Pagekit checks the active locale and returns the localized string when one is available; otherwise it returns the source string unchanged.

### Variables

To interpolate runtime values into a translatable string, pass them as the second argument:

```php
$message = __('Hello %name%!', ['%name%' => $name]);
```

In Vue templates, pass an object to the `trans` filter:

```vue
{{ 'Installing %title%' | trans { title: pkg.title } }}
```

### Pluralization

To choose between several messages depending on a number, use `_c()` and Symfony's pluralization syntax:

```php
$message = _c('{0} No item enabled.|{1} Item enabled.|]1,Inf[ Items enabled.', count($ids));
```

In Vue templates, use the `transChoice` filter:

```vue
{{ '{0} %count% Files|{1} %count% File|]1,Inf[ %count% Files' | transChoice count { count: count } }}
```

The number can be matched by literal value `{0}`, by an interval like `[1, +Inf]` or `]-1,2[`, and you can use `-Inf` and `+Inf` for unbounded ranges. The left delimiter `[` is inclusive and `]` is exclusive; the right delimiter `[` is exclusive and `]` is inclusive.

## Create language files for your extension

Generate the master translation file for a package via the CLI:

```bash
./pagekit extension:translate pagekit/extension-hello
```

This writes `/packages/pagekit/extension-hello/languages/messages.pot` containing every string discovered in calls to `__()`, `_c()`, the `trans` filter and the `transChoice` filter.

Strings that are computed at runtime cannot be discovered automatically:

```php
echo __($message);                 // not extractable: no string literal
echo __('Hello' + $name);          // not extractable: concatenation
```

```vue
UIkit.notify('Item deleted');      // not extractable: no trans filter
```

For unavoidable dynamic cases, place a `languages/messages.php` file inside your extension that lists every string explicitly so the extractor can find them:

```php
<?php

__('Message One');
__('Message Two');
_c('{0} %count% Files|one: File|more %count% File', 0);
```

Once `messages.pot` is generated, create per-locale translation files manually with a tool like [poEdit](http://www.poedit.net/) or via [Transifex](https://www.transifex.com/). Place the finished files under the `languages/` directory of your extension, for example `languages/de_DE/messages.php`.

## How a locale is determined

The locale is selected during installation and can later be changed in the admin area under *System / Localization*. You can configure separate locales for the frontend and the admin panel.

**Note** Only languages that are available for the System extension can be selected.

## Message domains

`__()`, `_c()` and the `trans` / `transChoice` filters all accept a third *domain* argument. The default domain is `messages`. Two domains are used by Pagekit's core:

| Domain | Purpose |
|--------|---------|
| `messages` | All UI strings — buttons, labels, messages. Shared between core and extensions. |
| `validators` | Symfony Validator constraint messages. Sourced from `<locale>/validators.php`. |

Strings translated by the System extension are reused automatically in extensions, which is why running `./pagekit extension:translate hello` produces a `messages.pot` that does **not** contain core-system strings.

To keep your extension's strings separate from the shared `messages` domain — for example to ship a private translation pack — set a custom domain on each call:

```php
$msg = __('Hello Universe', [], 'hello');
```

You can then ship a `languages/<locale>/hello.php` file with translations for that domain.
