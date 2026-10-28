<?php

namespace Brume\Pages;

use Brume\Contracts\Renderable;
use Brume\Facades\Brume;
use Illuminate\Http\Request;

/**
 * The protocol's ErrorPage, { type: 'error', status, title, message, action? }, rendered inside
 * the shell.
 */
final class ErrorPage implements Renderable
{
    /**
     * @param  array{label: string, url: string}|null  $action
     */
    public function __construct(
        private int $status,
        private string $title,
        private string $message,
        private ?array $action = null,
    ) {}

    /**
     * 404: the path is not a Brume page, with a link back to the home page.
     */
    public static function notFound(): self
    {
        return new self(404, __('brume::error.not_found.title'), __('brume::error.not_found.message'), [
            'label' => __('brume::error.not_found.action'),
            'url' => Brume::url('/'),
        ]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function toSchema(Request $request): array
    {
        return [
            'type' => 'error',
            'status' => $this->status,
            'title' => $this->title,
            'message' => $this->message,
        ] + ($this->action !== null ? ['action' => $this->action] : []);
    }
}
