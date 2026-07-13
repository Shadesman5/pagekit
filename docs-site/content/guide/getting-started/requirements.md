# Requirements
<p class="uk-article-lead">Pagekit requirements are few, and should be fulfilled by any modern webserver.</p>

## System requirements
Make sure your server meets the following requirements.
- Apache 2.2+ (with mod_rewrite) or nginx
- MySQL Server 8.0+ (recommended) or SQLite 3
- PHP Version 8.2+ (strict mode recommended)

## PHP extensions
Pagekit requires the following PHP extensions to be enabled: [JSON](https://www.php.net/manual/en/book.json.php), [OpenSSL](https://www.php.net/manual/en/book.openssl.php), [Session](https://www.php.net/manual/en/book.session.php), [ctype](https://www.php.net/manual/en/book.ctype.php), [Tokenizer](https://www.php.net/manual/en/book.tokenizer.php), [SimpleXML](https://www.php.net/manual/en/book.simplexml.php), [DOM](https://www.php.net/manual/en/book.dom.php), [mbstring](https://www.php.net/manual/en/book.mbstring.php), [PCRE](https://www.php.net/manual/en/book.pcre.php), [ZIP](https://www.php.net/manual/en/book.zip.php) and [PDO](https://www.php.net/manual/en/book.pdo.php) with [MySQL](https://www.php.net/manual/en/ref.pdo-mysql.php) or [SQLite](https://www.php.net/manual/en/ref.pdo-sqlite.php) drivers.

Although optional, we strongly recommend enabling: [cURL](https://www.php.net/manual/en/book.curl.php), [iconv](https://www.php.net/manual/en/book.iconv.php) and [XML Parser](https://www.php.net/manual/en/book.xml.php). For better performance, [OPcache](https://www.php.net/manual/en/book.opcache.php) (PHP opcode cache) is highly recommended. [APCu](https://www.php.net/manual/en/book.apcu.php) is optional for application-level caching when available.

## PHP configuration
The following php.ini setting is required:
- `allow_url_fopen` must be **on** – required for loading remote resources (e.g. feeds, updates, package downloads)

## Framework & Standards
Pagekit is built on modern, industry-standard frameworks and follows PSR standards:
- Symfony 6.4 LTS
- Doctrine DBAL 3.x
- Doctrine Migrations 3.x
- Symfony Validator 6.4 LTS
- PSR-6 Cache (Symfony Cache)
- PSR-11 Container
- PHP 8 Attributes

## Browser requirements
The admin panel of Pagekit is compatible with modern browsers. Chrome, Firefox, Opera, and Safari have an automatic update system, therefore we only support the latest versions for those browsers.

The browser support for the frontend depends on the theme your site is currently using.
