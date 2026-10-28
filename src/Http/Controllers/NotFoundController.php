<?php

namespace Brume\Http\Controllers;

use Brume\Facades\Brume;
use Brume\Http\ErrorResponse;
use Brume\Pages\ErrorPage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Brume route group's fallback: an unknown path under the prefix answers the styled 404
 * page inside the shell (a full page or an Inertia visit), with status 404. A JSON request that
 * is not an Inertia visit (fetch, axios, adapter.request()) gets the protocol's ErrorResponse.
 */
class NotFoundController
{
    public function __invoke(Request $request): Response
    {
        $page = ErrorPage::notFound();

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return ErrorResponse::make(__('brume::error.not_found.title'), $page->status());
        }

        return Brume::render($page)->toResponse($request)->setStatusCode($page->status());
    }
}
