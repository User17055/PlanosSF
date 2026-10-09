<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerAttachment;
use App\Models\IntegrationJob;
use App\Models\Proposal;
use App\Services\Access\FranchiseContext;
use App\Services\Audit\AuditLogger;
use App\Services\Documents\DocumentStorage;
use App\Services\Financial\FinancialStatusService;
use App\Services\Subscriptions\ActivationService;
use App\Support\Cpf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CustomerController extends Controller
{
    public function __construct(protected AuditLogger $audit, protected FranchiseContext $ctx) {}

    public function index(Request $request)
    {
        $q = trim((string) $request->input('q'));
        $customers = collect();
        if ($q !== '') {
            $digits = Cpf::normalize($q);
            $customers = Customer::query()
                ->when($digits && strlen($digits) >= 6, fn ($query) => $query->where('document_normalized', 'like', $digits.'%'))
                ->when(! ($digits && strlen($digits) >= 6), fn ($query) => $query->where(fn ($w) => $w
                    ->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.strtolower($q).'%')))
                ->withCount('pets')->orderBy('name')->limit(50)->get();
        }

        return view('customers.index', compact('customers', 'q'));
    }

    public function create()
    {
        return view('customers.form', ['customer' => new Customer]);
    }

    public function store(CustomerRequest $request)
    {
        $customer = Customer::create($request->attributesForModel() + [
            'created_by' => $request->user()->id,
            'created_franchise_id' => $this->ctx->id(),
            'marketing_opt_in' => $request->boolean('marketing_opt_in'),
            'marketing_opt_in_at' => $request->boolean('marketing_opt_in') ? now() : null,
        ]);
        $this->audit->log('customer.created', $customer);

        return redirect()->route('customers.show', $customer)->with('success', 'Tutor cadastrado.');
    }

    /** Perfil do cliente (seção 15): uma única consulta mostra pets cobertos, contratos, parcelas e motivos. */
    public function show(Customer $customer, FinancialStatusService $financial, ActivationService $activation)
    {
        $customer->load(['pets', 'attachments.uploader', 'financialEvents', 'createdFranchise']);
        $subscriptions = $customer->subscriptions()->with(['pet', 'planVersion.plan', 'contract', 'invoices', 'originatingFranchise'])->latest()->get();
        $proposals = Proposal::where('customer_id', $customer->id)->with('planVersion.plan', 'pet', 'seller', 'originatingFranchise')->latest()->get();
        $financialSnapshot = $financial->evaluate($customer);
        $impediments = $subscriptions->mapWithKeys(fn ($s) => [$s->id => $activation->impediments($s)]);
        $integrations = IntegrationJob::where(fn ($q) => $q->where('subject_type', 'Customer')->where('subject_id', $customer->id))
            ->orWhere(fn ($q) => $q->where('subject_type', 'Subscription')->whereIn('subject_id', $subscriptions->pluck('id')))
            ->latest()->limit(20)->get();
        $history = AuditLog::where(fn ($q) => $q->where('auditable_type', 'Customer')->where('auditable_id', $customer->id))
            ->orWhere(fn ($q) => $q->where('auditable_type', 'Pet')->whereIn('auditable_id', $customer->pets->pluck('id')))
            ->with('user')->latest('created_at')->limit(30)->get();

        return view('customers.show', [
            'customer' => $customer,
            'subscriptions' => $subscriptions,
            'proposals' => $proposals,
            'financial' => $financialSnapshot,
            'impediments' => $impediments,
            'integrations' => $integrations,
            'history' => $history,
            'ctx' => $this->ctx,
        ]);
    }

    public function edit(Customer $customer)
    {
        return view('customers.form', compact('customer'));
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        $customer->fill($request->attributesForModel());
        $optIn = $request->boolean('marketing_opt_in');
        if ($optIn !== $customer->marketing_opt_in) {
            $customer->marketing_opt_in = $optIn;
            $customer->marketing_opt_in_at = $optIn ? now() : null;
        }
        if ($customer->isDirty()) {
            $this->audit->log('customer.updated', $customer, $this->audit->diff($customer), $request->input('change_reason'));
            $customer->save();
        }

        return redirect()->route('customers.show', $customer)->with('success', 'Cadastro atualizado.');
    }

    /** Comprovante, documento de identificação e outros: anexos distintos do contrato (seção 7). */
    public function attach(Request $request, Customer $customer, DocumentStorage $storage)
    {
        $data = $request->validate([
            'kind' => 'required|in:'.implode(',', array_keys(CustomerAttachment::KINDS)),
            'file' => 'required|file|max:'.config('planos.uploads.max_kb'),
            'description' => 'nullable|string|max:500',
        ]);
        $stored = $storage->putUpload($request->file('file'), 'customers/'.$customer->id);
        $attachment = $customer->attachments()->create($stored + [
            'kind' => $data['kind'],
            'description' => $data['description'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);
        $this->audit->log('customer.attachment_added', $customer, ['tipo' => $data['kind'], 'anexo' => $attachment->id]);

        return back()->with('success', 'Anexo registrado. Ele não substitui o contrato final.');
    }

    public function downloadAttachment(CustomerAttachment $attachment)
    {
        $this->audit->log('customer.attachment_downloaded', $attachment);

        return Storage::disk($attachment->disk)->download($attachment->storage_key, 'anexo-'.$attachment->id.'.'.pathinfo($attachment->storage_key, PATHINFO_EXTENSION));
    }
}
