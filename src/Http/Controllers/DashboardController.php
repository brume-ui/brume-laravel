<?php

namespace Brume\Http\Controllers;

use Brume\Contracts\Renderable;
use Brume\Facades\Brume;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Default home page. Placeholder until the Dashboard DSL exists.
 */
class DashboardController
{
    public function __invoke(): Response
    {
        return Brume::render(new class implements Renderable
        {
            public function toSchema(Request $request): array
            {
                return [
                    'type' => 'dashboard',
                    'title' => __('brume::menu.dashboard'),
                    'blocks' => [],
                ];
            }
        });
    }
}
