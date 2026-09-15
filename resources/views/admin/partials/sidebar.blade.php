@php($active = $active ?? 'dashboard')
<aside class="sidebar">
    <div class="brand">
        <img src="{{ asset('brand/oktane-logo-white.svg') }}" alt="Oktane" style="height:34px">
    </div>
    <div class="nav-label">Visão geral</div>
    <a class="nav-item {{ $active === 'dashboard' ? 'active' : '' }}" href="{{ route('admin.dashboard') }}">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="14" y="3" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="3" y="14" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/><rect x="14" y="14" width="7" height="7" rx="1.5" stroke="currentColor" stroke-width="1.8"/></svg>
        Painel
    </a>
    <a class="nav-item {{ $active === 'webposto' ? 'active' : '' }}" href="{{ route('admin.webposto') }}">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="4" width="18" height="6" rx="1.6" stroke="currentColor" stroke-width="1.8"/><rect x="3" y="14" width="18" height="6" rx="1.6" stroke="currentColor" stroke-width="1.8"/><circle cx="7" cy="7" r="1" fill="currentColor"/><circle cx="7" cy="17" r="1" fill="currentColor"/></svg>
        WebPosto
    </a>
    <a class="nav-item {{ $active === 'alterdata' ? 'active' : '' }}" href="{{ route('admin.alterdata') }}">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5v-11Z" stroke="currentColor" stroke-width="1.8"/><path d="M8 15.5V12m4 3.5V8.5m4 7v-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        Alterdata
    </a>
    <a class="nav-item {{ $active === 'services' ? 'active' : '' }}" href="{{ route('admin.services') }}">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3 3 8l9 5 9-5-9-5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m3 13 9 5 9-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Serviços
    </a>
    <div class="sidebar-foot">
        @if($active === 'services')
            <button class="queue-open" id="queue-open" type="button" style="display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:8px">
                <span style="display:flex;align-items:center;gap:6px;min-width:0;overflow:hidden;text-overflow:ellipsis">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" style="width:14px;height:14px;flex:0 0 14px"><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/><path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span style="overflow:hidden;text-overflow:ellipsis">Central de execução</span>
                </span>
                <span class="queue-count" id="queue-count" style="flex:0 0 auto;min-width:20px;padding:2px 7px;border-radius:999px;background:var(--oktane-orange);color:#fff;font-size:10px;font-weight:800">0</span>
            </button>
        @endif
        <form method="POST" action="{{ route('admin.logout') }}">@csrf<button class="logout">Encerrar sessão</button></form>
    </div>
</aside>
