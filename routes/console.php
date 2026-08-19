<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\Integration\IntegrationServiceDispatcher;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(IntegrationServiceDispatcher::class)->dispatchDue())
    ->name('dispatch-integration-services')->everyMinute()->withoutOverlapping();
