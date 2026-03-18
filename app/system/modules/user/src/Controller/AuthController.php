<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use Pagekit\Application as App;
use Pagekit\Auth\Auth;
use Pagekit\Auth\Exception\AuthException;
use Pagekit\Auth\Exception\BadCredentialsException;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;
use Pagekit\Session\Csrf\Exception\CsrfException;
use function Pagekit\__;

class AuthController
{
    public function __construct(
        private readonly mixed $user,
        private readonly mixed $session,
        private readonly mixed $url,
        private readonly mixed $config,
        private readonly mixed $request,
        private readonly mixed $auth,
        private readonly mixed $csrf,
        private readonly mixed $response,
        private readonly mixed $message,
    ) {}

    #[Route(defaults: ['_maintenance' => true])]
    #[Request(['redirect' => 'string'])]
    public function loginAction($redirect = '')
    {
        if (!$redirect) {
            $redirect = ($this->url)(($this->config)('system/user')['login_redirect']);
        }

        if ($this->user->isAuthenticated()) {
            return $this->doRedirect($redirect);
        }

        return [
            '$view' => [
                'title' => __('Login'),
                'name' => 'system/user/login.php'
            ],
            'last_username' => $this->session->get(Auth::LAST_USERNAME),
            'redirect' => $redirect
        ];
    }

    #[Route(defaults: ['_maintenance' => true])]
    public function logoutAction($redirect = null)
    {
        if ($redirect === null) {
            $redirect = $this->request->get('redirect', '');
        }
        
        if (($event = $this->auth->logout()) && $event->hasResponse()) {
            return $event->getResponse();
        }

        return $this->doRedirect($redirect);
    }

    #[Route(methods: ['POST'], defaults: ['_maintenance' => true])]
    public function authenticateAction()
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

            $this->auth->authorize($user = $this->auth->authenticate($credentials, false));

            if (($event = $this->auth->login($user, $remember)) && $event->hasResponse()) {
                return $event->getResponse();
            }

            if ($this->request->isXmlHttpRequest()) {
                return $this->response->json(['csrf' => $this->csrf->generate()]);
            } else {
                return $this->doRedirect($redirect);
            }

        } catch (CsrfException $e) {
            if ($this->request->isXmlHttpRequest()) {
                return $this->response->json(['csrf' => $this->csrf->generate()], 401);
            }
            $error = $e->getMessage();
        } catch (BadCredentialsException $e) {
            $error = __('Invalid username or password.');
        } catch (AuthException $e) {
            $error = $e->getMessage();
        }

        if ($this->request->isXmlHttpRequest()) {
            return $this->response->json($error, 401);
        } else {
            $this->message->error($error);
            return $this->doRedirect($this->url->previous());
        }
    }

    protected function doRedirect($url)
    {
        do {
            $url = preg_replace('#^(https?:)?//[^/]+#', '', $url, 1, $count);
        } while ($count);
        return App::redirect($url); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
    }
}
