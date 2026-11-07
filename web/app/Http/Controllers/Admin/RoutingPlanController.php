<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoutingMode;
use App\Http\Controllers\Controller;
use App\Models\RoutingPlan;
use App\Models\RoutingPlanRoute;
use App\Models\Trunk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoutingPlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('routing-plans/Index', [
            'plans' => RoutingPlan::query()->withCount(['routes', 'accounts'])->orderBy('name')
                ->get(['id', 'name', 'mode', 'max_attempts', 'description']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('routing-plans/Form', ['plan' => null, 'modes' => RoutingMode::values()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plan = RoutingPlan::create($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Routing plan created. Add routes next.']);

        return to_route('routing-plans.show', $plan);
    }

    public function show(Request $request, RoutingPlan $routingPlan): Response
    {
        $q = preg_replace('/\D/', '', (string) $request->query('q', ''));

        return Inertia::render('routing-plans/Show', [
            'plan' => [...$routingPlan->only(['id', 'name', 'max_attempts', 'description', 'quality_routing', 'quality_min_asr', 'quality_max_pdd_ms', 'quality_min_attempts']), 'mode' => $routingPlan->mode->value],
            'routes' => $routingPlan->routes()
                ->with('trunk:id,name,account_id,enabled', 'trunk.account:id,code')
                ->when($q !== '', fn ($query) => $query->where('prefix', 'like', $q.'%'))
                ->orderBy('prefix')->orderBy('priority')
                ->paginate(100)->withQueryString()
                ->through(fn (RoutingPlanRoute $r) => [
                    ...$r->only(['id', 'prefix', 'priority', 'weight', 'enabled']),
                    'trunk' => ['id' => $r->trunk->id, 'name' => $r->trunk->name, 'enabled' => $r->trunk->enabled, 'vendor' => $r->trunk->account->code],
                ]),
            'filters' => ['q' => $q],
            'trunks' => Trunk::query()->with('account:id,code')->orderBy('name')->get(['id', 'name', 'account_id'])
                ->map(fn (Trunk $t) => ['id' => $t->id, 'label' => "{$t->account->code} / {$t->name}"])
                ->sortBy('label')->values(),
            'customers' => $routingPlan->accounts()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function edit(RoutingPlan $routingPlan): Response
    {
        return Inertia::render('routing-plans/Form', [
            'plan' => [...$routingPlan->only(['id', 'name', 'max_attempts', 'description', 'quality_routing', 'quality_min_asr', 'quality_max_pdd_ms', 'quality_min_attempts']), 'mode' => $routingPlan->mode->value],
            'modes' => RoutingMode::values(),
        ]);
    }

    public function update(Request $request, RoutingPlan $routingPlan): RedirectResponse
    {
        $routingPlan->update($this->validated($request, $routingPlan));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Routing plan updated.']);

        return to_route('routing-plans.show', $routingPlan);
    }

    public function storeRoute(Request $request, RoutingPlan $routingPlan): RedirectResponse
    {
        $request->merge(['prefix' => preg_replace('/[\s+\-]/', '', (string) $request->input('prefix'))]);
        $data = $request->validate([
            'prefix' => ['nullable', 'string', 'max:32', 'regex:/^[0-9]*$/'],
            'trunk_id' => ['required', 'integer', 'exists:trunks,id'],
            'priority' => ['required', 'integer', 'between:1,100'],
            'weight' => ['required', 'integer', 'between:1,1000'],
        ]);
        $data['prefix'] ??= '';

        $exists = $routingPlan->routes()->where('prefix', $data['prefix'])->where('trunk_id', $data['trunk_id'])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['trunk_id' => 'That trunk is already routed for this prefix.']);
        }
        $routingPlan->routes()->create($data);

        return to_route('routing-plans.show', $routingPlan);
    }

    public function updateRoute(Request $request, RoutingPlan $routingPlan, RoutingPlanRoute $route): RedirectResponse
    {
        $route->update($request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'between:1,100'],
            'weight' => ['sometimes', 'integer', 'between:1,1000'],
        ]));

        return back();
    }

    public function destroyRoute(RoutingPlan $routingPlan, RoutingPlanRoute $route): RedirectResponse
    {
        $route->delete();

        return back();
    }

    /** Also used by the REST API, so both validate identically. */
    public function validated(Request $request, ?RoutingPlan $plan = null): array
    {
        $request->merge(['quality_routing' => $request->boolean('quality_routing')]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('routing_plans', 'name')->ignore($plan)],
            'mode' => ['required', Rule::enum(RoutingMode::class)],
            'max_attempts' => ['required', 'integer', 'between:1,10'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quality_routing' => ['boolean'],
            'quality_min_asr' => ['sometimes', 'numeric', 'between:0,100'],
            'quality_max_pdd_ms' => ['sometimes', 'integer', 'between:500,120000'],
            'quality_min_attempts' => ['sometimes', 'integer', 'between:1,100000'],
        ]);
    }
}
