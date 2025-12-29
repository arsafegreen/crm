<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Repositories\Marketing\AudienceListRepository;

/**
 * Centraliza as rotinas de seed/garantia das listas necessárias para o módulo de e-mail.
 * Mantém a lógica fora do controller e facilita reaproveitamento em jobs ou comandos.
 */
final class ListMaintenanceService
{
    private AudienceListRepository $lists;

    public function __construct(?AudienceListRepository $lists = null)
    {
        $this->lists = $lists ?? new AudienceListRepository();
    }

    /**
     * Garante que grupo de teste e listas de manutenção existam.
     */
    public function bootstrapDefaults(array $maintenanceDefinitions): void
    {
        $this->seedTestGroup();
        $this->ensureMaintenanceLists($maintenanceDefinitions);
    }

    private function seedTestGroup(): void
    {
        try {
            $this->lists->upsert([
                'name' => 'Grupo Teste',
                'slug' => 'grupo-teste',
                'description' => 'Grupo de teste para validar envios em grupo.',
                'origin' => 'manual',
                'purpose' => 'Teste de grupos de e-mail',
                'status' => 'active',
            ]);
        } catch (\Throwable $exception) {
            @error_log('Seed grupo teste falhou: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<int, array<string, string>> $definitions
     */
    private function ensureMaintenanceLists(array $definitions): void
    {
        foreach ($definitions as $payload) {
            try {
                $this->lists->upsert($payload);
            } catch (\Throwable $exception) {
                @error_log('Seed lista de manutenção falhou: ' . $exception->getMessage());
            }
        }
    }
}
