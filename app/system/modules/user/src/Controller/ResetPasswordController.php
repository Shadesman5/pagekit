<?php

namespace Pagekit\User\Controller;

use Pagekit\Application as App;
use Pagekit\Application\Exception;
use Pagekit\User\Model\User;
use function Pagekit\__;

class ResetPasswordController
{

    public function indexAction()
    {
        if (App::user()->isAuthenticated()) {
            return App::redirect();
        }

        return [
            '$view' => [
                'title' => __('Reset'),
                'name' => 'system/user/reset-request.php',
            ],
            'error' => ''
        ];
    }

    /**
     * @Route("/request", methods="POST")
     */
    public function requestAction()
    {
        // Get parameters from request (Symfony 6.4 compatibility)
        $app = App::getInstance();
        $request = isset($app['request']) ? $app['request'] : \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        $email = $request->request->get('email', '');
        
        if (empty($email) && $request->getContent()) {
            $json = json_decode($request->getContent(), true);
            $email = $json['email'] ?? '';
        }
        
        try {

            if (App::user()->isAuthenticated()) {
                return App::redirect();
            }

            if (!App::csrf()->validate()) {
                throw new Exception(__('Invalid token. Please try again.'));
            }

            if (empty($email)) {
                throw new Exception(__('Enter a valid email address.'));
            }

            if (!$user = User::findByEmail($email)) {
                throw new Exception(__('Unknown email address.'));
            }

            if ($user->isBlocked()) {
                throw new Exception(__('Your account has not been activated or is blocked.'));
            }

            // Generate URL-safe key (no special chars like /)
            $key = bin2hex(random_bytes(16)); // 32 chars, URL-safe
            $url = App::url('@user/resetpassword/confirm', compact('key'), 0);

            try {

                $mail = App::mailer()->create();
                $mail->to($user->email)
                    ->subject(__('Reset password for %site%.', ['%site%' => App::module('system/site')->config('title')]))
                    ->html(App::view('system/user:mails/reset.php', compact('user', 'url', 'mail')));
                
                App::mailer()->send($mail);

            } catch (\Exception $e) {
                throw new Exception(__('Unable to send confirmation link.'));
            }

            $user->activation = $key;
            $user->save();

            App::message()->success(__('Check your email for the confirmation link.'));

            return App::redirect('@user/login');

        } catch (Exception $e) {
            return [
                '$view' => [
                    'title' => __('Reset'),
                    'name' => 'system/user/reset-request.php',
                ],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * @Route("/confirm", methods="GET")
     * @Route("/confirm", methods="POST")
     */
    public function confirmAction()
    {
        $logFile = __DIR__ . '/../../../../../../confirm_debug.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - confirmAction started\n", FILE_APPEND);
        
        try {
            // Get parameters from request (Symfony 6.4 compatibility)
            $app = App::getInstance();
            $request = isset($app['request']) ? $app['request'] : \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            
            file_put_contents($logFile, "Request method: " . $request->getMethod() . "\n", FILE_APPEND);
        
        // For GET requests (clicking the link), get key from query string
        // For POST requests (submitting new password), get from POST data
        if ($request->isMethod('GET')) {
            $activation = $request->query->get('key', '');
            $password = '';
        } else {
            $activation = $request->request->get('key', '');
            $password = $request->request->get('password', '');
            
            if ($request->getContent()) {
                $json = json_decode($request->getContent(), true);
                if ($json) {
                    $activation = $json['key'] ?? $activation;
                    $password = $json['password'] ?? $password;
                }
            }
        }
        
        if ($activation and $user = User::where(compact('activation'))->first()) {

            $app = App::getInstance();
            $session = $app['session'];
            $session->set('activation', [
                'key' => $activation,
                'user' => $user->id,
            ]);

            $user->activation = null;
            $user->save();
        }

        $app = App::getInstance();
        $session = $app['session'];
        if (!$data = $session->get('activation') or $data['key'] != $activation) {
            App::abort(400, __('Invalid key.'));
        }

        if (!$user = User::find($data['user']) or $user->isBlocked()) {
            App::abort(400, __('Your account has not been activated or is blocked.'));
        }

        if ('POST' === $request->getMethod()) {
            file_put_contents($logFile, "POST request detected\n", FILE_APPEND);

            try {

                if (!App::csrf()->validate()) {
                    file_put_contents($logFile, "CSRF validation failed\n", FILE_APPEND);
                    throw new Exception(__('Invalid token. Please try again.'));
                }
                file_put_contents($logFile, "CSRF validation passed\n", FILE_APPEND);

                if (empty($password)) {
                    throw new Exception(__('Enter password.'));
                }

                if ($password != trim($password)) {
                    throw new Exception(__('Invalid password.'));
                }

                $user->activation = null;
                $user->password = App::getInstance()['auth.password']->hash($password);
                $user->save();

                $session->remove('activation');
                
                // Login the user (optional - can be removed if not needed)
                // App::auth()->login($user);
                
                App::message()->success(__('Your password has been reset.'));

                return App::redirect('@user/login');

            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }

        return [
            '$view' => [
                'title' => __('Reset Confirm'),
                'name' => 'system/user/reset-confirm.php'
            ],
            'activation' => $activation,
            'error' => isset($error) ? $error : ''
        ];
        
        } catch (\Throwable $e) {
            file_put_contents($logFile, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            file_put_contents($logFile, "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n", FILE_APPEND);
            file_put_contents($logFile, "Trace:\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
            throw $e;
        }
    }

}
