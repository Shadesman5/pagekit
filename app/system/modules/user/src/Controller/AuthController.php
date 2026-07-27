<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use function Pagekit\__;

use Pagekit\Application\Response as PagekitResponse;
use Pagekit\Application\UrlProvider;
use Pagekit\Auth\Auth;
use Pagekit\Auth\Exception\AuthException;
use Pagekit\Auth\Exception\BadCredentialsException;
use Pagekit\Config\ConfigManager;
use Pagekit\Routing\Attribute\Request as RequestAttr;
use Pagekit\Routing\Attribute\Route;
use Pagekit\Routing\Router;
use Pagekit\Session\Csrf\Exception\CsrfException;
use Pagekit\Session\Csrf\Provider\CsrfProviderInterface;
use Pagekit\Session\MessageBag;
use Pagekit\User\Model\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class AuthController
{
    public function __construct(
        private readonly User $user,
        private readonly SessionInterface $session,
        private readonly UrlProvider $url,
        private readonly ConfigManager $config,
        private readonly Request $request,
        private readonly Auth $auth,
        private readonly CsrfProviderInterface $csrf,
        private readonly PagekitResponse $response,
        private readonly MessageBag $message,
        private readonly Router $router,
    ) {
    }

    /**
     * @return array<string, mixed>|HttpResponse
     */
    #[Route(defaults: ['_maintenance' => true])]
    #[RequestAttr(['redirect' => 'string'])]
    public function loginAction(string $redirect = ''): array|HttpResponse
    {
        if (!$redirect) {
            $loginRedirect = ($this->config)('system/user')['login_redirect'] ?? '';
            $redirect = (string) ($this->url)((string) $loginRedirect);
        }

        if ($this->user->isAuthenticated()) {
            return $this->doRedirect($redirect);
        }

        return [
            '$view' => [
                'title' => __('Login'),
                'name' => 'system/user/login.php',
            ],
            'last_username' => $this->session->get(Auth::LAST_USERNAME),
            'redirect' => $redirect,
        ];
    }

    #[Route(defaults: ['_maintenance' => true])]
    public function logoutAction(?string $redirect = null): HttpResponse
    {
        if ($redirect === null) {
            $redirect = (string) $this->request->get('redirect', '');
        }

        $event = $this->auth->logout();
        if ($event->hasResponse() && ($response = $event->getResponse()) !== null) {
            return $response;
        }

        return $this->doRedirect($redirect);
    }

    #[Route(methods: ['POST'], defaults: ['_maintenance' => true])]
    public function authenticateAction(): HttpResponse
    {
        try {
            $credentials = $this->request->request->all()['credentials'] ?? [];
            $remember = (bool) ($this->request->request->get('remember_me') ?? false);
            $redirect = $this->request->request->get('redirect') ?? '';

            if (empty($credentials) && $this->request->getContent()) {
                $data = json_decode($this->request->getContent(), true);
                if ($data) {
                    $credentials = $data['credentials'] ?? [];
                    $remember = $data['remember_me'] ?? false;
                    $redirect = $data['redirect'] ?? '';
                }
            }

            if (!$this->csrf->validate()) {
                throw new CsrfException(__('Invalid token. Please try again.'));
            }

            $this->auth->authorize($user = $this->auth->authenticate($credentials));

            $event = $this->auth->login($user, $remember);
            if ($event->hasResponse() && ($response = $event->getResponse()) !== null) {
                return $response;
            }

            if ($this->request->isXmlHttpRequest()) {
                return $this->response->json(['csrf' => $this->csrf->generate()]);
            } else {
                return $this->doRedirect((string) $redirect);
            }

        } catch (CsrfException $e) {
            if ($this->request->isXmlHttpRequest()) {
                return $this->response->json(['csrf' => $this->csrf->generate()], 401);
            }
            $error = $e->getMessage();
        } catch (BadCredentialsException $e) {
            $error = (string) __('Invalid username or password.');
        } catch (AuthException $e) {
            $error = $e->getMessage();
        }

        if ($this->request->isXmlHttpRequest()) {
            return $this->response->json($error, 401);
        }

        $this->message->error($error);

        return $this->doRedirect((string) $this->url->previous());
    }

    protected function doRedirect(string $url): RedirectResponse
    {
        do {
            $url = preg_replace('#^(https?:)?//[^/]+#', '', $url, 1, $count) ?? $url;
        } while ($count);

        return $this->router->redirect($url);
    }
}
