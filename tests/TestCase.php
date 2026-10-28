<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    private string $mediaTestToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaTestToken = bin2hex(random_bytes(12));
        ParallelTesting::resolveTokenUsing(fn () => $this->mediaTestToken);
    }

    protected function tearDown(): void
    {
        if (isset($this->mediaTestToken) && $this->app) {
            File::deleteDirectory(storage_path('framework/testing/disks/media_test_'.$this->mediaTestToken));
        }
        parent::tearDown();
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $user = $this->app['auth']->guard('web')->user();
        if ($user && ! array_key_exists('HTTP_X_SESSION_CONTEXT', $server)) {
            $server['HTTP_X_SESSION_CONTEXT'] = $user->id.':'.$user->access_version;
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        $this->withSession(['auth.access_version' => $user->access_version]);

        return parent::actingAs($user, $guard);
    }
}
