<?php

namespace App\Services\Actions;

use InvalidArgumentException;

/**
 * One command the app can run. The registry is the only place a visible
 * command is declared: Spotlight and the native Actions endpoint both render
 * from it, and the completeness test fails when a definition is unowned,
 * lacks a capability, or has nothing to run.
 */
final readonly class ActionDefinition
{
    /**
     * @param  array<string, array{type: string, required?: bool}>  $target  fields the caller supplies; `type` is uuid|string|integer
     * @param  array<string, mixed>  $params  fixed values merged into the target, so one handler serves several actions
     * @param  array<int, string>  $surfaces  web and/or native
     * @param  array{route: string}|null  $destination  navigation only
     * @param  class-string<ActionHandler>|null  $handler
     * @param  array<int, string>  $keywords
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $subtitle,
        public string $icon,
        public string $domain,
        public ActionKind $kind,
        public ConfirmPolicy $confirm = ConfirmPolicy::None,
        public ?string $capability = null,
        public ?string $handler = null,
        public array $target = [],
        public array $params = [],
        public array $surfaces = ['web', 'native'],
        public ?array $destination = null,
        public array $keywords = [],
    ) {
        if ($kind === ActionKind::Navigation && $destination === null) {
            throw new InvalidArgumentException("Action {$id} is navigation but has no destination.");
        }
    }

    public function isNavigation(): bool
    {
        return $this->kind === ActionKind::Navigation;
    }

    public function on(string $surface): bool
    {
        return in_array($surface, $this->surfaces, true);
    }

    /** Whether Spotlight's ">" mode can run it with no input and no confirm step. */
    public function runsWithoutInput(): bool
    {
        return $this->on('web')
            && $this->confirm !== ConfirmPolicy::Confirm
            && collect($this->target)->doesntContain(fn (array $field): bool => $field['required'] ?? true);
    }

    public function matches(string $query): bool
    {
        $query = strtolower(trim($query));

        if ($query === '') {
            return true;
        }

        return str_contains(strtolower($this->title), $query)
            || collect($this->keywords)->contains(fn (string $keyword): bool => str_contains(strtolower($keyword), $query));
    }

    /**
     * Laravel rules for the caller-supplied target.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return collect($this->target)->mapWithKeys(function (array $field, string $name): array {
            $rules = [($field['required'] ?? true) ? 'required' : 'nullable'];
            $rules[] = match ($field['type']) {
                'uuid' => 'uuid',
                'integer' => 'integer',
                default => 'string',
            };

            if ($field['type'] === 'string') {
                $rules[] = 'max:255';
            }

            return [$name => $rules];
        })->all();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'icon' => $this->icon,
            'domain' => $this->domain,
            'kind' => $this->kind->value,
            'confirm' => $this->confirm->value,
            'capability' => $this->capability,
            'target' => (object) $this->target,
            'destination' => $this->destination === null ? null : [
                'route' => $this->destination['route'],
                'path' => route($this->destination['route'], absolute: false),
            ],
        ];
    }
}
