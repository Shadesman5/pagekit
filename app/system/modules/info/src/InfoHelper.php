<?php

namespace Pagekit\Info;

use Doctrine\DBAL\Driver\PDOConnection;
use Pagekit\Application as App;
use Symfony\Component\HttpFoundation\ServerBag;

class InfoHelper
{
    /**
     * Method to get the system information
     *
     * @return string[]
     */
    public function get(): array
    {
        $server = new ServerBag($GLOBALS['_SERVER']);

        $info                  = [];
        $info['php']           = php_uname();

        try {
            // DBAL 3.x compatibility: getNativeConnection() replaces getWrappedConnection()
            $connection = App::db()->getNativeConnection();
            if ($connection instanceof \PDO || $connection instanceof PDOConnection) {
                $info['dbdriver']  = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
                $info['dbversion'] = $connection->getAttribute(\PDO::ATTR_SERVER_VERSION);
                $info['dbclient']  = $connection->getAttribute(\PDO::ATTR_CLIENT_VERSION);
            } else {
                // Fallback for non-PDO connections
                $info['dbdriver']  = App::db()->getDriver()->getName();
                $info['dbversion'] = 'Unknown';
                $info['dbclient']  = 'Unknown';
            }
        } catch (\Exception $e) {
            // If database is not connected
            $info['dbdriver']  = 'Not connected';
            $info['dbversion'] = 'N/A';
            $info['dbclient']  = 'N/A';
        }

        $info['phpversion']    = phpversion();
        $info['server']        = $server->get('SERVER_SOFTWARE', getenv('SERVER_SOFTWARE'));
        $info['sapi_name']     = php_sapi_name();
        $info['version']       = App::version();
        $info['useragent']     = $server->get('HTTP_USER_AGENT');
        $info['extensions']    = implode(", ", get_loaded_extensions());
        $info['directories']   = $this->getDirectories();

        return $info;
    }

    /**
     * Gets a list of files and directories and their writable status.
     *
     * @return string[]
     */
    protected function getDirectories(): array
    {
        // -TODO-

        $directories = [
            App::getInstance()->get('path.storage'),
            App::getInstance()->get('path.temp'),
            App::getInstance()->get('path.packages'),
            App::getInstance()->get('config.file')
        ];

        $result = [];

        foreach ($directories as $directory) {

            $result[$this->getRelativePath($directory)] = is_writable($directory);

            if (is_dir($directory)) {
                foreach (App::finder()->depth('< 2')->in($directory)->directories() as $dir) {
                    if (!is_writable($dir->getPathname())) {
                        $result[$this->getRelativePath($dir->getPathname())] = false;
                    }

                }
            }
        }

        return $result;
    }

    /**
     * Returns the path relative to the root.
     *
     * @param  string $path
     */
    protected function getRelativePath($path): string
    {
        if (0 === strpos($path, App::path())) {
            $path = ltrim(str_replace('\\', '/', substr($path, strlen(App::path()))), '/');
        }

        return $path;
    }
}
