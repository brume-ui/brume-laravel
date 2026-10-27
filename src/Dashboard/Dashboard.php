<?php

namespace Brume\Dashboard;

use Brume\Contracts\Renderable;
use Brume\Facades\Brume;
use Brume\Support\ComponentRef;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;

class Dashboard implements Renderable, Responsable
{
    /** @var list<array<string, mixed>> */
    protected array $blocks = [];

    final public function __construct(protected string $title) {}

    public static function make(string $title): static
    {
        return new static($title);
    }

    public function card(string $title, string $body): static
    {
        $this->blocks[] = ['type' => 'card', 'title' => $title, 'body' => $body];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $props
     */
    public function component(string $name, array $props = []): static
    {
        $this->blocks[] = ComponentRef::make($name, $props);

        return $this;
    }

    public function toSchema(Request $request): array
    {
        return ['type' => 'dashboard', 'title' => $this->title, 'blocks' => $this->blocks];
    }

    public function toResponse($request)
    {
        return Brume::render($this)->toResponse($request);
    }
}
