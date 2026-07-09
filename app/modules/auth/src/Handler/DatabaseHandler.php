<?php

declare(strict_types=1);

namespace Pagekit\Auth\Handler;

use Pagekit\Cookie\CookieJar;
use Pagekit\Database\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class DatabaseHandler implements HandlerInterface
{
    public const STATUS_INACTIVE = 0;
    public const STATUS_ACTIVE = 1;
    public const STATUS_REMEMBERED = 2;

    /** @var array<string, mixed>|null */
    protected ?array $config = null;

    protected CookieJar $cookie;

    protected RequestStack $requests;

    protected Connection $connection;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(Connection $connection, RequestStack $requests, CookieJar $cookie, ?array $config = null)
    {
        $this->connection = $connection;
        $this->requests = $requests;
        $this->cookie = $cookie;
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function read(): ?int
    {
        $config = $this->config;
        if ($config === null) {
            return null;
        }

        if ($token = $this->getToken() and $data = $this->connection->executeQuery("SELECT user_id, status, access FROM {$config['table']} WHERE id = :id AND status > :status", [
                'id' => sha1($token),
                'status' => self::STATUS_INACTIVE,
            ])->fetchAssociative()) {

            if (strtotime($data['access']) + $config['timeout'] < time()) {

                if ($data['status'] == self::STATUS_REMEMBERED) {
                    // AUDIT FIX Step 2.1.8: write() declares `bool $remember`; under strict_types the
                    // int STATUS_REMEMBERED (2) raised a TypeError, breaking remembered-session renewal.
                    // Re-issue the timed-out session while keeping it remembered.
                    $this->write($data['user_id'], true);
                } else {
                    return null;
                }

            }

            $this->connection->update($config['table'], ['access' => date('Y-m-d H:i:s')], ['id' => sha1($token)]);

            return $data['user_id'];
        }

        return null;
    }

    public function write(int|string $user, bool $remember = false): void
    {
        if ($this->config === null) {
            return;
        }

        if ($token = $this->getToken()) {
            $this->connection->delete($this->config['table'], ['id' => sha1($token)]);
        }

        $id = bin2hex(random_bytes(32));

        $this->cookie->set($this->config['cookie']['name'], $id, $this->config['cookie']['lifetime'] + time());

        $request = $this->getRequest();
        $this->connection->insert($this->config['table'], [
            'id' => sha1($id),
            'user_id' => $user,
            'access' => date('Y-m-d H:i:s'),
            'status' => $remember ? self::STATUS_REMEMBERED : self::STATUS_ACTIVE,
            'data' => json_encode([
                'ip' => $request?->getClientIp(),
                'user-agent' => $request?->headers->get('User-Agent'),
            ]),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(): void
    {
        if ($this->config === null) {
            return;
        }

        if ($token = $this->getToken()) {
            $this->connection->update($this->config['table'], ['status' => self::STATUS_INACTIVE], ['id' => sha1($token)]);
        }
    }

    /**
     * Gets the token from the request.
     */
    protected function getToken(): ?string
    {
        if ($this->config === null) {
            return null;
        }

        if ($request = $this->getRequest()) {
            $value = $request->cookies->get($this->config['cookie']['name']);

            return is_string($value) ? $value : null;
        }

        return null;
    }

    protected function getRequest(): ?Request
    {
        return $this->requests->getCurrentRequest();
    }

}
