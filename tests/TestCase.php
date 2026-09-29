<?php

namespace Tests;

use App\Support\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * App\Support\Tenant caches the current shop in plain class statics (by
     * design — see its docblock), which nothing resets between test methods
     * in the same process. Without this, a test touching more than one shop
     * can inherit a stale tenant left behind by an earlier test and silently
     * scope its queries to the wrong shop.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Tenant::reset();
    }
}
