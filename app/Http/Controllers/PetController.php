<?php

namespace App\Http\Controllers;

use App\Enums\Species;
use App\Models\Customer;
use App\Models\Pet;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PetController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function create(Customer $customer)
    {
        return view('pets.form', ['customer' => $customer, 'pet' => new Pet]);
    }

    public function store(Request $request, Customer $customer)
    {
        $pet = $customer->pets()->create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->audit->log('pet.created', $pet);

        // Só volta para uma página deste sistema: um return_to de fora seria um redirecionamento aberto.
        $back = (string) $request->input('return_to');
        if ($back === '' || parse_url($back, PHP_URL_HOST) !== $request->getHost()) {
            $back = route('customers.show', $customer);
        }

        return redirect()->to($back)->with('success', 'Pet cadastrado.');
    }

    public function edit(Pet $pet)
    {
        return view('pets.form', ['customer' => $pet->customer, 'pet' => $pet]);
    }

    /** A alteração de cadastro não modifica contratos históricos (dados congelados no aceite). */
    public function update(Request $request, Pet $pet)
    {
        $data = $this->validated($request);
        unset($data['estimated_age_reference_date']);
        $pet->fill($data);
        if ($pet->isDirty('estimated_age_months')) {
            $pet->estimated_age_reference_date = $pet->estimated_age_months !== null ? now(config('planos.business_timezone'))->toDateString() : null;
        }
        if ($pet->isDirty()) {
            $this->audit->log('pet.updated', $pet, $this->audit->diff($pet));
            $pet->save();
        }

        return redirect()->route('customers.show', $pet->customer_id)->with('success', 'Pet atualizado.');
    }

    protected function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => 'required|string|max:255',
            'species' => ['required', Rule::enum(Species::class)],
            'breed' => 'nullable|string|max:120',
            'sex' => 'nullable|in:macho,femea',
            'birth_date' => 'nullable|date|before_or_equal:today',
            'estimated_age_months' => 'nullable|integer|min:0|max:400',
            'identifier' => 'nullable|string|max:60',
        ]);
        if (! empty($d['estimated_age_months']) && empty($d['birth_date'])) {
            $d['estimated_age_reference_date'] = now(config('planos.business_timezone'))->toDateString();
        }

        return $d;
    }
}
