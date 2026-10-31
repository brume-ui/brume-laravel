<?php

namespace Brume\Form\Fields;

use Brume\Form\ConditionPath;
use Brume\Form\Conditions;
use Brume\Options\OptionSource;
use Brume\Support\Href;
use LogicException;

trait HasRemoteOptions
{
    protected ?OptionSource $source = null;

    protected array $dependencies = [];

    protected ?string $optionsEndpoint = null;

    public function remote(OptionSource $source, array $dependsOn = []): static
    {
        if (! in_array($this->type(), ['select', 'multipleSelect', 'tags', 'relation'], true)) {
            throw new \InvalidArgumentException('Remote source requires a form select, multipleSelect, tags or relation.');
        }
        $dependsOn = array_map(Conditions::path(...), $dependsOn);
        foreach ($dependsOn as $name => $path) {
            if (! is_string($name) || ! $path instanceof ConditionPath || str_contains($path->path, '*')) {
                throw new \InvalidArgumentException('Invalid source dependency.');
            }
        }
        $this->source = $source;
        $this->dependencies = $dependsOn;
        unset($this->schema['options']);

        return $this;
    }

    public function optionSource(): ?OptionSource
    {
        return $this->source;
    }

    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /** Bound only by the resource endpoint registrar; never fabricated by the declaration. */
    public function optionsEndpoint(string $url): static
    {
        $this->optionsEndpoint = Href::checked($url, 'Options endpoint');

        return $this;
    }

    protected function remoteSchema(): array
    {
        if ($this->source === null) {
            return [];
        }
        if ($this->optionsEndpoint === null) {
            throw new LogicException('Remote options require a registered endpoint.');
        }

        return ['remote' => ['url' => $this->optionsEndpoint] + ($this->dependencies !== [] ? ['dependsOn' => array_map(fn ($p) => $p->jsonSerialize(), $this->dependencies)] : [])];
    }
}
