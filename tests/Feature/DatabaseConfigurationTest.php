<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConfigurationTest extends TestCase
{
    public function test_feature_suite_uses_mysql_testing_database(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('bivaco_exam_testing', DB::connection()->getDatabaseName());
    }
}
