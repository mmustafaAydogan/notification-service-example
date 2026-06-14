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
            // Redis unavailable in this test context — services using it should fake it.
        }
    }
}
