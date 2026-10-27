<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->withSession(['auth.access_version' => $user->access_version]);

        return parent::actingAs($user, $guard);
    }
}
