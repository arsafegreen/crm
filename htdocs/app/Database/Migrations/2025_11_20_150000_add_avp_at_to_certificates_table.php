<?php

declare(strict_types=1);

use App\Database\Migration;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $columns = $this->columns($pdo, 'certificates');

        if (!isset($columns['avp_at'])) {
            $pdo->exec('ALTER TABLE certificates ADD COLUMN avp_at INTEGER NULL');
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_certificates_avp_at ON certificates(avp_at)');

        $this->backfillAvpDates($pdo);
    }

    /**
     * @return array<string, bool>
     */
    private function columns(\PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare('PRAGMA table_info(' . $table . ')');
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $info) {
            if (!empty($info['name'])) {
                $map[(string)$info['name']] = true;
            }
        }

        return $map;
    }

    private function backfillAvpDates(\PDO $pdo): void
    {
        $stmt = $pdo->query('SELECT id, source_payload, start_at FROM certificates WHERE (avp_at IS NULL OR avp_at = 0)');
        if ($stmt === false) {
            return;
        }

        $update = $pdo->prepare('UPDATE certificates SET avp_at = :avp_at WHERE id = :id');

        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $id = isset($row['id']) ? (int)$row['id'] : null;
            if ($id === null || $id <= 0) {
                continue;
            }

            $payload = is_string($row['source_payload'] ?? null) ? $row['source_payload'] : null;
            $avpAt = null;

            if ($payload !== null && trim($payload) !== '') {
                $avpAt = $this->extractAvpTimestamp($payload);
            }

            if ($avpAt === null) {
                $startAt = isset($row['start_at']) ? (int)$row['start_at'] : null;
                if ($startAt !== null && $startAt > 0) {
                    $avpAt = $startAt;
                }
            }

            if ($avpAt === null) {
                continue;
            }

            $update->execute([
                ':avp_at' => $avpAt,
                ':id' => $id,
            ]);
        }
    }

    private function extractAvpTimestamp(?string $payload): ?int
    {
        if ($payload === null || trim($payload) === '') {
            return null;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return null;
        }

        $value = $data['Data AVP']
            ?? $data['data_avp']
            ?? $data['Data Avp']
            ?? $data['data Avp']
            ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return $this->toTimestamp($value);
    }

    private function toTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return (int)ExcelDate::excelToTimestamp((float)$value);
            } catch (\Throwable) {
                // ignore
            }
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $timezone = new \DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $formats = ['d/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y'];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->getTimestamp();
            }
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }
};
