<?php

namespace Pagekit\User\Controller;

use Pagekit\Application as App;
use Pagekit\Auth\Auth;
use Pagekit\Auth\Exception\AuthException;
use Pagekit\Auth\Exception\BadCredentialsException;
use Pagekit\Session\Csrf\Exception\CsrfException;

class AuthController
{
    /**
     * @Route(defaults={"_maintenance"=true})
     * @Request({"redirect"})
     */
    public function loginAction($redirect = '')
    {
        if (!$redirect) {
            $redirect = App::url(App::config('system/user')['login_redirect']);
        }

        if (App::user()->isAuthenticated()) {
            return $this->redirect($redirect);
        }

        return [
            '$view' => [
                'title' => __('Login'),
                'name' => 'system/user/login.php'
            ],
            'last_username' => App::session()->get(Auth::LAST_USERNAME),
            'redirect' => $redirect
        ];
    }

    /**
     * @Route(defaults={"_maintenance" = true})
     * @Request({"redirect": "string"})
     */
    public function logoutAction($redirect = '')
    {
        if (($event = App::auth()->logout()) && $event->hasResponse()) {
            return $event->getResponse();
        }

        return $this->redirect($redirect);
    }

    /**
     * @Route(methods="POST", defaults={"_maintenance" = true})
     * @Request({"credentials": "array", "remember_me": "boolean", "redirect": "string"})
     */
    public function authenticateAction($credentials = null, $remember = null, $redirect = null)
    {
        // Debug logging
        $logFile = dirname(__DIR__, 5) . '/auth_debug.log';
        file_put_contents($logFile, "\n=== authenticateAction called ===\n", FILE_APPEND);
        file_put_contents($logFile, "Initial params: " . json_encode([
            'credentials' => $credentials,
            'remember' => $remember,
            'redirect' => $redirect
        ]) . "\n", FILE_APPEND);
        
        try {
            // Symfony 6.4 compatibility: Always get parameters from request
            $request = App::request();
            file_put_contents($logFile, "Request content: " . $request->getContent() . "\n", FILE_APPEND);
            file_put_contents($logFile, "POST data: " . json_encode($request->request->all()) . "\n", FILE_APPEND);
            
            // Try to get from POST data first
            if ($credentials === null) {
                $credentials = $request->request->get('credentials', []);
            }
            if ($remember === null) {
                $remember = (bool) $request->request->get('remember_me', false);
            }
            if ($redirect === null) {
                $redirect = $request->request->get('redirect', '');
            }
            
            // If still empty, try JSON body
            if (empty($credentials) && $request->getContent()) {
                $data = json_decode($request->getContent(), true);
                if ($data) {
                    $credentials = $data['credentials'] ?? [];
                    $remember = $data['remember_me'] ?? false;
                    $redirect = $data['redirect'] ?? '';
                }
            }
            
            file_put_contents($logFile, "Final params: " . json_encode([
                'credentials' => $credentials,
                'remember' => $remember,
                'redirect' => $redirect
            ]) . "\n", FILE_APPEND);
            
            if (!App::csrf()->validate()) {
                throw new CsrfException(__('Invalid token. Please try again.'));
            }

            App::auth()->authorize($user = App::auth()->authenticate($credentials, false));

            if (($event = App::auth()->login($user, $remember)) && $event->hasResponse()) {
                return $event->getResponse();
            }

            if (App::request()->isXmlHttpRequest()) {
                return App::response()->json(['csrf' => App::csrf()->generate()]);
            } else {
                return $this->redirect($redirect);
            }

        } catch (CsrfException $e) {
            if (App::request()->isXmlHttpRequest()) {
                return App::response()->json(['csrf' => App::csrf()->generate()], 401);
            }
            $error = $e->getMessage();
        } catch (BadCredentialsException $e) {
            $error = __('Invalid username or password.');
        } catch (AuthException $e) {
            $error = $e->getMessage();
        }

        if (App::request()->isXmlHttpRequest()) {
            return App::response()->json($error, 401);
        } else {
            App::message()->error($error);
            return $this->redirect(App::url()->previous());
        }
    }

    protected function redirect($url)
    {
        do {
            $url = preg_replace('#^(https?:)?//[^/]+#', '', $url, 1, $count);
        } while ($count);
        return App::redirect($url);
    }
}
