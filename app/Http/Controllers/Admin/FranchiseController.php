<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Franchise;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FranchiseController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function index()
    {
        return view('admin.franchises.index', ['franchises' => Franchise::withCount('users')->orderByDesc('is_central')->orderBy('name')->get()]);
    }

    public function create()
    {
        return view('admin.franchises.form', ['franchise' => new Franchise(['status' => 'active'])]);
    }

    public function store(Request $request)
    {
        $f = Franchise::create($this->validated($request));
        $this->audit->log('franchise.created', $f);

        return redirect()->route('franchises.index')->with('success', 'Franquia cadastrada.');
    }

    public function edit(Franchise $franchise)
    {
        return view('admin.franchises.form', compact('franchise'));
    }

    public function update(Request $request, Franchise $franchise)
    {
        $franchise->fill($this->validated($request, $franchise));
        $this->audit->log('franchise.updated', $franchise, $this->audit->diff($franchise));
        $franchise->save();

        return redirect()->route('franchises.index')->with('success', 'Franquia atualizada.');
    }

    protected function validated(Request $request, ?Franchise $franchise = null): array
    {
        return $request->validate([
            'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('franchises', 'code')->ignore($franchise?->id)],
            'name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
            'document' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'address_line' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|size:2',
        ]);
    }
}
