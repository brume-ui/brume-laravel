<?php

namespace Brume\Grid\Actions;

use Brume\Support\Href;
use InvalidArgumentException;

/**
 * What the page does after an action: a toast, a download, then a redirect or a refresh (a redirect
 * wins when both are set). Matches the Feedback schema.
 */
final class Feedback
{
    public const TOAST_TYPES = ['success', 'error', 'info', 'warning'];

    /** @var array{type: string, message: string}|null */
    protected ?array $toast = null;

    protected ?bool $refresh = null;

    protected ?string $redirect = null;

    /** @var array{url: string, filename?: string}|null */
    protected ?array $download = null;

    /**
     * Feedback without a toast, for a refresh, redirect or download only.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  'success'|'error'|'info'|'warning'  $type
     */
    public static function toast(string $type, string $message): self
    {
        if (! in_array($type, self::TOAST_TYPES, true)) {
            throw new InvalidArgumentException("Feedback::toast() 的類型「{$type}」不存在，可用的有 ".implode('、', self::TOAST_TYPES).'。');
        }

        $feedback = new self;
        $feedback->toast = ['type' => $type, 'message' => $message];

        return $feedback;
    }

    public function refresh(bool $on = true): self
    {
        $this->refresh = $on;

        return $this;
    }

    /**
     * Visit $url after the action. Only http, https, mailto, tel or relative URLs (Href::checked()).
     */
    public function redirect(string $url): self
    {
        $this->redirect = Href::checked($url, 'Feedback::redirect()');

        return $this;
    }

    /**
     * Download $url after the action. Only http, https, mailto, tel or relative URLs (Href::checked()).
     */
    public function download(string $url, ?string $filename = null): self
    {
        $url = Href::checked($url, 'Feedback::download()');
        $this->download = $url !== null ? ['url' => $url] + ($filename !== null ? ['filename' => $filename] : []) : null;

        return $this;
    }

    /**
     * @return array{toast?: array{type: string, message: string}, refresh?: bool, redirect?: string, download?: array{url: string, filename?: string}}
     */
    public function toArray(): array
    {
        return array_filter([
            'toast' => $this->toast,
            'refresh' => $this->refresh,
            'redirect' => $this->redirect,
            'download' => $this->download,
        ], fn ($value) => $value !== null);
    }
}
