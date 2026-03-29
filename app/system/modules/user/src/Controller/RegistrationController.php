<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use function Pagekit\__;

use Pagekit\Application\Exception;
use Pagekit\Captcha\Attribute\Captcha;
use Pagekit\Routing\Attribute\Request;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Model\User;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Controller for user registration.
 */
class RegistrationController
{
    use ValidatesRequestTrait;

    protected mixed $userModule;

    public function __construct(
        private readonly mixed $module,
        private readonly mixed $user,
        private readonly mixed $csrf,
        private readonly mixed $message,
        private readonly mixed $url,
        private readonly mixed $mailer,
        private readonly mixed $view,
        private readonly mixed $router,
        private readonly mixed $authPassword,
        private readonly mixed $validator,
    ) {
        $this->userModule = $this->module->get('system/user');
    }

    #[Captcha(route: '@user/registration/register')]
    public function indexAction()
    {
        if ($this->user->isAuthenticated()) {
            return $this->router->redirect();
        }

        if ($this->userModule->config('registration') == 'admin') {
            return $this->router->redirect();
        }

        return [
            '$view' => [
                'title' => __('User Registration'),
                'name' => 'system/user/registration.php',
            ],
        ];
    }

    #[Request(['user' => 'array'])]
    #[Captcha(verify: true)]
    public function registerAction(array $data)
    {
        try {

            if ($this->user->isAuthenticated() || $this->userModule->config('registration') == 'admin') {
                return $this->router->redirect();
            }

            if (!$this->csrf->validate()) {
                throw new Exception(__('Invalid token. Please try again.'));
            }

            $password = @$data['password'];
            if (trim($password) != $password || strlen($password) < 6) {
                throw new Exception(__('Password must be 6 characters or longer.'));
            }

            $user = User::create([
                'registered' => new \DateTime(),
                'name' => @$data['name'],
                'username' => @$data['username'],
                'email' => @$data['email'],
                'password' => $this->authPassword->hash($password),
                'status' => User::STATUS_BLOCKED,
            ]);

            $token = bin2hex(random_bytes(16));
            $admin = $this->userModule->config('registration') == 'approval';

            if ($verify = $this->userModule->config('require_verification') or $admin) {
                $user->activation = $token;
            } else {
                $user->status = User::STATUS_ACTIVE;
            }

            $this->validateOrFail($user, null, ['Default', 'registration']);

            $user->save();

            if ($verify) {
                $this->sendVerificationMail($user);
                $message = __('Complete your registration by clicking the link provided in the mail that has been sent to you.');
            } elseif ($admin) {
                $this->sendApproveMail($user);
                $message = __('Your user account has been created and is pending approval by the site administrator.');
            } else {
                $this->sendWelcomeEmail($user);
                $message = __('Your user account has been created.');
            }

        } catch (Exception $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $this->message->success($message);

        return [
            'redirect' => ($this->url)('@user/login'),
        ];
    }

    #[Request(['user' => 'string', 'key' => 'string'])]
    public function activateAction(string $username, string $activation)
    {
        if (empty($username) || empty($activation) || !$user = User::where(['username' => $username, 'activation' => $activation, 'login IS NULL'])->first()) {
            throw new BadRequestHttpException(__('Invalid key.'));
        }

        $verifying = false;
        if ($this->userModule->config('require_verification') && !$user->get('verified')) {
            $user->set('verified', true);
            $verifying = true;
        }

        if ($this->userModule->config('registration') === 'approval' && $user->status === User::STATUS_BLOCKED && $verifying) {
            $user->activation = bin2hex(random_bytes(16));
            $this->sendApproveMail($user);
            $message = __('Your email has been verified. Once an administrator approves your account, you will be notified by email.');
        } else {
            $user->status = User::STATUS_ACTIVE;
            $user->activation = '';
            $this->sendWelcomeEmail($user);
            $message = $verifying ? __('Your account has been activated.') : __('The user\'s account has been activated and the user has been notified about it.');
        }

        $user->save();

        $this->message->success($message);

        return $this->router->redirect('@user/login');
    }

    protected function sendWelcomeEmail(User $user): void
    {
        try {

            $mail = $this->mailer->create();
            $mail->to($user->email)
                ->subject(__('Welcome to %site%!', ['%site%' => $this->module->get('system/site')->config('title')]))
                ->html(($this->view)('system/user:mails/welcome.php', compact('user', 'mail')));

            $this->mailer->send($mail);

        } catch (\Exception $e) {
        }
    }

    protected function sendVerificationMail(User $user): void
    {
        try {

            $mail = $this->mailer->create();
            $mail->to($user->email)
                ->subject(__('Activate your %site% account.', ['%site%' => $this->module->get('system/site')->config('title')]))
                ->html(($this->view)('system/user:mails/verification.php', compact('user', 'mail')));

            $this->mailer->send($mail);

        } catch (\Exception $e) {
            throw new Exception(__('Unable to send verification link.'));
        }
    }

    protected function sendApproveMail(User $user): void
    {
        try {

            $mail = $this->mailer->create();
            $mail->to($this->module->get('system/mail')->config('from_address'))
                ->subject(__('Approve an account at %site%.', ['%site%' => $this->module->get('system/site')->config('title')]))
                ->html(($this->view)('system/user:mails/approve.php', compact('user', 'mail')));

            $this->mailer->send($mail);

        } catch (\Exception $e) {
        }
    }
}
