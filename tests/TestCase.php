<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests check markup and behaviour; the compiled stylesheet is covered by the e2e run.
        $this->withoutVite();
    }
}
