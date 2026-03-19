<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use Pagekit\Application as App;
use Pagekit\Application\Exception;
use Pagekit\Routing\Attribute\Request;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Model\User;
use function Pagekit\__;

/**
 * Controller for user profile management.
 */
class ProfileController
{
    use ValidatesRequestTrait;

    public function __construct(
        private readonly mixed $user,
        private readonly mixed $url,
        private readonly mixed $auth,
    ) {}

    public function indexAction()
    {
        if (!$this->user->isAuthenticated()) {
            return App::redirect('@user/login', ['redirect' => $this->url->current()]); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }

        return [
            '$view' => [
                'title' => __('Profile'),
                'name'  => 'system/user/profile.php'
            ],
            '$data' => [
                'user' => [
                    'name' => $this->user->name,
                    'email' => $this->user->email
                ]
            ]
        ];
    }

    #[Request(['user' => 'array'], csrf: true)]
    public function saveAction(array $data)
    {
        if (!$this->user->isAuthenticated()) {
            App::abort(404); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
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

                $user->password = App::getInstance()->get('auth.password')->hash($password); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
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
            App::abort(400, $e->getMessage()); // TODO: Must be refactored in Step 2.0.1e (StaticTrait Removal)
        }
    }
}
