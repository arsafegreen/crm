<?php

declare(strict_types=1);

use App\Database\Migration;

return new class extends Migration {
    public function up(\PDO $pdo): void
    {
        $this->addColumn($pdo, 'campaigns', 'template_id', 'INTEGER NULL');
        $this->addColumn($pdo, 'campaigns', 'template_version_id', 'INTEGER NULL');
        $this->addColumn($pdo, 'campaign_messages', 'template_version_id', 'INTEGER NULL');

        $this->createIndex($pdo, 'idx_campaigns_template_id', 'CREATE INDEX idx_campaigns_template_id ON campaigns(template_id)');
        $this->createIndex($pdo, 'idx_campaigns_template_version', 'CREATE INDEX idx_campaigns_template_version ON campaigns(template_version_id)');
        $this->createIndex($pdo, 'idx_campaign_messages_template_version', 'CREATE INDEX idx_campaign_messages_template_version ON campaign_messages(template_version_id)');

        $this->backfillCampaignVersions($pdo);
    }

    private function addColumn(\PDO $pdo, string $table, string $column, string $definition): void
    {
        if ($this->columnExists($pdo, $table, $column)) {
            return;
        }

        $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('PRAGMA table_info(' . $table . ')');
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($columns as $info) {
            if (($info['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    private function createIndex(\PDO $pdo, string $indexName, string $statement): void
    {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'index' AND name = :name LIMIT 1");
        $stmt->execute([':name' => $indexName]);
        $exists = (bool)$stmt->fetchColumn();

        if ($exists) {
            return;
        }

        $pdo->exec($statement);
    }

    private function backfillCampaignVersions(\PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'campaigns', 'template_id')) {
            return;
        }

        $campaignStmt = $pdo->query('SELECT id, template_id FROM campaigns WHERE template_id IS NOT NULL');
        if ($campaignStmt === false) {
            return;
        }

        $latestStmt = $pdo->prepare('SELECT latest_version_id FROM templates WHERE id = :template_id LIMIT 1');
        $fallbackStmt = $pdo->prepare('SELECT id FROM template_versions WHERE template_id = :template_id ORDER BY (published_at IS NULL), published_at DESC, updated_at DESC LIMIT 1');
        $updateCampaign = $pdo->prepare('UPDATE campaigns SET template_version_id = :version_id WHERE id = :campaign_id');

        while ($row = $campaignStmt->fetch(PDO::FETCH_ASSOC)) {
            $templateId = (int)($row['template_id'] ?? 0);
            $campaignId = (int)($row['id'] ?? 0);

            if ($templateId <= 0 || $campaignId <= 0) {
                continue;
            }

            $versionId = $this->resolveVersionId($latestStmt, $fallbackStmt, $templateId);
            if ($versionId === null) {
                continue;
            }

            $updateCampaign->execute([
                ':version_id' => $versionId,
                ':campaign_id' => $campaignId,
            ]);
        }

        $pdo->exec(
            'UPDATE campaign_messages
             SET template_version_id = (
                 SELECT template_version_id FROM campaigns WHERE campaigns.id = campaign_messages.campaign_id
             )
             WHERE template_version_id IS NULL'
        );
    }

    private function resolveVersionId(\PDOStatement $latestStmt, \PDOStatement $fallbackStmt, int $templateId): ?int
    {
        $latestStmt->execute([':template_id' => $templateId]);
        $latest = $latestStmt->fetchColumn();
        if ($latest !== false && (int)$latest > 0) {
            return (int)$latest;
        }

        $fallbackStmt->execute([':template_id' => $templateId]);
        $fallback = $fallbackStmt->fetchColumn();
        if ($fallback !== false && (int)$fallback > 0) {
            return (int)$fallback;
        }

        return null;
    }
};
