<?php

namespace App\Services\Integration;

use App\Models\IntegrationService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class IntegrationServiceSchedule
{
    public function nextRunAt(IntegrationService $service, ?CarbonInterface $from = null): CarbonInterface
    {
        $from ??= now();
        $settings = $service->settings ?? [];
        $dailyAt = $settings['daily_at'] ?? null;

        if (! is_string($dailyAt) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $dailyAt)) {
            return $from->copy()->addMinutes($service->frequency_minutes);
        }

        $timezone = (string) ($settings['schedule_timezone'] ?? config('app.display_timezone', 'America/Sao_Paulo'));
        $localNow = Carbon::instance($from)->setTimezone($timezone);
        $candidate = Carbon::createFromFormat('Y-m-d H:i', $localNow->format('Y-m-d').' '.$dailyAt, $timezone);
        if ($candidate->lessThanOrEqualTo($localNow)) {
            $candidate->addDay();
        }

        return $candidate->utc();
    }
}
