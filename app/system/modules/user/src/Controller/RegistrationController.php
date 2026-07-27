<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use function Pagekit\__;

use Pagekit\Application\Exception;
use Pagekit\Application\UrlProvider;
use Pagekit\Auth\Encoder\PasswordEncoderInterface;
use Pagekit\Captcha\Attribute\Captcha;
use Pagekit\Mail\Mailer;
use Pagekit\Module\ModuleManager;
use Pagekit\Routing\Attribute\Request as RequestAttr;
use Pagekit\Routing\Router;
use Pagekit\Session\Csrf\Provider\CsrfProviderInterface;
use Pagekit\Session\MessageBag;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;
use Pagekit\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Controller for user registration.
 */
class RegistrationController
{
    use ValidatesRequestTrait;

    protected mixed $userModule = null;

    public function __construct(
        private readonly ModuleManager $module,
        private readonly User $user,
        private readonly CsrfProviderInterface $csrf,
        private readonly MessageBag $message,
        private readonly UrlProvider $url,
        private readonly Mailer $mailer,
        private readonly View $view,
        private readonly Router $router,
        private readonly PasswordEncoderInterface $authPassword,
        protected readonly ValidatorInterface $validator,
        private readonly UserRepository $userRepository,
    ) {
        $this->userModule = $this->module->get('system/user');
    }

    /**
     * @return array<string, mixed>|HttpResponse
     */
    #[Captcha(route: '@user/registration/register')]
    public function indexAction(): array|HttpResponse
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

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|HttpResponse
     */
    #[RequestAttr(['user' => 'array'])]
    #[Captcha(verify: true)]
    public function registerAction(array $data): array|HttpResponse
    {
        try {

            if ($this->user->isAuthenticated() || $this->userModule->config('registration') == 'admin') {
                return $this->router->redirect();
            }

            if (!$this->csrf->validate()) {
                throw new Exception(__('Invalid token. Please try again.'));
            }

            $password = (string) (@$data['password'] ?? '');
            if (trim($password) != $password || strlen($password) < 6) {
                throw new Exception(__('Password must be 6 characters or longer.'));
            }

            $user = $this->userRepository->create([
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

            $this->userRepository->save($user);

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

        $this->message->success((string) $message);

        return [
            'redirect' => ($this->url)('@user/login'),
        ];
    }

    #[RequestAttr(['user' => 'string', 'key' => 'string'])]
    public function activateAction(string $username, string $activation): RedirectResponse
    {
        $user = (empty($username) || empty($activation))
            ? null
            : $this->userRepository->where(['username' => $username, 'activation' => $activation, 'login IS NULL'])->first();

        if ($user === null) {
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

        $this->userRepository->save($user);

        $this->message->success((string) $message);

        return $this->router->redirect('@user/login');
    }

    protected function sendWelcomeEmail(User $user): void
    {
        try {

            $mail = $this->mailer->create();
            $mail->to($user->email ?? '')
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
            $mail->to($user->email ?? '')
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
