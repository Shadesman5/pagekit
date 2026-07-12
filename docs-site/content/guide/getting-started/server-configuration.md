# Server Configuration

<p class="uk-article-lead">Pagekit is written in PHP and can run on several web server configurations. Official support exists for Apache 2.2+ and nginx.</p>

**Note** If you use Docker for installation, Apache is pre-configured with mod_rewrite. The following applies to manual installations only.

## Apache 2.2+

Although Pagekit should run fine on Apache 2.2+ without additional configuration, during installation you may receive a warning message. If you do, you should verify that the `.htaccess` file is present in the root of your Pagekit folder.

**Note** The `.htaccess` file is an Apache configuration file and it is hidden on Unix-based systems; as such it is easy to miss when uploading the package initially. If it is not present, copy it from the Pagekit package.

It is possible as well that your webserver does not allow the server's configuration to be overridden through an `.htaccess` file. In that case, contact your hosting provider and ask them to change the AllowOverride directive.

Another common problem is that the `mod_rewrite` module is not enabled on your webserver, in which case you'll also have to turn to your hosting provider to have them enable this Apache module. If the module is not available, Pagekit will still work but fall back to a URL format of the form `http://example.com/index.php/page/welcome`.

## nginx

With Nginx, connect [PHP to Nginx](http://wiki.nginx.org/PHPFcgiExample). Update your nginx config according to the [basic example configuration](https://gist.github.com/DarrylDias/be8955970f4b37fdd682). Please note that out of the box, the Apache solution provides more features from its configuration, such as compression and cache headers for assets. These are currently not included in the nginx configuration.

Ensure your nginx configuration correctly handles pretty URLs (rewrite rules for `index.php`). Without proper configuration, you may need to use URLs in the form `http://example.com/index.php/page/welcome`.
