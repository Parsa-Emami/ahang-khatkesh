<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApplicationShellTest extends TestCase
{
    public function test_application_shell_is_private_and_loads_the_frontend_entry(): void
    {
        $this->get('/')->assertOk()->assertSee('id="root"', false)->assertSee('noindex, nofollow', false);
        $this->getJson('/api/v1/not-a-real-endpoint')->assertNotFound();
    }
}
