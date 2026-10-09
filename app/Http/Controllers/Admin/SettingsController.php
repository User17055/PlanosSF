<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DecisionRecord;
use App\Services\Audit\AuditLogger;
use App\Services\Settings;
use Illuminate\Http\Request;

/**
 * Decisões pendentes viram configurações explícitas, nunca regras implícitas no código (seção 19).
 */
class SettingsController extends Controller
{
    public function index(Settings $settings)
    {
        $items = collect(Settings::DEFAULTS)->map(fn ($def, $key) => [
            'key' => $key,
            'value' => $settings->get($key),
            'pending' => $settings->isPendingDecision($key),
            'description' => $def['description'],
        ]);

        return view('admin.settings', [
            'items' => $items,
            'decisions' => DecisionRecord::latest()->get(),
            'billingDriver' => config('planos.billing.driver'),
            'integrationModes' => config('planos.integrations'),
        ]);
    }

    public function update(Request $request, Settings $settings, AuditLogger $audit)
    {
        $d = $request->validate(['key' => 'required|in:'.implode(',', array_keys(Settings::DEFAULTS)), 'value' => 'nullable|string|max:255', 'decided' => 'nullable|boolean', 'reason' => 'required|string|max:500']);
        $current = $settings->get($d['key']);
        $value = is_bool(Settings::DEFAULTS[$d['key']]['value']) ? $request->boolean('value') : $d['value'];
        $settings->set($d['key'], $value, ! $request->boolean('decided'), $request->user()->id);
        $audit->log('settings.updated', null, ['chave' => $d['key'], 'de' => $current, 'para' => $value, 'decisao_aprovada' => $request->boolean('decided')], $d['reason']);

        return back()->with('success', 'Configuração atualizada.');
    }

    public function storeDecision(Request $request, AuditLogger $audit)
    {
        $d = $request->validate([
            'subject' => 'required|string|max:255',
            'owner' => 'required|string|max:255',
            'decided_on' => 'nullable|date',
            'approved_option' => 'nullable|string|max:5000',
            'expected_effect' => 'nullable|string|max:5000',
            'status' => 'required|in:pending,approved',
        ]);
        $r = DecisionRecord::create($d + ['created_by' => $request->user()->id]);
        $audit->log('decision.recorded', $r, ['assunto' => $d['subject'], 'situacao' => $d['status']]);

        return back()->with('success', 'Decisão registrada.');
    }
}
