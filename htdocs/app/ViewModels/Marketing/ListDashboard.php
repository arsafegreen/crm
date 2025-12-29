<?php

declare(strict_types=1);

namespace App\ViewModels\Marketing;

final class ListDashboard
{
    /**
     * @param array<int, array<string, mixed>> $lists
     * @param array<int, array<string, mixed>> $segments
     * @return array{
     *     totals: array{
     *         lists:int,
     *         contacts: array{total:int, subscribed:int},
     *         segments:int
     *     },
     *     statusLabels: array<string,string>
     * }
     */
    public static function summarize(array $lists, array $segments): array
    {
        $totalContacts = 0;
        $totalSubscribed = 0;

        foreach ($lists as $list) {
            $totalContacts += (int)($list['contacts_total'] ?? 0);
            $totalSubscribed += (int)($list['contacts_subscribed'] ?? 0);
        }

        return [
            'totals' => [
                'lists' => count($lists),
                'contacts' => [
                    'total' => $totalContacts,
                    'subscribed' => $totalSubscribed,
                ],
                'segments' => count($segments),
            ],
            'statusLabels' => [
                'active' => 'Ativa',
                'paused' => 'Pausada',
                'archived' => 'Arquivada',
            ],
        ];
    }
}
