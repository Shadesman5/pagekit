<?php

declare(strict_types=1);

namespace Pagekit\System\Controller;

use Pagekit\Application\UrlProvider;
use Pagekit\Auth\Auth;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;
use Pagekit\Routing\Router;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\User;
use Pagekit\User\Model\UserRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class AdminController
{
    public function __construct(
        private readonly User $user,
        private readonly SessionInterface $session,
        private readonly UrlProvider $url,
        private readonly Router $router,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[Access(admin: true)]
    public function indexAction(): RedirectResponse
    {
        return $this->router->redirect('@dashboard');
    }

    /**
     * @return array<string, mixed>|RedirectResponse
     */
    #[Route('/admin/login', defaults: ['_maintenance' => true])]
    #[Request(['redirect' => 'string', 'message' => 'string'])]
    public function loginAction(string $redirect = '', string $message = ''): array|RedirectResponse
    {
        if ($this->user->isAuthenticated()) {
            return $this->router->redirect('@system');
        }

        return [
            '$view' => [
                'title' => __('Login'),
                'name' => 'system/theme:views/login.php',
                'layout' => false,
            ],
            'last_username' => $this->session->get(Auth::LAST_USERNAME),
            'redirect' => $redirect ?: ($this->url)('@system'),
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed> $order
     * @return array<string, string>
     */
    #[Access(admin: true)]
    #[Request(['order' => 'array'])]
    public function adminMenuAction(array $order): array
    {
        if (!$order) {
            throw new BadRequestHttpException(__('Missing order data.'));
        }

        $user = $this->userRepository->find((int) $this->user->id);
        if ($user === null) {
            throw new BadRequestHttpException(__('User not found.'));
        }
        $user->set('admin.menu', $order);
        $this->userRepository->save($user);

        return ['message' => __('Order saved.')];
    }

}
