<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AppointmentRepository;
use App\Repositories\AvpScheduleRepository;
use DateTimeImmutable;
use DateTimeZone;

final class AvpAvailabilityService
{
    private AvpScheduleRepository $scheduleRepository;
    private AppointmentRepository $appointmentRepository;
    private DateTimeZone $timezone;

    public function __construct(
        ?AvpScheduleRepository $scheduleRepository = null,
        ?AppointmentRepository $appointmentRepository = null,
        ?DateTimeZone $timezone = null
    ) {
        $this->scheduleRepository = $scheduleRepository ?? new AvpScheduleRepository();
        $this->appointmentRepository = $appointmentRepository ?? new AppointmentRepository();
        $this->timezone = $timezone ?? new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
    }

    public function slotsForDate(int $avpUserId, DateTimeImmutable $date): array
    {
        $dayIndex = (int)$date->format('w');
        $config = $this->scheduleRepository->findConfig($avpUserId, $dayIndex);
        if ($config === null) {
            return [];
        }

        if (!empty($config['is_closed'])) {
            return [];
        }

        $workStart = isset($config['work_start_minutes']) ? (int)$config['work_start_minutes'] : null;
        $workEnd = isset($config['work_end_minutes']) ? (int)$config['work_end_minutes'] : null;
        if ($workStart === null || $workEnd === null || $workEnd <= $workStart) {
            return [];
        }

        $slotMinutes = max(10, (int)($config['slot_duration_minutes'] ?? 20));
        $blocked = $this->buildBlockedRanges(
            $config['offline_blocks'] ?? null,
            isset($config['lunch_start_minutes']) ? (int)$config['lunch_start_minutes'] : null,
            isset($config['lunch_end_minutes']) ? (int)$config['lunch_end_minutes'] : null
        );

        $dayStart = $date->setTime(0, 0, 0);
        $dayStartTs = $dayStart->getTimestamp();
        $dayEndTs = $dayStart->setTime(23, 59, 59)->getTimestamp();

        $busyIntervals = $this->appointmentRepository->busyIntervalsForOwner($avpUserId, $dayStartTs, $dayEndTs);
        $now = new DateTimeImmutable('now', $this->timezone);
        $isToday = $date->format('Y-m-d') === $now->format('Y-m-d');
        $nowTs = $now->getTimestamp();

        $available = [];
        for ($minute = $workStart; $minute + $slotMinutes <= $workEnd; $minute += $slotMinutes) {
            $slotStartMinutes = $minute;
            $slotEndMinutes = $minute + $slotMinutes;

            if ($this->intersectsBlocked($slotStartMinutes, $slotEndMinutes, $blocked)) {
                continue;
            }

            $slotStartTs = $dayStartTs + ($slotStartMinutes * 60);
            $slotEndTs = $dayStartTs + ($slotEndMinutes * 60);

            if ($isToday && $slotStartTs <= $nowTs) {
                continue;
            }

            if ($this->intersectsBusy($slotStartTs, $slotEndTs, $busyIntervals)) {
                continue;
            }

            $available[] = [
                'label' => sprintf('%02d:%02d', intdiv($slotStartMinutes, 60), $slotStartMinutes % 60),
                'value' => sprintf('%02d:%02d', intdiv($slotStartMinutes, 60), $slotStartMinutes % 60),
                'timestamp' => $slotStartTs,
                'duration' => $slotMinutes,
                'avp_user_id' => $avpUserId,
            ];
        }

        return $available;
    }

    private function buildBlockedRanges(mixed $offlinePayload, ?int $lunchStart, ?int $lunchEnd): array
    {
        $blocks = [];
        if ($lunchStart !== null && $lunchEnd !== null && $lunchEnd > $lunchStart) {
            $blocks[] = ['start' => $lunchStart * 60, 'end' => $lunchEnd * 60];
        }

        if (is_string($offlinePayload) && trim($offlinePayload) !== '') {
            $decoded = json_decode($offlinePayload, true);
            if (is_array($decoded)) {
                foreach ($decoded as $block) {
                    $start = isset($block['start']) ? (int)$block['start'] : null;
                    $end = isset($block['end']) ? (int)$block['end'] : null;
                    if ($start === null || $end === null || $end <= $start) {
                        continue;
                    }
                    $blocks[] = ['start' => $start * 60, 'end' => $end * 60];
                }
            }
        }

        return $blocks;
    }

    private function intersectsBlocked(int $slotStartMinutes, int $slotEndMinutes, array $blocked): bool
    {
        $slotStart = $slotStartMinutes * 60;
        $slotEnd = $slotEndMinutes * 60;

        foreach ($blocked as $block) {
            if ($this->overlaps($slotStart, $slotEnd, (int)$block['start'], (int)$block['end'])) {
                return true;
            }
        }

        return false;
    }

    private function intersectsBusy(int $slotStartTs, int $slotEndTs, array $busyIntervals): bool
    {
        foreach ($busyIntervals as $interval) {
            $start = isset($interval['start']) ? (int)$interval['start'] : 0;
            $end = isset($interval['end']) ? (int)$interval['end'] : 0;
            if ($start <= 0 || $end <= 0) {
                continue;
            }

            if ($this->overlaps($slotStartTs, $slotEndTs, $start, $end)) {
                return true;
            }
        }

        return false;
    }

    private function overlaps(int $startA, int $endA, int $startB, int $endB): bool
    {
        return $startA < $endB && $startB < $endA;
    }
}
