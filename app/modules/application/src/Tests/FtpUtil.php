<?php

declare(strict_types=1);

namespace Pagekit\Tests;

trait FtpUtil
{
    public function getFtpConnection(): \FTP\Connection
    {
        if (!extension_loaded('ftp')) {
            throw new \Exception('FTP extension needed');
        }

        if (!function_exists('ftp_connect')) {
            throw new \Exception('Function "ftp_connect" does not exist on this server.');
        }

        if (!isset($GLOBALS['ftp_host'], $GLOBALS['ftp_port'], $GLOBALS['ftp_user'], $GLOBALS['ftp_pass'], $GLOBALS['ftp_passive'], $GLOBALS['ftp_mode'])) {
            throw new \Exception('FTP credentials not set.');
        }

        $connection = ftp_connect($GLOBALS['ftp_host']);
        if (!$connection instanceof \FTP\Connection) {
            throw new \Exception('Unable to connect to ftp server.');
        }

        if (false === ftp_login($connection, $GLOBALS['ftp_user'], $GLOBALS['ftp_pass'])) {
            ftp_close($connection);

            throw new \Exception('Unable to login to ftp server.');
        }

        if ($GLOBALS['ftp_passive'] && !ftp_pasv($connection, true)) {
            ftp_close($connection);

            throw new \Exception('Unable to switch on FTP passive mode.');
        }

        return $connection;
    }

    public function getSharedFtpConnection(): \FTP\Connection
    {
        static $connection = null;
        static $error = null;

        if ($connection === null && $error === null) {

            try {
                $connection = $this->getFtpConnection();
            } catch (\Exception $e) {
                $error = $e;
            }
        }

        if ($error !== null) {
            throw $error;
        }

        if (!$connection instanceof \FTP\Connection) {
            throw new \Exception('Unable to establish connection.');
        }

        return $connection;
    }
}
