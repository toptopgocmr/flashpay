<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\ServicePoint;
use App\Services\Locations\LocatorService;
use Illuminate\Http\Request;

/**
 * « Localiser un agent » (carte de l'app, accessible aussi sans être connecté) :
 * agents, agences, points de vente et GAB autour du client.
 */
class LocationsController extends Controller
{
    public function __construct(protected LocatorService $locator)
    {
    }

    /** GET /api/locations?lat=&lng=&types=agent,atm&q=&radius=&country= */
    public function index(Request $request)
    {
        $v = $request->validate([
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180',
            'types' => 'nullable|string|max:80', 'q' => 'nullable|string|max:80',
            'radius' => 'nullable|numeric|min:1|max:500', 'country' => 'nullable|string|size:2', 'limit' => 'nullable|integer|min:1|max:300',
        ]);
        $types = array_filter(array_map('trim', explode(',', (string) ($v['types'] ?? ''))));
        $items = $this->locator->nearby(
            isset($v['lat']) ? (float) $v['lat'] : null, isset($v['lng']) ? (float) $v['lng'] : null,
            $types, $v['q'] ?? null, isset($v['country']) ? strtoupper($v['country']) : null,
            (float) ($v['radius'] ?? 50), (int) ($v['limit'] ?? 100),
        );

        return response()->json([
            'types' => LocatorService::TYPES,
            'count' => count($items),
            'locations' => $items,
        ]);
    }

    /** POST /api/agent/location : l'agent enregistre la position de son point de service. */
    public function agentLocation(Request $request)
    {
        $v = $request->validate([
            'lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:255', 'opening_hours' => 'nullable|string|max:120', 'show_on_map' => 'nullable|boolean',
        ]);
        $agent = Agent::where('user_id', $request->user()->id)->firstOrFail();
        $agent->forceFill(array_filter([
            'latitude' => $v['lat'], 'longitude' => $v['lng'], 'address' => $v['address'] ?? null,
            'opening_hours' => $v['opening_hours'] ?? null, 'show_on_map' => $v['show_on_map'] ?? null,
        ], fn ($x) => $x !== null))->save();

        return response()->json(['message' => 'Position enregistrée : vous apparaissez sur la carte des clients.', 'agent' => $agent->only(['latitude', 'longitude', 'address', 'opening_hours', 'show_on_map'])]);
    }

    // ------------------------------------------------------------ Console

    public function adminIndex(Request $request)
    {
        return response()->json([
            'types' => ServicePoint::TYPES,
            'points' => ServicePoint::orderBy('type')->orderBy('city')->orderBy('name')->get(),
            'agents' => Agent::with('user:id,full_name,phone')->where('validation_status', 'approved')->orderBy('city')->get()
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->user?->full_name, 'code' => $a->agent_code,
                    'city' => $a->city, 'latitude' => $a->latitude, 'longitude' => $a->longitude, 'address' => $a->address,
                    'opening_hours' => $a->opening_hours, 'show_on_map' => (bool) $a->show_on_map]),
        ]);
    }

    public function adminSave(Request $request, ?ServicePoint $point = null)
    {
        $v = $request->validate([
            'type' => 'required|in:' . implode(',', array_keys(ServicePoint::TYPES)),
            'name' => 'required|string|max:120', 'operator' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:255', 'city' => 'nullable|string|max:80', 'country' => 'nullable|string|size:2',
            'latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180',
            'opening_hours' => 'nullable|string|max:120', 'phone' => 'nullable|string|max:30',
            'services' => 'nullable|array', 'services.*' => 'string|max:30', 'active' => 'nullable|boolean',
        ]);
        $v['country'] = strtoupper($v['country'] ?? 'CG');
        $point = $point ?? new ServicePoint();
        $point->fill($v)->save();

        return response()->json(['message' => 'Point enregistré.', 'point' => $point->fresh()], $point->wasRecentlyCreated ? 201 : 200);
    }

    public function adminDelete(ServicePoint $point)
    {
        $point->delete();

        return response()->json(['deleted' => true]);
    }

    public function adminAgentLocation(Request $request, Agent $agent)
    {
        $v = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'address' => 'nullable|string|max:255', 'opening_hours' => 'nullable|string|max:120', 'show_on_map' => 'nullable|boolean',
        ]);
        $agent->forceFill($v)->save();

        return response()->json(['message' => 'Position de l\'agent enregistrée.']);
    }
}
