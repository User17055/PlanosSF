@extends('layouts.public')
@section('title', 'Link indisponível')
@section('content')
<div class="text-center py-5">
    <div class="display-5 text-muted"><i class="bi bi-link-45deg"></i></div>
    <h1 class="h4">Este link não está mais disponível</h1>
    <p class="text-muted">Ele pode ter expirado, sido substituído por uma nova proposta ou revogado.<br>Solicite uma nova proposta à unidade São Francisco que atendeu você.</p>
    <a href="{{ route('site.plans') }}" class="btn btn-outline-primary">Ver planos</a>
</div>
@endsection
