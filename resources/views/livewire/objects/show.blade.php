<?php

use App\Models\EventObject;
use App\Models\Event;
use App\Models\Block;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use function Livewire\Volt\layout;
use App\Integrations\PluginRegistry;
use Spatie\Activitylog\Models\Activity;
use Spatie\Tags\Tag;

layout('components.layouts.app');

new class extends Component {
    public EventObject $object;
    public bool $showSidebar = false;
    public string $comment = '';
    public bool $activityOpen = true;
    public bool $objectMetaOpen = false;

    public function mount(EventObject $object): void
    {
        $this->object = $object->load(['tags']);
    }

    public function toggleSidebar(): void
    {
        $this->showSidebar = ! $this->showSidebar;
    }

    public function getRelatedEvents()
    {
        // Find events where this object is either actor or target
        return Event::with(['actor', 'target', 'integration', 'tags'])
            ->whereHas('integration', function ($q) {
                $userId = optional(auth()->guard('web')->user())->id;
                if ($userId) {
                    $q->where('user_id', $userId);
                } else {
                    $q->whereRaw('1 = 0');
                }
            })
            ->where(function ($q) {
                $q->where('actor_id', $this->object->id)
                  ->orWhere('target_id', $this->object->id);
            })
            ->orderBy('time', 'desc')
            ->limit(10)
            ->get();
    }

    public function getActivities()
    {
        return Activity::forSubject($this->object)
            ->latest()
            ->get();
    }

    public function addComment(): void
    {
        $text = trim($this->comment);
        if ($text === '') {
            return;
        }

        activity('changelog')
            ->performedOn($this->object)
            ->causedBy(auth()->guard('web')->user())
            ->event('comment')
            ->withProperties(['comment' => $text])
            ->log('comment');

        $this->comment = '';
    }

    public function formatAction($action)
    {
        return format_action_title($action);
    }

    public function formatJson($data)
    {
        if (is_array($data) || is_object($data)) {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return $data;
    }

    public function getObjectIcon($type, $concept, $service = null)
    {
        // Try to get icon from plugin configuration first if service is available
        if ($service) {
            $pluginClass = PluginRegistry::getPlugin($service);
            if ($pluginClass) {
                $objectTypes = $pluginClass::getObjectTypes();
                if (isset($objectTypes[$concept]) && isset($objectTypes[$concept]['icon'])) {
                    return $objectTypes[$concept]['icon'];
                }
            }
        }

        // Fallback to hardcoded icons if plugin doesn't have this object type
        $icons = [
            'user' => 'o-user',
            'post' => 'o-document-text',
            'comment' => 'o-chat-bubble-left',
            'like' => 'o-heart',
            'share' => 'o-share',
            'file' => 'o-document',
            'image' => 'o-photo',
            'video' => 'o-video-camera',
            'audio' => 'o-musical-note',
            'link' => 'o-link',
            'location' => 'o-map-pin',
            'event' => 'o-calendar',
            'group' => 'o-user-group',
            'page' => 'o-document-text',
            'product' => 'o-shopping-bag',
            'order' => 'o-shopping-cart',
            'payment' => 'o-credit-card',
            'transaction' => 'o-arrow-path',
            'account' => 'o-banknotes',
            'wallet' => 'o-wallet',
            'goal' => 'o-flag',
            'task' => 'o-check-circle',
            'project' => 'o-folder',
            'team' => 'o-users',
            'organization' => 'o-building-office',
            'website' => 'o-globe-alt',
            'app' => 'o-device-phone-mobile',
            'device' => 'o-computer-desktop',
            'integration' => 'o-puzzle-piece',
            'webhook' => 'o-bell',
            'notification' => 'o-bell-alert',
            'message' => 'o-envelope',
            'email' => 'o-envelope',
            'sms' => 'o-chat-bubble-left-right',
            'push' => 'o-device-phone-mobile',
            'alert' => 'o-exclamation-triangle',
            'warning' => 'o-exclamation-triangle',
            'error' => 'o-x-circle',
            'success' => 'o-check-circle',
            'info' => 'o-information-circle',
            'question' => 'o-question-mark-circle',
            'help' => 'o-question-mark-circle',
            'support' => 'o-heart',
            'feedback' => 'o-chat-bubble-oval-left-ellipsis',
            'review' => 'o-star',
            'rating' => 'o-star',
            'poll' => 'o-chart-bar',
            'survey' => 'o-clipboard-document-list',
            'form' => 'o-clipboard-document',
            'submission' => 'o-paper-airplane',
            'contact' => 'o-user',
            'lead' => 'o-user-plus',
            'customer' => 'o-user',
            'client' => 'o-briefcase',
            'partner' => 'o-user-group',
            'vendor' => 'o-truck',
            'supplier' => 'o-building-storefront',
            'service' => 'o-wrench-screwdriver',
            'tool' => 'o-wrench',
            'plugin' => 'o-puzzle-piece',
            'extension' => 'o-puzzle-piece',
            'addon' => 'o-plus-circle',
            'module' => 'o-squares-2x2',
            'component' => 'o-cube',
            'widget' => 'o-squares-2x2',
            'gadget' => 'o-cog',
            'feature' => 'o-sparkles',
            'function' => 'o-cog-6-tooth',
            'method' => 'o-cog-6-tooth',
            'api' => 'o-code-bracket',
            'endpoint' => 'o-code-bracket',
            'route' => 'o-map',
            'path' => 'o-map',
            'url' => 'o-link',
            'domain' => 'o-globe-alt',
            'subdomain' => 'o-globe-alt',
            'ip' => 'o-server',
            'server' => 'o-server',
            'database' => 'o-circle-stack',
            'table' => 'o-table-cells',
            'record' => 'o-document',
            'row' => 'o-table-cells',
            'column' => 'o-table-cells',
            'field' => 'o-rectangle-stack',
            'property' => 'o-tag',
            'attribute' => 'o-tag',
            'parameter' => 'o-tag',
            'variable' => 'o-tag',
            'constant' => 'o-tag',
            'function' => 'o-cog-6-tooth',
            'class' => 'o-cube',
            'object' => 'o-cube',
            'instance' => 'o-cube',
            'entity' => 'o-cube',
            'model' => 'o-cube',
            'view' => 'o-eye',
            'template' => 'o-document-text',
            'layout' => 'o-squares-2x2',
            'style' => 'o-paint-brush',
            'theme' => 'o-paint-brush',
            'color' => 'o-swatch',
            'font' => 'o-document-text',
            'icon' => 'o-photo',
            'logo' => 'o-photo',
            'banner' => 'o-photo',
            'background' => 'o-photo',
            'texture' => 'o-photo',
            'pattern' => 'o-photo',
            'gradient' => 'o-photo',
            'shadow' => 'o-photo',
            'border' => 'o-photo',
            'outline' => 'o-photo',
            'stroke' => 'o-photo',
            'fill' => 'o-photo',
            'opacity' => 'o-photo',
            'transparency' => 'o-photo',
            'blur' => 'o-photo',
            'sharpness' => 'o-photo',
            'resolution' => 'o-photo',
            'quality' => 'o-photo',
            'format' => 'o-tag',
            'encoding' => 'o-tag',
            'compression' => 'o-tag',
            'encryption' => 'o-lock-closed',
            'hash' => 'o-finger-print',
            'signature' => 'o-finger-print',
            'certificate' => 'o-academic-cap',
            'key' => 'o-key',
            'token' => 'o-key',
            'secret' => 'o-lock-closed',
            'password' => 'o-lock-closed',
            'auth' => 'o-shield-check',
            'authentication' => 'o-shield-check',
            'authorization' => 'o-shield-check',
            'permission' => 'o-shield-check',
            'role' => 'o-shield-check',
            'group' => 'o-user-group',
            'team' => 'o-users',
            'organization' => 'o-building-office',
            'company' => 'o-building-office',
            'business' => 'o-building-office',
            'enterprise' => 'o-building-office',
            'startup' => 'o-rocket-launch',
            'project' => 'o-folder',
            'campaign' => 'o-megaphone',
            'initiative' => 'o-flag',
            'strategy' => 'o-light-bulb',
            'plan' => 'o-clipboard-document-list',
            'roadmap' => 'o-map',
            'timeline' => 'o-clock',
            'schedule' => 'o-calendar',
            'deadline' => 'o-clock',
            'milestone' => 'o-flag',
            'checkpoint' => 'o-check-circle',
            'phase' => 'o-arrow-path',
            'stage' => 'o-arrow-path',
            'step' => 'o-arrow-path',
            'level' => 'o-arrow-path',
            'tier' => 'o-arrow-path',
            'category' => 'o-tag',
            'tag' => 'o-tag',
            'label' => 'o-tag',
            'keyword' => 'o-tag',
            'topic' => 'o-tag',
            'subject' => 'o-tag',
            'genre' => 'o-tag',
            'style' => 'o-paint-brush',
            'mood' => 'o-heart',
            'emotion' => 'o-heart',
            'feeling' => 'o-heart',
            'sentiment' => 'o-heart',
            'tone' => 'o-musical-note',
            'voice' => 'o-microphone',
            'personality' => 'o-user',
            'character' => 'o-user',
            'identity' => 'o-finger-print',
            'profile' => 'o-user',
            'avatar' => 'o-user',
            'picture' => 'o-photo',
            'photo' => 'o-photo',
            'image' => 'o-photo',
            'video' => 'o-video-camera',
            'audio' => 'o-musical-note',
            'sound' => 'o-musical-note',
            'music' => 'o-musical-note',
            'song' => 'o-musical-note',
            'track' => 'o-musical-note',
            'album' => 'o-musical-note',
            'playlist' => 'o-list-bullet',
            'podcast' => 'o-musical-note',
            'episode' => 'o-musical-note',
            'show' => 'o-tv',
            'series' => 'o-tv',
            'season' => 'o-tv',
            'chapter' => 'o-book-open',
            'book' => 'o-book-open',
            'novel' => 'o-book-open',
            'story' => 'o-book-open',
            'article' => 'o-newspaper',
            'blog' => 'o-newspaper',
            'post' => 'o-document-text',
            'page' => 'o-document-text',
            'document' => 'o-document-text',
            'file' => 'o-document',
            'folder' => 'o-folder',
            'directory' => 'o-folder',
            'archive' => 'o-archive-box',
            'backup' => 'o-archive-box',
            'snapshot' => 'o-camera',
            'version' => 'o-tag',
            'revision' => 'o-tag',
            'edit' => 'o-pencil',
            'change' => 'o-arrow-path',
            'update' => 'o-arrow-path',
            'modification' => 'o-pencil',
            'adjustment' => 'o-pencil',
            'correction' => 'o-pencil',
            'fix' => 'o-wrench',
            'repair' => 'o-wrench',
            'maintenance' => 'o-wrench',
            'improvement' => 'o-arrow-trending-up',
            'enhancement' => 'o-arrow-trending-up',
            'optimization' => 'o-arrow-trending-up',
            'performance' => 'o-chart-bar',
            'speed' => 'o-clock',
            'efficiency' => 'o-chart-bar',
            'productivity' => 'o-chart-bar',
            'quality' => 'o-star',
            'reliability' => 'o-shield-check',
            'stability' => 'o-shield-check',
            'security' => 'o-shield-check',
            'privacy' => 'o-lock-closed',
            'confidentiality' => 'o-lock-closed',
            'integrity' => 'o-shield-check',
            'authenticity' => 'o-shield-check',
            'validity' => 'o-shield-check',
            'accuracy' => 'o-check-circle',
            'precision' => 'o-check-circle',
            'exactness' => 'o-check-circle',
            'correctness' => 'o-check-circle',
            'truth' => 'o-check-circle',
            'fact' => 'o-check-circle',
            'reality' => 'o-check-circle',
            'existence' => 'o-check-circle',
            'presence' => 'o-check-circle',
            'availability' => 'o-check-circle',
            'accessibility' => 'o-check-circle',
            'usability' => 'o-check-circle',
            'functionality' => 'o-cog-6-tooth',
            'capability' => 'o-cog-6-tooth',
            'capacity' => 'o-cog-6-tooth',
            'potential' => 'o-cog-6-tooth',
            'ability' => 'o-cog-6-tooth',
            'skill' => 'o-cog-6-tooth',
            'talent' => 'o-cog-6-tooth',
            'expertise' => 'o-cog-6-tooth',
            'knowledge' => 'o-academic-cap',
            'wisdom' => 'o-academic-cap',
            'intelligence' => 'o-academic-cap',
            'understanding' => 'o-academic-cap',
            'comprehension' => 'o-academic-cap',
            'awareness' => 'o-eye',
            'consciousness' => 'o-eye',
            'recognition' => 'o-eye',
            'identification' => 'o-eye',
            'detection' => 'o-eye',
            'discovery' => 'o-magnifying-glass',
            'exploration' => 'o-magnifying-glass',
            'investigation' => 'o-magnifying-glass',
            'research' => 'o-magnifying-glass',
            'analysis' => 'o-chart-bar',
            'examination' => 'o-magnifying-glass',
            'inspection' => 'o-magnifying-glass',
            'review' => 'o-eye',
            'evaluation' => 'o-star',
            'assessment' => 'o-star',
            'judgment' => 'o-scale',
            'decision' => 'o-check-circle',
            'choice' => 'o-check-circle',
            'selection' => 'o-check-circle',
            'option' => 'o-check-circle',
            'alternative' => 'o-check-circle',
            'possibility' => 'o-check-circle',
            'opportunity' => 'o-check-circle',
            'chance' => 'o-check-circle',
            'probability' => 'o-chart-bar',
            'likelihood' => 'o-chart-bar',
            'certainty' => 'o-check-circle',
            'confidence' => 'o-check-circle',
            'trust' => 'o-shield-check',
            'belief' => 'o-heart',
            'faith' => 'o-heart',
            'hope' => 'o-heart',
            'dream' => 'o-heart',
            'vision' => 'o-eye',
            'goal' => 'o-flag',
            'target' => 'o-flag',
            'objective' => 'o-flag',
            'purpose' => 'o-flag',
            'intention' => 'o-flag',
            'motivation' => 'o-flag',
            'inspiration' => 'o-light-bulb',
            'creativity' => 'o-light-bulb',
            'imagination' => 'o-light-bulb',
            'innovation' => 'o-light-bulb',
            'invention' => 'o-light-bulb',
            'creation' => 'o-plus-circle',
            'production' => 'o-cog',
            'generation' => 'o-cog',
            'formation' => 'o-cog',
            'development' => 'o-cog',
            'growth' => 'o-arrow-trending-up',
            'expansion' => 'o-arrow-trending-up',
            'extension' => 'o-arrow-trending-up',
            'enlargement' => 'o-arrow-trending-up',
            'increase' => 'o-arrow-trending-up',
            'decrease' => 'o-arrow-trending-down',
            'reduction' => 'o-arrow-trending-down',
            'diminution' => 'o-arrow-trending-down',
            'contraction' => 'o-arrow-trending-down',
            'shrinkage' => 'o-arrow-trending-down',
            'compression' => 'o-arrow-trending-down',
            'condensation' => 'o-arrow-trending-down',
            'concentration' => 'o-arrow-trending-down',
            'focus' => 'o-arrow-trending-down',
            'attention' => 'o-eye',
            'awareness' => 'o-eye',
            'consciousness' => 'o-eye',
            'recognition' => 'o-eye',
            'understanding' => 'o-academic-cap',
            'comprehension' => 'o-academic-cap',
            'knowledge' => 'o-academic-cap',
            'wisdom' => 'o-academic-cap',
            'intelligence' => 'o-academic-cap',
            'expertise' => 'o-academic-cap',
            'skill' => 'o-cog-6-tooth',
            'talent' => 'o-cog-6-tooth',
            'ability' => 'o-cog-6-tooth',
            'capability' => 'o-cog-6-tooth',
            'capacity' => 'o-cog-6-tooth',
            'potential' => 'o-cog-6-tooth',
            'functionality' => 'o-cog-6-tooth',
            'usability' => 'o-cog-6-tooth',
            'accessibility' => 'o-cog-6-tooth',
            'availability' => 'o-cog-6-tooth',
            'presence' => 'o-cog-6-tooth',
            'existence' => 'o-cog-6-tooth',
            'reality' => 'o-cog-6-tooth',
            'truth' => 'o-cog-6-tooth',
            'fact' => 'o-cog-6-tooth',
            'accuracy' => 'o-cog-6-tooth',
            'precision' => 'o-cog-6-tooth',
            'exactness' => 'o-cog-6-tooth',
            'correctness' => 'o-cog-6-tooth',
            'validity' => 'o-cog-6-tooth',
            'authenticity' => 'o-cog-6-tooth',
            'integrity' => 'o-cog-6-tooth',
            'security' => 'o-cog-6-tooth',
            'privacy' => 'o-cog-6-tooth',
            'confidentiality' => 'o-cog-6-tooth',
            'stability' => 'o-cog-6-tooth',
            'reliability' => 'o-cog-6-tooth',
            'quality' => 'o-cog-6-tooth',
            'performance' => 'o-cog-6-tooth',
            'efficiency' => 'o-cog-6-tooth',
            'productivity' => 'o-cog-6-tooth',
            'speed' => 'o-cog-6-tooth',
            'optimization' => 'o-cog-6-tooth',
            'enhancement' => 'o-cog-6-tooth',
            'improvement' => 'o-cog-6-tooth',
            'maintenance' => 'o-cog-6-tooth',
            'repair' => 'o-cog-6-tooth',
            'fix' => 'o-cog-6-tooth',
            'correction' => 'o-cog-6-tooth',
            'adjustment' => 'o-cog-6-tooth',
            'modification' => 'o-cog-6-tooth',
            'change' => 'o-cog-6-tooth',
            'update' => 'o-cog-6-tooth',
            'revision' => 'o-cog-6-tooth',
            'version' => 'o-cog-6-tooth',
            'edit' => 'o-cog-6-tooth',
            'snapshot' => 'o-cog-6-tooth',
            'backup' => 'o-cog-6-tooth',
            'archive' => 'o-cog-6-tooth',
            'directory' => 'o-cog-6-tooth',
            'folder' => 'o-cog-6-tooth',
            'file' => 'o-cog-6-tooth',
            'document' => 'o-cog-6-tooth',
            'page' => 'o-cog-6-tooth',
            'post' => 'o-cog-6-tooth',
            'blog' => 'o-cog-6-tooth',
            'article' => 'o-cog-6-tooth',
            'story' => 'o-cog-6-tooth',
            'novel' => 'o-cog-6-tooth',
            'book' => 'o-cog-6-tooth',
            'chapter' => 'o-cog-6-tooth',
            'series' => 'o-cog-6-tooth',
            'show' => 'o-cog-6-tooth',
            'episode' => 'o-cog-6-tooth',
            'season' => 'o-cog-6-tooth',
            'podcast' => 'o-cog-6-tooth',
            'track' => 'o-cog-6-tooth',
            'song' => 'o-cog-6-tooth',
            'music' => 'o-cog-6-tooth',
            'audio' => 'o-cog-6-tooth',
            'sound' => 'o-cog-6-tooth',
            'video' => 'o-cog-6-tooth',
            'image' => 'o-cog-6-tooth',
            'photo' => 'o-cog-6-tooth',
            'picture' => 'o-cog-6-tooth',
            'avatar' => 'o-cog-6-tooth',
            'profile' => 'o-cog-6-tooth',
            'identity' => 'o-cog-6-tooth',
            'character' => 'o-cog-6-tooth',
            'personality' => 'o-cog-6-tooth',
            'voice' => 'o-cog-6-tooth',
            'tone' => 'o-cog-6-tooth',
            'sentiment' => 'o-cog-6-tooth',
            'feeling' => 'o-cog-6-tooth',
            'emotion' => 'o-cog-6-tooth',
            'mood' => 'o-cog-6-tooth',
            'style' => 'o-cog-6-tooth',
            'genre' => 'o-cog-6-tooth',
            'subject' => 'o-cog-6-tooth',
            'topic' => 'o-cog-6-tooth',
            'keyword' => 'o-cog-6-tooth',
            'label' => 'o-cog-6-tooth',
            'tag' => 'o-cog-6-tooth',
            'category' => 'o-cog-6-tooth',
            'tier' => 'o-cog-6-tooth',
            'level' => 'o-cog-6-tooth',
            'stage' => 'o-cog-6-tooth',
            'phase' => 'o-cog-6-tooth',
            'step' => 'o-cog-6-tooth',
            'checkpoint' => 'o-cog-6-tooth',
            'milestone' => 'o-cog-6-tooth',
            'deadline' => 'o-cog-6-tooth',
            'schedule' => 'o-cog-6-tooth',
            'timeline' => 'o-cog-6-tooth',
            'roadmap' => 'o-cog-6-tooth',
            'plan' => 'o-cog-6-tooth',
            'strategy' => 'o-cog-6-tooth',
            'initiative' => 'o-cog-6-tooth',
            'campaign' => 'o-cog-6-tooth',
            'project' => 'o-cog-6-tooth',
            'startup' => 'o-cog-6-tooth',
            'enterprise' => 'o-cog-6-tooth',
            'business' => 'o-cog-6-tooth',
            'company' => 'o-cog-6-tooth',
            'organization' => 'o-cog-6-tooth',
            'team' => 'o-cog-6-tooth',
            'group' => 'o-cog-6-tooth',
            'role' => 'o-cog-6-tooth',
            'permission' => 'o-cog-6-tooth',
            'authorization' => 'o-cog-6-tooth',
            'authentication' => 'o-cog-6-tooth',
            'auth' => 'o-cog-6-tooth',
            'secret' => 'o-cog-6-tooth',
            'token' => 'o-cog-6-tooth',
            'key' => 'o-cog-6-tooth',
            'certificate' => 'o-cog-6-tooth',
            'signature' => 'o-cog-6-tooth',
            'hash' => 'o-cog-6-tooth',
            'encryption' => 'o-cog-6-tooth',
            'compression' => 'o-cog-6-tooth',
            'encoding' => 'o-cog-6-tooth',
            'format' => 'o-cog-6-tooth',
            'quality' => 'o-cog-6-tooth',
            'resolution' => 'o-cog-6-tooth',
            'sharpness' => 'o-cog-6-tooth',
            'blur' => 'o-cog-6-tooth',
            'transparency' => 'o-cog-6-tooth',
            'opacity' => 'o-cog-6-tooth',
            'fill' => 'o-cog-6-tooth',
            'stroke' => 'o-cog-6-tooth',
            'outline' => 'o-cog-6-tooth',
            'border' => 'o-cog-6-tooth',
            'pattern' => 'o-cog-6-tooth',
            'texture' => 'o-cog-6-tooth',
            'background' => 'o-cog-6-tooth',
            'banner' => 'o-cog-6-tooth',
            'logo' => 'o-cog-6-tooth',
            'icon' => 'o-cog-6-tooth',
            'font' => 'o-cog-6-tooth',
            'color' => 'o-cog-6-tooth',
            'theme' => 'o-cog-6-tooth',
            'style' => 'o-cog-6-tooth',
            'layout' => 'o-cog-6-tooth',
            'template' => 'o-cog-6-tooth',
            'view' => 'o-cog-6-tooth',
            'instance' => 'o-cog-6-tooth',
            'object' => 'o-cog-6-tooth',
            'class' => 'o-cog-6-tooth',
            'function' => 'o-cog-6-tooth',
            'constant' => 'o-cog-6-tooth',
            'variable' => 'o-cog-6-tooth',
            'parameter' => 'o-cog-6-tooth',
            'attribute' => 'o-cog-6-tooth',
            'property' => 'o-cog-6-tooth',
            'column' => 'o-cog-6-tooth',
            'row' => 'o-cog-6-tooth',
            'record' => 'o-cog-6-tooth',
            'table' => 'o-cog-6-tooth',
            'database' => 'o-cog-6-tooth',
            'server' => 'o-cog-6-tooth',
            'ip' => 'o-cog-6-tooth',
            'domain' => 'o-cog-6-tooth',
            'subdomain' => 'o-cog-6-tooth',
            'url' => 'o-cog-6-tooth',
            'path' => 'o-cog-6-tooth',
            'route' => 'o-cog-6-tooth',
            'endpoint' => 'o-cog-6-tooth',
            'api' => 'o-cog-6-tooth',
            'method' => 'o-cog-6-tooth',
            'function' => 'o-cog-6-tooth',
            'addon' => 'o-cog-6-tooth',
            'extension' => 'o-cog-6-tooth',
            'plugin' => 'o-cog-6-tooth',
            'module' => 'o-cog-6-tooth',
            'component' => 'o-cog-6-tooth',
            'widget' => 'o-cog-6-tooth',
            'gadget' => 'o-cog-6-tooth',
            'tool' => 'o-cog-6-tooth',
            'service' => 'o-cog-6-tooth',
            'supplier' => 'o-cog-6-tooth',
            'vendor' => 'o-cog-6-tooth',
            'partner' => 'o-cog-6-tooth',
            'client' => 'o-cog-6-tooth',
            'customer' => 'o-cog-6-tooth',
            'lead' => 'o-cog-6-tooth',
            'contact' => 'o-cog-6-tooth',
            'submission' => 'o-cog-6-tooth',
            'form' => 'o-cog-6-tooth',
            'survey' => 'o-cog-6-tooth',
            'poll' => 'o-cog-6-tooth',
            'rating' => 'o-cog-6-tooth',
            'review' => 'o-cog-6-tooth',
            'feedback' => 'o-cog-6-tooth',
            'support' => 'o-cog-6-tooth',
            'help' => 'o-cog-6-tooth',
            'question' => 'o-cog-6-tooth',
            'info' => 'o-cog-6-tooth',
            'success' => 'o-cog-6-tooth',
            'error' => 'o-cog-6-tooth',
            'warning' => 'o-cog-6-tooth',
            'alert' => 'o-cog-6-tooth',
            'notification' => 'o-cog-6-tooth',
            'webhook' => 'o-cog-6-tooth',
            'integration' => 'o-cog-6-tooth',
            'device' => 'o-cog-6-tooth',
            'app' => 'o-cog-6-tooth',
            'website' => 'o-cog-6-tooth',
            'team' => 'o-cog-6-tooth',
            'goal' => 'o-cog-6-tooth',
            'wallet' => 'o-cog-6-tooth',
            'account' => 'o-cog-6-tooth',
            'transaction' => 'o-cog-6-tooth',
            'payment' => 'o-cog-6-tooth',
            'order' => 'o-cog-6-tooth',
            'product' => 'o-cog-6-tooth',
            'page' => 'o-cog-6-tooth',
            'group' => 'o-cog-6-tooth',
            'event' => 'o-cog-6-tooth',
            'location' => 'o-cog-6-tooth',
            'link' => 'o-cog-6-tooth',
            'audio' => 'o-cog-6-tooth',
            'video' => 'o-cog-6-tooth',
            'image' => 'o-cog-6-tooth',
            'photo' => 'o-cog-6-tooth',
            'picture' => 'o-cog-6-tooth',
            'file' => 'o-cog-6-tooth',
            'document' => 'o-cog-6-tooth',
            'post' => 'o-cog-6-tooth',
            'comment' => 'o-cog-6-tooth',
            'like' => 'o-cog-6-tooth',
            'share' => 'o-cog-6-tooth',
            'user' => 'o-cog-6-tooth',
        ];

        // Try to match by type first, then concept
        if (isset($icons[strtolower($type)])) {
            return $icons[strtolower($type)];
        }

        if (isset($icons[strtolower($concept)])) {
            return $icons[strtolower($concept)];
        }

        return 'o-cube'; // Default icon
    }

    public function addTag(string $value): void
    {
        $name = trim((string) $value);
        if ($name === '') {
            return;
        }

        if (str_starts_with($name, 'tag-whitelist-') || str_starts_with($name, 'tag-initial-')) {
            return;
        }

        $tag = Tag::findOrCreate($name);
        $this->object->attachTag($tag);
        $this->object->refresh()->loadMissing('tags');
    }

    public function removeTag(string $value): void
    {
        $name = trim((string) $value);
        if ($name === '') {
            return;
        }

        if (str_starts_with($name, 'tag-whitelist-') || str_starts_with($name, 'tag-initial-')) {
            return;
        }

        $this->object->detachTag($name);
        $this->object->refresh()->loadMissing('tags');
    }
};

?>

<div>
    @if ($this->object)
        <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
            <!-- Main Content Area -->
            <div class="flex-1 space-y-4 lg:space-y-6">
                <!-- Header -->
                <x-header title="Object Details" separator>
                    <x-slot:actions>
                        <x-button
                            wire:click="toggleSidebar"
                            class="btn-ghost btn-sm"
                            title="{{ $this->showSidebar ? 'Hide details' : 'Show details' }}"
                            aria-label="{{ $this->showSidebar ? 'Hide details' : 'Show details' }}"
                        >
                            <x-icon name="{{ $this->showSidebar ? 'o-x-mark' : 'o-adjustments-horizontal' }}" class="w-4 h-4" />
                        </x-button>
                    </x-slot:actions>
                </x-header>

                            <!-- Object Overview Card -->
                <x-card>
                    <div class="flex flex-col sm:flex-row items-start gap-4">
                        <!-- Object Icon -->
                        <div class="flex-shrink-0 self-center sm:self-start">
                            <div class="w-12 h-12 rounded-full bg-secondary/10 flex items-center justify-center">
                                <x-icon name="{{ $this->getObjectIcon($this->object->type, $this->object->concept) }}"
                                       class="w-6 h-6 text-secondary" />
                            </div>
                        </div>

                    <!-- Object Info -->
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-3">
                            <h2 class="text-xl font-semibold text-base-content">
                                {{ $this->object->title }}
                            </h2>
                            @if ($this->object->type)
                                <x-badge :value="$this->object->type" class="badge-secondary" />
                            @endif
                            @if ($this->object->concept)
                                <x-badge :value="$this->object->concept" class="badge-accent" />
                            @endif
                        </div>

                        @php $text = is_array($this->object->metadata ?? null) ? ($this->object->metadata['text'] ?? null) : null; @endphp
                        @if ($text)
                            <p class="text-base-content/70 mb-4">{{ $text }}</p>
                        @endif

                        <!-- Object Metadata -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 lg:gap-4 text-sm">
                            @if ($this->object->time)
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-clock" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70">Time:</span>
                                    <span class="font-medium">{{ $this->object->time->format('F j, Y g:i A') }}</span>
                                </div>
                            @endif
                            @if ($this->object->url)
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-link" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70">URL:</span>
                                    <a href="{{ $this->object->url }}" target="_blank" class="font-medium text-primary hover:underline">
                                        View
                                    </a>
                                </div>
                            @endif
                            @if ($this->object->media_url)
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-photo" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70">Media:</span>
                                    <a href="{{ $this->object->media_url }}" target="_blank" class="font-medium text-primary hover:underline">
                                        View
                                    </a>
                                </div>
                            @endif
                        </div>

                        <!-- Object Tags -->
                        @if ($this->object->tags->isNotEmpty())
                            <div class="mt-4">
                                <h4 class="text-sm font-medium text-base-content/70 mb-2">Object Tags</h4>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($this->object->tags as $tag)
                                        <x-badge :value="$tag->name" class="badge-secondary" />
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Related Events -->
                                    @if ($this->getRelatedEvents()->isNotEmpty())
                <x-card>
                                            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-bolt" class="w-5 h-5 text-primary" />
                            Related Events ({{ $this->getRelatedEvents()->count() }})
                        </h3>
                    <div class="space-y-3">
                        @foreach ($this->getRelatedEvents() as $event)
                            <div class="border border-base-300 rounded-lg p-3 hover:bg-base-50 transition-colors">
                                <a href="{{ route('events.show', $event->id) }}"
                                   class="block hover:text-primary transition-colors">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                            <x-icon name="o-bolt" class="w-4 h-4 text-primary" />
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="mb-1">
                                                <span class="font-medium">
                                                    {{ $this->formatAction($event->action) }}
                                                    @if ($event->target)
                                                        to {{ $event->target->title }}
                                                    @elseif ($event->actor)
                                                        {{ $event->actor->title }}
                                                    @endif
                                                    @if ($event->value)
                                                        <span class="text-primary">
                                                            ({{ $event->formatted_value }}{{ $event->value_unit ? ' ' . $event->value_unit : '' }})
                                                        </span>
                                                    @endif
                                                </span>
                                            </div>
                                            <div class="text-sm text-base-content/70">
                                                {{ $event->time->format('M j, Y g:i A') }}
                                            </div>
                                        </div>
                                        <x-icon name="o-chevron-right" class="w-4 h-4 text-base-content/40" />
                                    </div>
                                </a>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif

            <!-- Drawer for Technical Details -->
            <x-drawer wire:model="showSidebar" right title="Object Details" with-close-button separator class="w-11/12 lg:w-1/3">
                <div class="space-y-4 lg:space-y-6">
                    <!-- Activity Timeline -->
                    <x-collapse wire:model="activityOpen">
                        <x-slot:heading>
                            <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                                <x-icon name="o-clock" class="w-5 h-5 text-primary" />
                                Activity
                            </div>
                        </x-slot:heading>
                        <x-slot:content>
                        @php $activities = $this->getActivities(); @endphp
                        @if ($activities->isEmpty())
                            <div class="text-sm text-base-content/70">No activity yet.</div>
                        @else
                            @php
                                $activities = $this->getActivities();
                                $timeline = collect();
                                if ($this->object?->created_at) {
                                    $timeline->push((object) [
                                        '__synthetic' => true,
                                        'event' => 'created',
                                        'created_at' => $this->object->created_at,
                                        'properties' => [],
                                        'description' => '',
                                    ]);
                                }
                                foreach ($activities as $a) { $timeline->push($a); }
                                $timeline = $timeline->sortByDesc(fn($a) => $a->created_at)->values();
                            @endphp
                            @foreach ($timeline as $activity)
                                @php
                                    $modelLabel = 'Object';
                                    $event = strtolower((string) ($activity->event ?? ($activity->description ?? '')));
                                    $title = in_array($event, ['created','updated','deleted','restored'])
                                        ? $modelLabel . ' ' . ucfirst($event)
                                        : ($event === 'comment' ? 'Comment' : ucfirst($event));
                                    $subtitle = $activity->created_at?->format('M j, Y g:i A');
                                    $props = is_array($activity->properties ?? null) ? $activity->properties : (object) ($activity->properties ?? []);
                                    $changes = [];
                                    $new = $props['attributes'] ?? [];
                                    $old = $props['old'] ?? [];
                                    foreach ($new as $k => $v) {
                                        if ($k === 'updated_at') { continue; }
                                        $before = $old[$k] ?? null;
                                        $after = $v;
                                        $changes[] = $k . ': ' . (is_scalar($before) ? (string) $before : json_encode($before)) . ' → ' . (is_scalar($after) ? (string) $after : json_encode($after));
                                    }
                                    if (($props['comment'] ?? null) !== null) {
                                        $desc = (string) $props['comment'];
                                    } elseif (!empty($changes)) {
                                        $desc = implode(', ', $changes);
                                    } else {
                                        $desc = '';
                                    }
                                @endphp
                                <x-timeline-item title="{{ $title }}" subtitle="{{ $subtitle }}" description="{{ $desc }}" />
                            @endforeach
                        @endif
                        </x-slot:content>
                    </x-collapse>

                    <!-- Add Comment -->
                    <x-card>
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-chat-bubble-left" class="w-5 h-5 text-primary" />
                            Comment
                        </h3>
                        <x-form wire:submit="addComment">
                            <x-textarea wire:model="comment" rows="3" placeholder="Add a comment..." />
                            <div class="mt-3 flex justify-end">
                                <x-button type="submit" class="btn-primary btn-sm" label="Post" />
                            </div>
                        </x-form>
                    </x-card>

                    <!-- Tags Manager -->
                    <x-card>
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-tag" class="w-5 h-5 text-primary" />
                            Tags
                        </h3>
                        <div class="space-y-2" wire:key="object-tags-{{ $this->object->id }}" wire:ignore>
                            <input id="tag-input-{{ $this->object->id }}" data-tagify data-initial="tag-initial-{{ $this->object->id }}" data-suggestions-id="tag-suggestions-{{ $this->object->id }}" aria-label="Tags" class="input input-sm w-full" placeholder="Add tags" />
                            <script type="application/json" id="tag-suggestions-{{ $this->object->id }}">{!! json_encode(\Spatie\Tags\Tag::query()->pluck('name')->map(fn($n)=>(string)$n)->unique()->values()->all()) !!}</script>
                            <script type="application/json" id="tag-initial-{{ $this->object->id }}">{!! json_encode($this->object->tags->pluck('name')->values()->all()) !!}</script>
                        </div>
                    </x-card>
                    @if ($this->object->metadata && count($this->object->metadata) > 0)
                        <x-collapse wire:model="objectMetaOpen">
                            <x-slot:heading>
                                <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                                    <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-warning" />
                                    Object Metadata
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="bg-base-200 rounded-lg p-3">
                                    <pre class="text-xs text-base-content/80 whitespace-pre-wrap overflow-x-auto">{{ $this->formatJson($this->object->metadata) }}</pre>
                                </div>
                            </x-slot:content>
                        </x-collapse>
                    @endif
                </div>
            </x-drawer>
        </div>
    @else
        <div class="text-center py-8">
            <x-icon name="o-exclamation-triangle" class="w-12 h-12 text-warning mx-auto mb-4" />
            <h3 class="text-lg font-semibold text-base-content mb-2">Object Not Found</h3>
            <p class="text-base-content/70">The requested object could not be found.</p>
        </div>
    @endif
    </div>
</div>
