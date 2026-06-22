<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use function Pagekit\__;

use Pagekit\Application\Exception;
use Pagekit\Application\UrlProvider;
use Pagekit\Auth\Encoder\PasswordEncoderInterface;
use Pagekit\Mail\Mailer;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Route;
use Pagekit\Routing\Router;
use Pagekit\Session\Csrf\Provider\CsrfProviderInterface;
use Pagekit\Session\MessageBag;
use Pagekit\User\Model\User;
use Pagekit\View\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ResetPasswordController
{
    public function __construct(
        private readonly User $user,
        private readonly Request $request,
        private readonly SessionInterface $session,
        private readonly CsrfProviderInterface $csrf,
        private readonly UrlProvider $url,
        private readonly Mailer $mailer,
        private readonly ModuleManager $module,
        private readonly View $view,
        private readonly MessageBag $message,
        private readonly Router $router,
        private readonly PasswordEncoderInterface $authPassword,
    ) {
    }

    /**
     * @return array<string, mixed>|HttpResponse
     */
    public function indexAction(): array|HttpResponse
    {
        if ($this->user->isAuthenticated()) {
            return $this->router->redirect();
        }

        return [
            '$view' => [
                'title' => __('Reset'),
                'name' => 'system/user/reset-request.php',
            ],
            'error' => '',
        ];
    }

    /**
     * @return array<string, mixed>|HttpResponse
     */
    #[Route('/request', methods: ['POST'])]
    public function requestAction(): array|HttpResponse
    {
        $email = $this->request->request->get('email', '');

        if (empty($email) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $email = $json['email'] ?? '';
        }

        try {

            if ($this->user->isAuthenticated()) {
                return $this->router->redirect();
            }

            if (!$this->csrf->validate()) {
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

            $key = bin2hex(random_bytes(16));
            $url = ($this->url)('@user/resetpassword/confirm', compact('key'), 0);

            try {

                $mail = $this->mailer->create();
                $mail->to($user->email)
                    ->subject(__('Reset password for %site%.', ['%site%' => $this->module->get('system/site')->config('title')]))
                    ->html(($this->view)('system/user:mails/reset.php', compact('user', 'url', 'mail')));

                $this->mailer->send($mail);

            } catch (\Exception $e) {
                throw new Exception(__('Unable to send confirmation link.'));
            }

            $user->activation = $key;
            $user->save();

            $this->message->success((string) __('Check your email for the confirmation link.'));

            return $this->router->redirect('@user/login');

        } catch (Exception $e) {
            return [
                '$view' => [
                    'title' => __('Reset'),
                    'name' => 'system/user/reset-request.php',
                ],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>|HttpResponse
     */
    #[Route('/confirm', methods: ['GET'])]
    #[Route('/confirm', methods: ['POST'])]
    public function confirmAction(): array|HttpResponse
    {
        if ($this->request->isMethod('GET')) {
            $activation = $this->request->query->get('key', '');
            $password = '';
        } else {
            $activation = $this->request->request->get('key', '');
            $password = $this->request->request->get('password', '');

            if ($this->request->getContent()) {
                $json = json_decode($this->request->getContent(), true);
                if ($json) {
                    $activation = $json['key'] ?? $activation;
                    $password = $json['password'] ?? $password;
                }
            }
        }

        if ($activation && ($user = User::where(compact('activation'))->first()) !== null) {
            if (!$user instanceof User) {
                throw new \LogicException(sprintf(
                    'QueryBuilder::first() returned %s, expected %s',
                    get_class($user),
                    User::class
                ));
            }

            $this->session->set('activation', [
                'key' => $activation,
                'user' => $user->id,
            ]);

            $user->activation = null;
            $user->save();
        }

        if (!$this->session->isStarted()) {
            $this->session->start();
        }

        $data = $this->session->get('activation');

        if ($this->request->isMethod('POST') && !$data && $activation) {
            if (($user = User::where(compact('activation'))->first()) !== null) {
                if (!$user instanceof User) {
                    throw new \LogicException(sprintf(
                        'QueryBuilder::first() returned %s, expected %s',
                        get_class($user),
                        User::class
                    ));
                }
                $data = [
                    'key' => $activation,
                    'user' => $user->id,
                ];
                $this->session->set('activation', $data);
            }
        }

        if (!$data || $data['key'] != $activation) {
            throw new BadRequestHttpException(__('Invalid key.'));
        }

        if (!$user = User::find($data['user']) or $user->isBlocked()) {
            throw new BadRequestHttpException(__('Your account has not been activated or is blocked.'));
        }

        if ('POST' === $this->request->getMethod()) {

            try {

                if (!$this->csrf->validate()) {
                    throw new Exception(__('Invalid token. Please try again.'));
                }

                if (empty($password)) {
                    throw new Exception(__('Enter password.'));
                }

                if ($password != trim($password)) {
                    throw new Exception(__('Invalid password.'));
                }

                $user->activation = null;
                $user->password = $this->authPassword->hash($password);
                $user->save();

                $this->session->remove('activation');

                $this->message->success((string) __('Your password has been reset.'));

                return $this->router->redirect('@user/login');

            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }

        return [
            '$view' => [
                'title' => __('Reset Confirm'),
                'name' => 'system/user/reset-confirm.php',
            ],
            'activation' => $activation,
            'error' => isset($error) ? $error : '',
        ];
    }

}
