<?php

declare(strict_types=1);

namespace Armp\ErrorTracker;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * The logged-in user, as reported with requests and exceptions.
 *
 * Only a user the guard has already resolved is reported, so reporting
 * never starts a session or database lookup of its own. The id is always
 * included; name and email only when `send_user_details` is on.
 */
class CurrentUser
{
    public function __construct(
        protected AuthFactory $auth,
        protected Config $config,
    ) {}

    /**
     * @return array{id: int|string, name?: string, email?: string}|null
     */
    public function resolve(): ?array
    {
        try {
            $guard = $this->auth->guard();

            if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
                return null;
            }

            $user = $guard->user();
            $id = $user?->getAuthIdentifier();

            if (! is_int($id) && ! is_string($id)) {
                return null;
            }

            $resolved = ['id' => $id];

            if ($this->config->get('error-tracker.send_user_details', false)) {
                $name = $user->name ?? null;
                $email = $user->email ?? null;

                if (is_string($name) && $name !== '') {
                    $resolved['name'] = $name;
                }

                if (is_string($email) && $email !== '') {
                    $resolved['email'] = $email;
                }
            }

            return $resolved;
        } catch (Throwable) {
            return null;
        }
    }
}
