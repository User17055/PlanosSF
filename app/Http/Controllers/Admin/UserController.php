<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Franchise;
use App\Models\FranchiseUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\FranchiseContext;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Contas individuais vinculadas à franquia (seção 27.1). Usuário desativado perde o acesso,
 * mas permanece identificado nos registros que produziu.
 */
class UserController extends Controller
{
    public function __construct(protected FranchiseContext $ctx, protected AuditLogger $audit) {}

    public function index()
    {
        $users = User::with(['memberships' => fn ($q) => $q->with('franchise', 'role')])
            ->when(! $this->ctx->isCentral(), fn ($q) => $q->whereHas('memberships', fn ($m) => $m->where('franchise_id', $this->ctx->id())))
            ->orderBy('name')->paginate(50);

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        return view('admin.users.form', ['user' => new User] + $this->options());
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
            'franchise_id' => ['required', 'integer', Rule::in($this->allowedFranchiseIds())],
            'role_id' => ['required', 'integer', Rule::in($this->allowedRoleIds())],
        ]);
        $user = User::create(['name' => $d['name'], 'email' => strtolower($d['email']), 'password' => $d['password'], 'is_active' => true]);
        FranchiseUser::create(['user_id' => $user->id, 'franchise_id' => $d['franchise_id'], 'role_id' => $d['role_id'], 'granted_by' => $request->user()->id]);
        $this->audit->log('user.created', $user, ['unidade' => $d['franchise_id'], 'perfil' => $d['role_id']]);

        return redirect()->route('users.edit', $user)->with('success', 'Usuário criado. Oriente-o a trocar a senha no primeiro acesso.');
    }

    public function edit(User $user)
    {
        $this->assertManageable($user);
        $user->load('memberships.franchise', 'memberships.role');

        return view('admin.users.form', ['user' => $user] + $this->options());
    }

    public function update(Request $request, User $user)
    {
        $this->assertManageable($user);
        $d = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'is_active' => 'required|boolean',
        ]);
        abort_if($user->id === $request->user()->id && ! $d['is_active'], 422, 'Você não pode desativar o próprio acesso.');

        $user->fill(['name' => $d['name'], 'email' => strtolower($d['email']), 'is_active' => (bool) $d['is_active']]);
        if (! empty($d['password'])) {
            $user->password = $d['password'];
        }
        if ($user->isDirty('is_active')) {
            $user->deactivated_at = $user->is_active ? null : now();
        }
        $this->audit->log('user.updated', $user, $this->audit->diff($user));
        $user->save();

        if (! $user->is_active) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return back()->with('success', 'Usuário atualizado.');
    }

    public function addMembership(Request $request, User $user)
    {
        $this->assertManageable($user);
        $d = $request->validate([
            'franchise_id' => ['required', 'integer', Rule::in($this->allowedFranchiseIds())],
            'role_id' => ['required', 'integer', Rule::in($this->allowedRoleIds())],
        ]);
        $m = FranchiseUser::updateOrCreate(['user_id' => $user->id, 'franchise_id' => $d['franchise_id']], ['role_id' => $d['role_id'], 'is_active' => true, 'granted_by' => $request->user()->id]);
        $this->audit->log('user.membership_granted', $user, ['unidade' => $d['franchise_id'], 'perfil' => $d['role_id'], 'vinculo' => $m->id]);

        return back()->with('success', 'Vínculo registrado.');
    }

    public function removeMembership(FranchiseUser $membership)
    {
        abort_unless(in_array($membership->franchise_id, $this->allowedFranchiseIds(), true), 403);
        $membership->forceFill(['is_active' => false])->save();
        $this->audit->log('user.membership_revoked', $membership->user, ['unidade' => $membership->franchise_id]);

        return back()->with('success', 'Vínculo desativado. O histórico do usuário é preservado.');
    }

    /** @return array<string, mixed> */
    protected function options(): array
    {
        return [
            'franchises' => Franchise::whereIn('id', $this->allowedFranchiseIds())->orderBy('name')->get(),
            'roles' => Role::whereIn('id', $this->allowedRoleIds())->orderBy('name')->get(),
        ];
    }

    /** @return array<int, int> */
    protected function allowedFranchiseIds(): array
    {
        return $this->ctx->isCentral() ? Franchise::pluck('id')->all() : [$this->ctx->id()];
    }

    /** @return array<int, int> */
    protected function allowedRoleIds(): array
    {
        return Role::when(! $this->ctx->isCentral(), fn ($q) => $q->where('key', '!=', 'admin_tecnico'))->pluck('id')->all();
    }

    protected function assertManageable(User $user): void
    {
        // Na unidade, só quem atua apenas nela: quem também tem vínculo ativo na central ou em outra unidade é
        // alterado pela central (senão o gestor da unidade assumiria um acesso maior que o dele).
        abort_unless($this->ctx->isCentral() || (
            $user->memberships()->where('franchise_id', $this->ctx->id())->exists()
            && ! $user->memberships()->where('is_active', true)->where('franchise_id', '!=', $this->ctx->id())->exists()
        ), 403);
    }
}
