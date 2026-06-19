<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection()->flushdb();
        } catch (\Throwable $e) {
            // Redis is required for the idempotency-path feature tests. Where it
            // is unavailable the flush is skipped and those tests surface the
            // connection error themselves rather than failing here.
        }
    }
}
