<?php

declare(strict_types=1);

use App\Database\Migration;
use App\Support\EmailProviderLimitDefaults;
use PDO;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $stmt = $pdo->query('SELECT id, provider, hourly_limit, daily_limit, burst_limit FROM email_accounts');
        $accounts = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($accounts === false || $accounts === []) {
            return;
        }

        foreach ($accounts as $account) {
            $provider = (string)($account['provider'] ?? '');
            $defaults = EmailProviderLimitDefaults::for($provider);
            $updates = [];

            $hourly = (int)($account['hourly_limit'] ?? 0);
            if ($hourly <= 0 && ($defaults['hourly_limit'] ?? 0) > 0) {
                $updates['hourly_limit'] = (int)$defaults['hourly_limit'];
            }

            $daily = (int)($account['daily_limit'] ?? 0);
            if ($daily <= 0 && ($defaults['daily_limit'] ?? 0) > 0) {
                $updates['daily_limit'] = (int)$defaults['daily_limit'];
            }

            $burst = (int)($account['burst_limit'] ?? 0);
            if ($burst <= 0 && ($defaults['burst_limit'] ?? 0) > 0) {
                $updates['burst_limit'] = (int)$defaults['burst_limit'];
            }

            if ($updates === []) {
                continue;
            }

            $updates['updated_at'] = time();
            $updates['id'] = (int)$account['id'];

            $assignments = [];
            foreach ($updates as $column => $_) {
                if ($column === 'id') {
                    continue;
                }
                $assignments[] = sprintf('%s = :%s', $column, $column);
            }

            $sql = 'UPDATE email_accounts SET ' . implode(', ', $assignments) . ' WHERE id = :id';
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($updates);
        }
    }
};
