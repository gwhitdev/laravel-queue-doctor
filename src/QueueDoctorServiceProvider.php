<?php

namespace QueueDoctor;

use Illuminate\Support\ServiceProvider;
use QueueDoctor\Commands\QueueDoctorCommand;

class QueueDoctorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                QueueDoctorCommand::class,
            ]);
        }
    }
}
