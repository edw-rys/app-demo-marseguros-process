{{--
    Conector entre dos nodos del grafo estilo workflow canvas.
    @var string $status  estado del nodo de origen (passed|failed|degraded|running|skipped|pending)
--}}
<div class="gdv-connector gdv-connector--{{ $status ?? 'pending' }}" aria-hidden="true">
    <div class="gdv-connector-line"></div>
</div>