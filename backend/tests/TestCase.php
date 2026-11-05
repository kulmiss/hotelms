<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Requests look like they come from the React app (Sanctum cookie session).
        $this->withHeader('Referer', 'http://localhost:5173/');
    }
}
