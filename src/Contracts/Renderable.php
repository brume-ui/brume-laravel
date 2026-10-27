<?php

namespace Brume\Contracts;

use Illuminate\Http\Request;

interface Renderable
{
    /**
     * Return the page in the BrumePage shape of @brume-ui/brume-schema (list, dashboard, ...).
     *
     * @return array<string, mixed>
     */
    public function toSchema(Request $request): array;
}
