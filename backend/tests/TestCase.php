<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Requests look like they come from the React app (Sanctum cookie session).
        $this->withHeader('Referer', 'http://localhost:5173/');
    }

    /**
     * Sanctum remembers the user of the first request; forget it so a test
     * can switch between users.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        // auth:sanctum switches the default guard to "sanctum" during a request;
        // put it back and start fresh so the next request sees this user.
        config(['auth.defaults.guard' => 'web']);
        $this->app['auth']->forgetGuards();

        return parent::actingAs($user, $guard ?? 'web');
    }
}
