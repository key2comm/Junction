<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\GatewayController;
use App\Http\Controllers\Admin\RoutingPlanController;
use App\Http\Controllers\Admin\TrunkController;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Gateway;
use App\Models\RoutingPlan;
use App\Models\RoutingPlanRoute;
use App\Models\Trunk;
use App\Services\Router;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Trunks, gateways, routing plans, routes and the route tester. Gateway and
 * firewall changes reach the switch automatically (queued sync), as in the UI.
 */
class RoutingController extends Controller
{
    use Paginates;

    // --- trunks ---

    public function trunks(Request $request): JsonResponse
    {
        $request->validate(['vendor_id' => ['nullable', 'integer']]);

        return $this->paged(Trunk::query()->with('gateways')->when($request->integer('vendor_id'), fn ($q, $v) => $q->where('account_id', $v))
            ->orderBy('id')->paginate($this->perPage($request)), fn ($t) => Present::trunk($t, true));
    }

    public function showTrunk(Trunk $trunk): JsonResponse
    {
        return response()->json(['data' => Present::trunk($trunk->load('gateways'), true)]);
    }

    public function storeTrunk(Request $request, TrunkController $admin): JsonResponse
    {
        $request->replace(['enabled' => true, ...$request->all(), 'account_id' => $request->input('vendor_id', $request->input('account_id'))]);
        $trunk = Trunk::create($admin->validated($request));

        return response()->json(['data' => Present::trunk($trunk->fresh()->load('gateways'), true)], 201);
    }

    public function updateTrunk(Request $request, Trunk $trunk, TrunkController $admin): JsonResponse
    {
        $request->replace([...$trunk->only(['account_id', 'name', 'rate_deck_id', 'enabled', 'max_channels', 'max_cps', 'tech_prefix', 'strip_digits']), ...$request->except('vendor_id')]);
        $trunk->update($admin->validated($request, $trunk));

        return response()->json(['data' => Present::trunk($trunk->fresh()->load('gateways'), true)]);
    }

    // --- gateways ---

    public function storeGateway(Request $request, Trunk $trunk, GatewayController $admin): JsonResponse
    {
        $request->replace(['port' => 5060, 'transport' => 'udp', 'priority' => 1, 'weight' => 100, 'enabled' => true, ...$request->all()]);
        $gateway = $trunk->gateways()->create($admin->validated($request, $trunk));

        return response()->json(['data' => Present::gateway($gateway->fresh())], 201);
    }

    /** Partial update. The password is write-only: omit it to keep it, send null to remove it. */
    public function updateGateway(Request $request, Trunk $trunk, Gateway $gateway, GatewayController $admin): JsonResponse
    {
        $clearPassword = $request->exists('password') && $request->input('password') === null;
        $request->replace([...$gateway->only(['name', 'host', 'port', 'username', 'register', 'enabled', 'priority', 'weight']),
            'transport' => $gateway->transport->value, ...$request->all()]);
        $data = $admin->validated($request, $trunk, $gateway);
        if (! $request->filled('password')) {
            unset($data['password']);
        }
        if ($clearPassword) {
            $data['password'] = null;
        }
        $gateway->update($data);

        return response()->json(['data' => Present::gateway($gateway->fresh())]);
    }

    public function destroyGateway(Trunk $trunk, Gateway $gateway): Response
    {
        $gateway->delete();

        return response()->noContent();
    }

    // --- routing plans and routes ---

    public function plans(Request $request): JsonResponse
    {
        return $this->paged(RoutingPlan::query()->orderBy('id')->paginate($this->perPage($request)), fn ($p) => Present::plan($p));
    }

    public function showPlan(RoutingPlan $routingPlan): JsonResponse
    {
        $routingPlan->load(['routes' => fn ($q) => $q->orderBy('prefix')->orderBy('priority')]);

        return response()->json(['data' => Present::plan($routingPlan, true)]);
    }

    public function storePlan(Request $request, RoutingPlanController $admin): JsonResponse
    {
        $request->replace(['mode' => 'lcr', 'max_attempts' => 3, ...$request->all()]);
        $plan = RoutingPlan::create($admin->validated($request));

        return response()->json(['data' => Present::plan($plan->fresh()->load('routes'), true)], 201);
    }

    public function updatePlan(Request $request, RoutingPlan $routingPlan, RoutingPlanController $admin): JsonResponse
    {
        $request->replace([...$routingPlan->only(['name', 'max_attempts', 'description']), 'mode' => $routingPlan->mode->value, ...$request->all()]);
        $routingPlan->update($admin->validated($request, $routingPlan));

        return response()->json(['data' => Present::plan($routingPlan->fresh()->load('routes'), true)]);
    }

    public function storeRoute(Request $request, RoutingPlan $routingPlan): JsonResponse
    {
        $request->merge(['prefix' => preg_replace('/[\s+\-]/', '', (string) $request->input('prefix'))]);
        $data = $request->validate([
            'prefix' => ['nullable', 'string', 'max:32', 'regex:/^[0-9]*$/'],
            'trunk_id' => ['required', 'integer', 'exists:trunks,id'],
            'priority' => ['nullable', 'integer', 'between:1,100'],
            'weight' => ['nullable', 'integer', 'between:1,1000'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        $data = ['priority' => 1, 'weight' => 100, 'enabled' => true, ...array_filter($data, fn ($v) => $v !== null), 'prefix' => $data['prefix'] ?? ''];
        if ($routingPlan->routes()->where('prefix', $data['prefix'])->where('trunk_id', $data['trunk_id'])->exists()) {
            throw ValidationException::withMessages(['trunk_id' => 'That trunk is already routed for this prefix.']);
        }

        return response()->json(['data' => Present::route($routingPlan->routes()->create($data))], 201);
    }

    public function updateRoute(Request $request, RoutingPlan $routingPlan, RoutingPlanRoute $route): JsonResponse
    {
        $route->update($request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'between:1,100'],
            'weight' => ['sometimes', 'integer', 'between:1,1000'],
        ]));

        return response()->json(['data' => Present::route($route->fresh())]);
    }

    public function destroyRoute(RoutingPlan $routingPlan, RoutingPlanRoute $route): Response
    {
        $route->delete();

        return response()->noContent();
    }

    /** What the routing engine would do with a call from a customer to a number, right now. Nothing is reserved. */
    public function routeTest(Request $request, Router $router): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:accounts,id'],
            'number' => ['required', 'string', 'max:64', 'regex:/^[0-9+\s()\-*#]+$/'],
        ]);

        return response()->json(['data' => $router->route(Account::query()->findOrFail($data['customer_id']), $data['number'])]);
    }
}
