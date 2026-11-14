<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tax;
use App\Support\TaxRegions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Billing → Taxes: sales tax rates per Canadian province and US state. */
class TaxController extends Controller
{
    public function index(): Response
    {
        $taxes = Tax::query()->orderBy('position')->orderBy('id')->get()->groupBy('region');
        $customers = DB::table('accounts')->where('is_customer', true)->whereNotNull('tax_region')
            ->groupBy('tax_region')->selectRaw('tax_region, count(*) AS n')->pluck('n', 'tax_region');

        return Inertia::render('billing/Taxes', [
            'countries' => array_map(fn ($group) => [
                'country' => $group['country'],
                'regions' => array_map(fn ($r) => [
                    ...$r,
                    'customers' => (int) ($customers[$r['code']] ?? 0),
                    'taxes' => ($taxes[$r['code']] ?? collect())->map(fn (Tax $t) => [
                        'id' => $t->id, 'name' => $t->name, 'rate' => rtrim(rtrim((string) $t->rate, '0'), '.'), 'active' => $t->active,
                    ])->values(),
                ], $group['regions']),
            ], TaxRegions::options()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'region' => ['required', Rule::in(array_keys(TaxRegions::all()))],
            'name' => ['required', 'string', 'max:64', Rule::unique('taxes', 'name')->where('region', $request->input('region'))],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ], ['name.unique' => 'That region already has a tax with this name.']);
        Tax::create([...$data, 'position' => (int) Tax::query()->where('region', $data['region'])->max('position') + 1]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$data['name']} added to ".TaxRegions::name($data['region']).'.']);

        return back();
    }

    /** New rates apply to charges from now on; what's already posted keeps its rate. */
    public function update(Request $request, Tax $tax): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('taxes', 'name')->where('region', $tax->region)->ignore($tax)],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $tax->update([...$data, 'active' => $request->boolean('active', $tax->active)]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$tax->name} for ".TaxRegions::name($tax->region).' saved.']);

        return back();
    }

    public function destroy(Tax $tax): RedirectResponse
    {
        $tax->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$tax->name} removed from ".TaxRegions::name($tax->region).'.']);

        return back();
    }
}
