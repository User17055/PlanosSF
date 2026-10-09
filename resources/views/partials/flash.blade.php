@if (session('success'))
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle"></i> {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if (session('homolog_code'))
    <div class="alert alert-warning"><strong>Homologação:</strong> código de confirmação <code class="fs-5">{{ session('homolog_code') }}</code> (exibido porque não há e-mail configurado).</div>
@endif
@if (session('warning'))
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> {{ session('warning') }}</div>
@endif
@if (session('warnings'))
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i>
        @foreach (session('warnings') as $w)<div>{{ $w }}</div>@endforeach
    </div>
@endif
@if ($errors->any())
    <div class="alert alert-danger">
        <strong>Não foi possível concluir:</strong>
        <ul class="mb-0">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif
