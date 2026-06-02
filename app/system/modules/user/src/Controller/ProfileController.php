<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use function Pagekit\__;

use Pagekit\Application\Exception;
use Pagekit\Application\UrlProvider;
use Pagekit\Auth\Auth;
use Pagekit\Auth\Encoder\PasswordEncoderInterface;
use Pagekit\Routing\Attribute\Request as RequestAttr;
use Pagekit\Routing\Router;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Model\User;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Controller for user profile management.
 */
class ProfileController
{
    use ValidatesRequestTrait;

    public function __construct(
        private readonly User $user,
        private readonly UrlProvider $url,
        private readonly Auth $auth,
        private readonly Router $router,
        private readonly PasswordEncoderInterface $authPassword,
        protected readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @return array<string, mixed>|HttpResponse
     */
    public function indexAction(): array|HttpResponse
    {
        if (!$this->user->isAuthenticated()) {
            return $this->router->redirect('@user/login', ['redirect' => $this->url->current()]);
        }

        return [
            '$view' => [
                'title' => __('Profile'),
                'name' => 'system/user/profile.php',
            ],
            '$data' => [
                'user' => [
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{message: string}
     */
    #[RequestAttr(['user' => 'array'], csrf: true)]
    public function saveAction(array $data): array
    {
        if (!$this->user->isAuthenticated()) {
            throw new NotFoundHttpException();
        }

        try {

            $user = User::find($this->user->id);

            if ($password = @$data['password_new']) {

                if (!$this->auth->getUserProvider()->validateCredentials($user, ['password' => @$data['password_old']])) {
                    throw new Exception(__('Invalid Password.'));
                }

                if (trim($password) != $password || strlen($password) < 3) {
                    throw new Exception(__('Invalid Password.'));
                }

                $user->password = $this->authPassword->hash($password);
            }

            if (@$data['email'] != $user->email) {
                $user->set('verified', false);
            }

            $user->name = @$data['name'];
            $user->email = @$data['email'];

            $this->validateOrFail($user);

            $user->save();

            return ['message' => 'success'];

        } catch (Exception $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }
    }
}
