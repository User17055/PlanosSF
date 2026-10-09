<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Access\FranchiseContext;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request, FranchiseContext $ctx)
    {
        $logs = AuditLog::with('user', 'franchise')
            ->when(! $ctx->isCentral(), fn ($q) => $q->where('franchise_id', $ctx->id()))
            ->when($request->action, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($request->correlation, fn ($q, $c) => $q->where('correlation_id', $c))
            ->when($request->object, function ($q, $o) {
                [$type, $id] = array_pad(explode('#', $o, 2), 2, null);
                $q->where('auditable_type', $type)->when($id, fn ($w) => $w->where('auditable_id', $id));
            })
            ->latest('created_at')->paginate(50)->withQueryString();

        return view('admin.audit', compact('logs'));
    }
}
