<?php

namespace Brume\Form;

use Brume\Support\Href;

final class AfterSave
{
    public static function urls(array $urls, ?string $view): array
    {
        $result = [];
        foreach (['list', 'edit', 'create', 'view'] as $key) {
            $url = $key === 'view' ? $view : ($urls[$key] ?? null);
            if ($url !== null && ($safe = Href::checked($url, 'Form::urls.'.$key)) !== null) {
                $result[$key] = $safe;
            }
        }

        if (! isset($result['edit'])) {
            throw new \LogicException('Form submission requires a safe edit URL.');
        }

        return $result;
    }
}
