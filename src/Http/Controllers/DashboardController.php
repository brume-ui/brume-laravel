<?php

namespace Brume\Http\Controllers;

use Brume\Dashboard\Dashboard;
use Brume\Facades\Brume;
use Inertia\Response;

/**
 * Default home page: a welcome card. Point `brume.home` at your own controller to replace it.
 */
class DashboardController
{
    public function __invoke(): Response
    {
        return Brume::render(
            Dashboard::make(__('brume::menu.dashboard'))
                ->card(__('brume::dashboard.welcome_title'), __('brume::dashboard.welcome_body'))
        );
    }
}
