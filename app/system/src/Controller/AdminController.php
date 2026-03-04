<?php

declare(strict_types=1);

namespace Pagekit\System\Controller;

use Pagekit\Application as App;
use Pagekit\Auth\Auth;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;
use Pagekit\User\Attribute\Access;
use Pagekit\User\Model\User;

class AdminController
{
    public function __construct(
        private readonly mixed $user,
        private readonly mixed $session,
        private readonly mixed $url,
    ) {}

    #[Access(admin: true)]
    public function indexAction()
    {
        return App::redirect('@dashboard'); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
    }

    #[Route('/admin/login', defaults: ['_maintenance' => true])]
    #[Request(['redirect' => 'string', 'message' => 'string'])]
    public function loginAction($redirect = '', $message = '')
    {
        if ($this->user->isAuthenticated()) {
            return App::redirect('@system'); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }

        return [
            '$view' => [
                'title'  => __('Login'),
                'name'   => 'system/theme:views/login.php',
                'layout' => false
            ],
            'last_username' => $this->session->get(Auth::LAST_USERNAME),
            'redirect' => $redirect ?: ($this->url)('@system'),
            'message' => $message
        ];
    }

    #[Access(admin: true)]
    #[Request(['order' => 'array'])]
    public function adminMenuAction($order): array
    {
        if (!$order) {
            App::abort(400, __('Missing order data.')); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }

        $user = User::find($this->user->id);
        $user->set('admin.menu', $order);
        $user->save();

        return ['message' => __('Order saved.')];
    }

}
