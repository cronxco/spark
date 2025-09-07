<?php

use Livewire\Volt\Component;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Spatie\Tags\Tag;

new class extends Component {
    public string $modelClass;
    public string $modelId;

    /** @var array<int, string> */
    public array $currentTags = [];

    /** @var array<int, string> */
    public array $allTags = [];

    private EloquentModel $model;

    public function mount(string $modelClass, string $modelId): void
    {
        $this->modelClass = $modelClass;
        $this->modelId = $modelId;

        /** @var EloquentModel $model */
        $model = $modelClass::query()->with('tags')->findOrFail($modelId);
        $this->model = $model;

        $this->refreshData();
    }

    private function refreshData(): void
    {
        $this->model->refresh();
        $this->model->loadMissing('tags');

        $this->currentTags = $this->model->tags
            ->map(function (Tag $tag) {
                return [
                    'value' => (string) $tag->name,
                    'type' => $tag->type ? (string) $tag->type : null,
                ];
            })
            ->values()
            ->all();

        $this->allTags = Tag::query()
            ->select(['id', 'name', 'type'])
            ->get()
            ->map(function (Tag $tag) {
                return [
                    'value' => (string) $tag->name,
                    'type' => $tag->type ? (string) $tag->type : null,
                ];
            })
            ->sort(function ($a, $b) {
                return strnatcasecmp((string) ($a['value'] ?? ''), (string) ($b['value'] ?? ''));
            })
            ->values()
            ->all();
    }

    public function addTag(string $value, ?string $type = null): void
    {
        $name = trim($value);
        if ($name === '') {
            return;
        }

        // If type not explicitly provided, infer from value prefix (e.g., type:label or type_label)
        $detectedType = $type !== null ? trim($type) : null;
        if ($detectedType === null) {
            if (preg_match('/^([A-Za-z0-9-]+)[_:](.+)$/', $name, $m) === 1) {
                $detectedType = strtolower($m[1]);
                $name = trim($m[2]);
            }
        } else {
            // Strip matching prefix from the visible value if present
            if (preg_match('/^' . preg_quote($detectedType, '/') . '[_:](.+)$/i', $name, $m) === 1) {
                $name = trim($m[1]);
            }
        }

        // Default free-form tags to 'spark' unless they are emoji-only
        if ($detectedType === null) {
            $detectedType = preg_match('/^\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?(?:\\x{200D}\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?)*$/u', $name) === 1
                ? 'emoji'
                : 'spark';
        }

        $tag = Tag::findOrCreate($name, $detectedType);
        // Ensure type persisted in case library returned an existing tag without the type set
        if (($tag->type ?? null) !== $detectedType) {
            $tag->type = $detectedType;
            $tag->save();
        }

        // @phpstan-ignore-next-line attachTag provided by HasTags
        $this->model->attachTag($tag);

        $this->refreshData();
        $this->dispatch('tag-added', name: $name);
    }

    public function removeTag(string $value, ?string $type = null): void
    {
        $name = trim($value);
        if ($name === '') {
            return;
        }

        // @phpstan-ignore-next-line detachTag provided by HasTags
        $this->model->detachTag($name, $type ?: null);

        $this->refreshData();
        $this->dispatch('tag-removed', name: $name);
    }
};
?>

@php($cid = md5($this->modelClass . '-' . $this->modelId))

<div class="space-y-2" wire:key="tag-manager-{{ $cid }}">
    <label class="label text-sm text-base-content/70">Tags</label>
    <div class="w-full" wire:ignore>
        <input id="tag-input-{{ $cid }}" data-tagify data-whitelist="tag-whitelist-{{ $cid }}" data-initial="tag-initial-{{ $cid }}" aria-label="Tags" class="w-full" placeholder="Add tags" />
    </div>
    <script type="application/json" id="tag-whitelist-{{ $cid }}">@json($this->allTags)</script>
    <script type="application/json" id="tag-initial-{{ $cid }}">@json($this->currentTags)</script>
</div>


