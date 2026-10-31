<?php

namespace Brume\Form;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ConditionPath implements JsonSerializable
{
    public function __construct(public string $scope, public string $path)
    {
        if (! in_array($scope, ['root', 'row'], true) || ! preg_match('/^(?:(?!__proto__\b|prototype\b|constructor\b|_brume\b)[A-Za-z_$][A-Za-z0-9_$-]*|[0-9]+)(?:\.(?:(?!__proto__\b|prototype\b|constructor\b|_brume\b)[A-Za-z_$][A-Za-z0-9_$-]*|[0-9]+|\*))*$/D', $path)) {
            throw new InvalidArgumentException('Invalid condition path.');
        }
    }

    public static function root(string $path): self
    {
        return new self('root', $path);
    }

    public static function row(string $path): self
    {
        return new self('row', $path);
    }

    public function resolve(?string $rowPath = null): string
    {
        return $this->scope === 'root' ? $this->path : ($rowPath !== null ? $rowPath.'.'.$this->path : throw new InvalidArgumentException('Row condition requires explicit row context.'));
    }

    public function jsonSerialize(): array
    {
        return ['scope' => $this->scope, 'path' => $this->path];
    }
}
