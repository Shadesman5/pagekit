<?php

declare(strict_types=1);

namespace Pagekit\Tests;

use PHPUnit\Framework\TestCase;

abstract class FtpTestCase extends TestCase
{
    use FtpUtil;

    protected string|false|null $workspace = null;
    private \FTP\Connection $connection;

    public function setUp(): void
    {
        try {

            $this->connection = $this->getSharedFtpConnection();

        } catch (\Exception $e) {
            $this->markTestSkipped(sprintf('Unable to establish connection. (%s)', $e->getMessage()));

            return;
        }

        $this->workspace = DIRECTORY_SEPARATOR.time().rand(0, 1000);

        if (false === @ftp_mkdir($this->connection, $this->workspace)) {
            $this->markTestSkipped('Unable to create workspace folder');
            $this->workspace = false;

            return;
        }
    }

    public function tearDown(): void
    {
        $workspace = $this->workspace;
        if (isset($this->connection) && $workspace) {
            $this->clean($workspace);
        }
    }

    private function clean(string $file): void
    {
        if (ftp_size($this->connection, $file) == -1) {
            $result = ftp_nlist($this->connection, $file);
            if ($result === false) {
                return;
            }
            foreach ($result as $childFile) {
                $this->clean($childFile);
            }
            ftp_rmdir($this->connection, $file);
        } else {
            ftp_delete($this->connection, $file);
        }
    }
}
