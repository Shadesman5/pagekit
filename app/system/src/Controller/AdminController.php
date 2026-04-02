<?php

declare(strict_types=1);

namespace Pagekit\System\Controller;

use Pagekit\Auth\Auth;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\User;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class AdminController
{
    public function __construct(
        private readonly mixed $user,
        private readonly mixed $session,
        private readonly mixed $url,
        private readonly mixed $router,
    ) {
    }

    #[Access(admin: true)]
    public function indexAction()
    {
        return $this->router->redirect('@dashboard');
    }

    #[Route('/admin/login', defaults: ['_maintenance' => true])]
    #[Request(['redirect' => 'string', 'message' => 'string'])]
    public function loginAction($redirect = '', $message = '')
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

    #[Access(admin: true)]
    #[Request(['order' => 'array'])]
    public function adminMenuAction($order): array
    {
        if (!$order) {
            throw new BadRequestHttpException(__('Missing order data.'));
        }

        $user = User::find($this->user->id);
        $user->set('admin.menu', $order);
        $user->save();

        return ['message' => __('Order saved.')];
    }

}
