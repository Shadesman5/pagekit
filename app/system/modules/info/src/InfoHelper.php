<?php

declare(strict_types=1);

namespace Pagekit\Info;

use Doctrine\DBAL\Connection;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\ServerBag;

class InfoHelper
{
    public function __construct(
        private readonly Connection $db,
        private readonly string $version,
        private readonly string $pathStorage,
        private readonly string $pathTemp,
        private readonly string $pathPackages,
        private readonly string $configFile,
        private readonly string $basePath,
    ) {
    }

    /**
     * Method to get the system information
     *
     * @return string[]
     */
    public function get(): array
    {
        $server = new ServerBag($GLOBALS['_SERVER']);

        $info = [];
        $info['php'] = php_uname();

        try {
            $native = $this->db->getNativeConnection();

            if ($native instanceof \PDO) {
                $info['dbdriver'] = $native->getAttribute(\PDO::ATTR_DRIVER_NAME);
                $info['dbversion'] = $native->getAttribute(\PDO::ATTR_SERVER_VERSION);
                $info['dbclient'] = $native->getAttribute(\PDO::ATTR_CLIENT_VERSION);
            } else {
                $info['dbdriver'] = 'Non-PDO driver';
                $info['dbversion'] = 'N/A';
                $info['dbclient'] = 'N/A';
            }
        } catch (\Exception $e) {
            $info['dbdriver'] = 'Not connected';
            $info['dbversion'] = 'N/A';
            $info['dbclient'] = 'N/A';
        }

        $info['phpversion'] = phpversion();
        $info['server'] = $server->get('SERVER_SOFTWARE', getenv('SERVER_SOFTWARE'));
        $info['sapi_name'] = php_sapi_name();
        $info['version'] = $this->version;
        $info['useragent'] = $server->get('HTTP_USER_AGENT');
        $info['extensions'] = implode(", ", get_loaded_extensions());
        $info['directories'] = $this->getDirectories();

        return $info;
    }

    /**
     * Gets a list of files and directories and their writable status.
     *
     * @return string[]
     */
    protected function getDirectories(): array
    {
        $directories = [
            $this->pathStorage,
            $this->pathTemp,
            $this->pathPackages,
            $this->configFile,
        ];

        $result = [];

        foreach ($directories as $directory) {

            $result[$this->getRelativePath($directory)] = is_writable($directory);

            if (is_dir($directory)) {
                foreach (Finder::create()->depth('< 2')->in($directory)->directories() as $dir) {
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
        if (0 === strpos($path, $this->basePath)) {
            $path = ltrim(str_replace('\\', '/', substr($path, strlen($this->basePath))), '/');
        }

        return $path;
    }
}
