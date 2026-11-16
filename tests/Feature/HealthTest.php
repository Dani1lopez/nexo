<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_application_boots_without_a_database_or_app_key(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_health_endpoint_is_stateless_and_sets_no_cookie(): void
    {
        $this->get('/up')->assertOk()->assertHeaderMissing('Set-Cookie');
    }
}
