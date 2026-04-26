<?php

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

    protected ?array $config = null;

    protected \Pagekit\Cookie\CookieJar $cookie;

    protected \Symfony\Component\HttpFoundation\RequestStack $requests;

    protected \Pagekit\Database\Connection $connection;

    /**
     * Constructor.
     *
     * @param Connection   $connection
     * @param RequestStack $requests
     * @param CookieJar    $cookie
     * @param array        $config
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
        if ($token = $this->getToken() and $data = $this->connection->executeQuery("SELECT user_id, status, access FROM {$this->config['table']} WHERE id = :id AND status > :status", [
                'id' => sha1($token),
                'status' => self::STATUS_INACTIVE,
            ])->fetchAssociative()) {

            if (strtotime($data['access']) + $this->config['timeout'] < time()) {

                if ($data['status'] == self::STATUS_REMEMBERED) {
                    $this->write($data['user_id'], self::STATUS_REMEMBERED);
                } else {
                    return null;
                }

            }

            $this->connection->update($this->config['table'], ['access' => date('Y-m-d H:i:s')], ['id' => sha1($token)]);

            return $data['user_id'];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function write($user, $remember = false): void
    {
        if ($token = $this->getToken()) {
            $this->connection->delete($this->config['table'], ['id' => sha1($token)]);
        }

        $id = bin2hex(random_bytes(32));

        $this->cookie->set($this->config['cookie']['name'], $id, $this->config['cookie']['lifetime'] + time());

        $this->connection->insert($this->config['table'], [
            'id' => sha1($id),
            'user_id' => $user,
            'access' => date('Y-m-d H:i:s'),
            'status' => $remember ? self::STATUS_REMEMBERED : self::STATUS_ACTIVE,
            'data' => json_encode([
                'ip' => $this->getRequest()->getClientIp(),
                'user-agent' => $this->getRequest()->headers->get('User-Agent'),
            ]),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(): void
    {
        if ($token = $this->getToken()) {
            $this->connection->update($this->config['table'], ['status' => self::STATUS_INACTIVE], ['id' => sha1($token)]);
        }
    }

    /**
     * Gets the token from the request.
     *
     * @return mixed
     */
    protected function getToken()
    {
        if ($request = $this->getRequest()) {
            return $request->cookies->get($this->config['cookie']['name']);
        }
    }

    /**
     * @return null|Request
     */
    protected function getRequest(): ?Request
    {
        return $this->requests->getCurrentRequest();
    }

}
