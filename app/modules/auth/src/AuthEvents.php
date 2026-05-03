<?php

declare(strict_types=1);

namespace Pagekit\Auth;

final class AuthEvents
{
    /**
     * This event occurs before a user is authenticated.
     *
     * @var string
     */
    public const PRE_AUTHENTICATE = 'auth.pre_authenticate';

    /**
     * This event occurs after a user is authenticated.
     *
     * @var string
     */
    public const SUCCESS = 'auth.success';

    /**
     * This event occurs after a user cannot be authenticated.
     *
     * @var string
     */
    public const FAILURE = 'auth.failure';

    /**
     * This event occurs when a user needs to be authorized.
     *
     * @var string
     */
    public const AUTHORIZE = 'auth.authorize';

    /**
     * This event occurs after a user is logged in interactively for authentication based on http, cookies or X509.
     *
     * @var string
     */
    public const LOGIN = 'auth.login';

    /**
     * This event occurs after a user is logged out.
     *
     * @var string
     */
    public const LOGOUT = 'auth.logout';
}
