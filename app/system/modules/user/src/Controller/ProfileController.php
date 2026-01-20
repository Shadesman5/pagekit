<?php

declare(strict_types=1);

namespace Pagekit\User\Controller;

use Pagekit\Application as App;
use Pagekit\Application\Exception;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Model\User;
use function Pagekit\__;

/**
 * Controller for user profile management.
 *
 * Uses Symfony Validator for entity validation (Step 1.13 - Hybrid Mode).
 */
class ProfileController
{
    use ValidatesRequestTrait;

    public function indexAction()
    {
        $user = App::user();

        if (!$user->isAuthenticated()) {
            return App::redirect('@user/login', ['redirect' => App::url()->current()]);
        }

        return [
            '$view' => [
                'title' => __('Profile'),
                'name'  => 'system/user/profile.php'
            ],
            '$data' => [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email
                ]
            ]
        ];
    }

    /**
     * Save user profile changes.
     *
     * Uses Symfony Validator for validation (replaces old $user->validate() method).
     *
     * @Request({"user": "array"}, csrf=true)
     */
    public function saveAction(array $data)
    {
        $user = App::user();

        if (!$user->isAuthenticated()) {
            App::abort(404);
        }

        try {

            $user = User::find($user->id);

            if ($password = @$data['password_new']) {

                if (!App::auth()->getUserProvider()->validateCredentials($user, ['password' => @$data['password_old']])) {
                    throw new Exception(__('Invalid Password.'));
                }

                if (trim($password) != $password || strlen($password) < 3) {
                    throw new Exception(__('Invalid Password.'));
                }

                $user->password = App::getInstance()['auth.password']->hash($password);
            }

            if (@$data['email'] != $user->email) {
                $user->set('verified', false);
            }

            $user->name = @$data['name'];
            $user->email = @$data['email'];

            // Validate using Symfony Validator (replaces old $user->validate() call)
            // Rule #4: DELETE OVER WRAP - old validate() method has been removed
            $this->validateOrFail($user);

            $user->save();

            return ['message' => 'success'];

        } catch (Exception $e) {
            App::abort(400, $e->getMessage());
        }
    }
}
