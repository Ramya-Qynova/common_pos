<?php

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Every Phase 1+ test needs the platform catalogue (permissions,
     * module_definitions, plans, templates, languages, platform_settings) -
     * real seeded data rows, not test-only fixtures, since the whole point
     * is proving the app behaves correctly against the same seed data it
     * ships with (rule 1.11/13).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }
}
