<?php

namespace Brume\Form;

use Closure;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** A link styled as a button. It has no value and is not a field; the URL is checked when the page renders. */
class ButtonNode extends FormNode
{
    public function __construct(protected string $label, protected string|Closure $url)
    {
        parent::__construct('button');
    }

    public function newTab(bool $on = true): static
    {
        $this->attributes['newTab'] = $on;

        return $this;
    }

    public function secondary(bool $on = true): static
    {
        $this->attributes['variant'] = $on ? 'secondary' : 'primary';

        return $this;
    }

    public function mapFields(Closure $map): FormNode
    {
        return clone $this;
    }

    public function toSchema(?Model $record = null, ?Model $root = null): array
    {
        $url = $this->url instanceof Closure ? ($this->url)($record) : $this->url;
        if (! is_string($url) || ! self::isHref($url)) {
            throw new InvalidArgumentException('A form button needs an absolute http(s) URL or a path starting with a single "/".');
        }

        return ['type' => 'button', 'label' => $this->label, 'href' => $url]
            + (($this->attributes['newTab'] ?? false) ? ['newTab' => true] : [])
            + (($this->attributes['variant'] ?? 'primary') === 'secondary' ? ['variant' => 'secondary'] : []);
    }

    /**
     * The renderer's `isButtonHref`: an absolute http or https URL with a host, or a path with a
     * single leading slash. No whitespace, control character or backslash anywhere; so no `//host`,
     * `mailto:`, `tel:` or `javascript:`.
     */
    public static function isHref(string $href): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $href)) {
            return false;
        }
        if (preg_match('~^/(?![/\\\\])~', $href)) {
            return true;
        }
        if (! preg_match('~^https?://[^/?#]~i', $href)) {
            return false;
        }
        $host = parse_url($href, PHP_URL_HOST);

        // The browser's URL parser refuses hosts with these characters; a bracketed IPv6 literal is the only use of "[".
        return is_string($host) && $host !== '' && (preg_match('/^\[[0-9a-fA-F:.]+\]$/', $host) || ! preg_match('/[\x00-\x20#\/:<>?@\[\\\\\]^|%]/', $host));
    }
}
