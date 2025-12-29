<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SocialAccountRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DashboardController
{
    public function index(Request $request): Response
    {
        $socialRepo = new SocialAccountRepository();
        $accounts = $socialRepo->all();

        $metrics = [
            'contacts_total' => 0,
            'segments_total' => 0,
            'campaigns_active' => 0,
            'channels_connected' => count($accounts),
        ];

        return view('dashboard/index', [
            'metrics' => $metrics,
            'socialAccounts' => $accounts,
        ]);
    }
}
