<?php

namespace QueueDoctor\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use QueueDoctor\QueueDoctorServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            QueueDoctorServiceProvider::class,
        ];
    }
}
