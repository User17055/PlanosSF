<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\ContractTemplate;
use App\Services\Audit\AuditLogger;
use App\Services\Contracts\ContractRenderer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ContractTemplateController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function index()
    {
        return view('catalog.templates.index', ['templates' => ContractTemplate::orderBy('name')->orderByDesc('version')->get()]);
    }

    public function create()
    {
        return view('catalog.templates.form', ['template' => new ContractTemplate, 'placeholders' => ContractRenderer::PLACEHOLDERS]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|max:255', 'body' => 'required|string|max:200000']);
        $version = (int) ContractTemplate::where('name', $d['name'])->max('version') + 1;
        $t = ContractTemplate::create($d + ['version' => $version, 'status' => 'draft', 'created_by' => $request->user()->id]);
        $this->audit->log('contract_template.created', $t);

        return redirect()->route('templates.index')->with('success', 'Modelo salvo.');
    }

    public function edit(ContractTemplate $template)
    {
        return view('catalog.templates.form', ['template' => $template, 'placeholders' => ContractRenderer::PLACEHOLDERS]);
    }

    public function update(Request $request, ContractTemplate $template)
    {
        if ($template->isLocked()) {
            throw ValidationException::withMessages(['body' => 'Modelo já utilizado por versão publicada: crie uma nova versão.']);
        }
        $d = $request->validate(['body' => 'required|string|max:200000']);
        $template->fill($d);
        $this->audit->log('contract_template.updated', $template);
        $template->save();

        return redirect()->route('templates.index')->with('success', 'Modelo atualizado.');
    }

    public function newVersion(Request $request, ContractTemplate $template)
    {
        $next = (int) ContractTemplate::where('name', $template->name)->max('version') + 1;
        $copy = ContractTemplate::create([
            'name' => $template->name, 'version' => $next, 'body' => $template->body,
            'status' => 'draft', 'created_by' => $request->user()->id,
        ]);
        $this->audit->log('contract_template.version_created', $copy, ['origem' => $template->version]);

        return redirect()->route('templates.edit', $copy)->with('success', 'Nova versão do modelo criada.');
    }
}
