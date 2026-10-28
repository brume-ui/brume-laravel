<?php

namespace Brume\Http\Controllers;

use Brume\Facades\Brume;
use Brume\Pages\ErrorPage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Brume route group's fallback: an unknown path under the prefix answers the styled 404
 * page inside the shell (a full page or an Inertia visit), with status 404.
 */
class NotFoundController
{
    public function __invoke(Request $request): Response
    {
        $page = ErrorPage::notFound();

        return Brume::render($page)->toResponse($request)->setStatusCode($page->status());
    }
}
