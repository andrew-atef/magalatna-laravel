<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Network guard: the local .env carries PRODUCTION Cloudflare
        // purge credentials, and under QUEUE_CONNECTION=sync every
        // fixture save fires a REAL purge API call. A burst of them
        // (any test creating many flyers) rate-limit-stalls the whole
        // test run for minutes. Blank the credentials so observer
        // jobs short-circuit with ZERO network I/O; tests that need
        // the credentials (PurgeCloudflareCacheJobTest) set their own
        // with Http::fake().
        config([
            'services.cloudflare.api_token' => null,
            'services.cloudflare.zone_id' => null,
            'services.indexnow.key' => null,
        ]);
    }
}
