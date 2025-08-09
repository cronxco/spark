<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\EventObject;
use App\Models\Event;
use App\Models\Block;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class EventApiController extends Controller
{
    /**
     * List events for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        $query = Event::with(['actor', 'target', 'blocks', 'integration', 'tags'])
            ->whereHas('integration', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            });

        // Apply filters
        if ($request->has('integration_id')) {
            $query->where('integration_id', $request->integration_id);
        }

        if ($request->has('service')) {
            $query->where('service', $request->service);
        }

        if ($request->has('domain')) {
            $query->where('domain', $request->domain);
        }

        if ($request->has('action')) {
            $query->where('action', $request->action);
        }

        // Apply date filters
        if ($request->has('from_date')) {
            $query->where('time', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('time', '<=', $request->to_date);
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $events = $query->orderBy('time', 'desc')->paginate($perPage);

        return response()->json($events);
    }

    /**
     * Get a specific event.
     */
    public function show(string $id): JsonResponse
    {
        $user = Auth::user();
        
        $event = Event::with(['actor', 'target', 'blocks', 'integration', 'tags'])
            ->whereHas('integration', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->findOrFail($id);

        return response()->json($event);
    }

    /**
     * Create a new event.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'actor' => 'required|array',
            'target' => 'required|array',
            'event' => 'required|array',
            'blocks' => 'array',
            'blocks.*' => 'array',
        ]);

        $result = DB::transaction(function () use ($validated) {
            // Create actor object
            $actor = EventObject::create($validated['actor']);
            // Create target object
            $target = EventObject::create($validated['target']);
            // Create event, linking actor and target
            $eventData = array_merge($validated['event'], [
                'actor_id' => $actor->id,
                'target_id' => $target->id,
            ]);
            $event = Event::create($eventData);
            // Create blocks if provided
            $blocks = [];
            if (!empty($validated['blocks'])) {
                foreach ($validated['blocks'] as $blockData) {
                    $block = Block::create(array_merge($blockData, [
                        'event_id' => $event->id,
                        'integration_id' => $event->integration_id,
                    ]));
                    $blocks[] = $block;
                }
            }
            return [
                'event' => $event,
                'actor' => $actor,
                'target' => $target,
                'blocks' => $blocks,
            ];
        });

        return response()->json($result, 201);
    }

    /**
     * Update an event.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = Auth::user();
        
        $event = Event::whereHas('integration', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->findOrFail($id);

        $validated = $request->validate([
            'source_id' => 'sometimes|string',
            'time' => 'sometimes|date',
            'service' => 'sometimes|string',
            'domain' => 'sometimes|string',
            'action' => 'sometimes|string',
            'value' => 'sometimes|integer',
            'value_multiplier' => 'sometimes|integer',
            'value_unit' => 'sometimes|string',
            'event_metadata' => 'sometimes|array',
            'embeddings' => 'sometimes|string',
        ]);

        $event->update($validated);

        return response()->json($event->load(['actor', 'target', 'blocks', 'integration', 'tags']));
    }

    /**
     * Delete an event.
     */
    public function destroy(string $id): JsonResponse
    {
        $user = Auth::user();
        
        $event = Event::whereHas('integration', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->findOrFail($id);

        DB::transaction(function () use ($event) {
            // Soft delete associated blocks
            $event->blocks()->delete();
            
            // Soft delete the event
            $event->delete();
            
            // Note: We don't delete actor/target objects as they might be used by other events
        });

        return response()->json(['message' => 'Event deleted successfully']);
    }
} 