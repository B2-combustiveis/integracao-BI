<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Serviços · Integração BI</title>
    @include('admin.partials.favicons')
    @include('admin.partials.theme')
    <style>
        .panel{border:1px solid var(--oktane-border);border-radius:var(--radius-lg);overflow:hidden;background:#fff;box-shadow:var(--shadow-card)}
        .empty{padding:64px 20px;text-align:center;color:var(--oktane-muted)}
        .empty-icon{display:grid;place-items:center;width:44px;height:44px;margin:0 auto 14px;border:1px solid var(--oktane-border);border-radius:50%;color:var(--oktane-muted)}
        .empty strong{display:block;margin-bottom:6px;color:var(--oktane-navy);font-size:14px}
        .empty span{font-size:12px}
        .action{padding:7px 10px;border:1px solid var(--oktane-border);border-radius:8px;background:#fff;color:var(--oktane-text);cursor:pointer;font:inherit}
        .action:hover{border-color:#CBD5E1;background:var(--oktane-canvas)}
        .confirm-danger{border-color:#FCA5A5;color:var(--oktane-danger)}
        .confirm-danger:hover{background:var(--oktane-danger-bg)}

        /* Execution drawer */
        .queue-open{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}
        .drawer-overlay{position:fixed;inset:0;background:rgba(13,17,23,0);opacity:0;visibility:hidden;transition:.25s;z-index:20}
        .drawer-overlay.open{background:rgba(13,17,23,.4);opacity:1;visibility:visible}
        .queue-drawer{position:fixed;z-index:21;top:0;right:0;width:min(430px,100%);height:100vh;padding:25px;background:#fff;border-left:1px solid var(--oktane-border);box-shadow:var(--shadow-modal);transform:translateX(100%);transition:transform .3s;overflow:auto}
        .queue-drawer.open{transform:none}
        .drawer-head{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:22px}
        .drawer-head h2{margin:0;font-size:18px;color:var(--oktane-navy)}
        .drawer-head p{margin:5px 0 0;color:var(--oktane-muted);font-size:11px}
        .drawer-close{width:34px;height:34px;border:1px solid var(--oktane-border);border-radius:50%;background:transparent;color:var(--oktane-muted);cursor:pointer}
        .queue-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px}
        .queue-stat{padding:12px;border:1px solid var(--oktane-border);border-radius:10px;background:#fff}
        .queue-stat span{display:block;color:var(--oktane-muted);font-size:9px;text-transform:uppercase}
        .queue-stat strong{display:block;margin-top:5px;font-size:18px;color:var(--oktane-navy)}
        .live-card{padding:14px;margin-bottom:10px;border:1px solid var(--oktane-border);border-radius:12px;background:#fff;box-shadow:var(--shadow-card)}
        .live-top{display:flex;justify-content:space-between;gap:10px}
        .live-name{font-size:12px;font-weight:750;color:var(--oktane-navy)}
        .live-state{color:var(--oktane-info-text);font-size:10px;font-weight:650}
        .live-meta{margin-top:8px;color:var(--oktane-muted);font-size:10px;line-height:1.6}
        .live-progress{height:5px;margin-top:10px;border-radius:8px;background:var(--oktane-canvas);overflow:hidden}
        .live-progress i{display:block;height:100%;width:38%;border-radius:8px;background:var(--oktane-info);animation:pulse 1.4s ease-in-out infinite}
        @keyframes pulse{50%{opacity:.35;transform:translateX(130%)}}
        .drawer-tabs{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-bottom:14px}
        .drawer-tab{padding:9px;border:1px solid var(--oktane-border);border-radius:9px;background:#fff;color:var(--oktane-muted);cursor:pointer;font:inherit}
        .drawer-tab.active{background:var(--oktane-navy);color:#fff;border-color:var(--oktane-navy)}
        .drawer-pane{display:none}
        .drawer-pane.active{display:block}
        .drawer-refresh{margin-left:auto;padding:6px 9px;border:1px solid var(--oktane-border);border-radius:7px;background:transparent;color:var(--oktane-text);cursor:pointer;font:inherit}
        .completed-card{padding:13px;margin-bottom:9px;border:1px solid var(--oktane-border);border-radius:11px;background:#fff;box-shadow:var(--shadow-card)}
        .completed-actions{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:10px}
        .completed-date{color:var(--oktane-muted);font-size:10px}
        .drawer-empty{padding:35px;text-align:center;color:var(--oktane-muted);font-size:11px}

        h1{margin:0 0 24px}

        /* Service rows */
        .toggle{width:34px;height:34px;flex:0 0 34px;padding:0;display:grid;place-items:center;border:1px solid var(--oktane-border);border-radius:50%;background:#fff;color:var(--oktane-muted);cursor:pointer;transition:background .2s,border-color .2s,color .2s}
        .toggle:hover{border-color:#CBD5E1;color:var(--oktane-navy)}
        .toggle svg{width:15px;height:15px;transition:transform .3s ease}
        .toggle.open svg{transform:rotate(180deg)}
        .service-title{padding:0;border:0;background:transparent;color:var(--oktane-navy);font-weight:700;text-align:left;cursor:pointer;font:inherit}
        .service-title:hover{color:var(--oktane-orange-dark)}
        .service-subtitle{display:block;margin-top:4px;color:var(--oktane-muted);font-size:10px;font-weight:400}
        .detail-row td{padding:0;border-bottom:1px solid var(--oktane-border)}
        .detail{max-height:0;overflow:hidden;background:var(--oktane-canvas);transition:max-height .42s ease}
        .detail.open{max-height:900px}
        .detail-content{padding:22px 24px 26px;display:grid;grid-template-columns:1.2fr 1fr;gap:28px}
        .detail h3{margin:0 0 9px;font-size:13px;color:var(--oktane-navy)}
        .detail p{margin:0;color:var(--oktane-muted);font-size:12px;line-height:1.65}
        .flow{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin-top:15px}
        .step{padding:7px 9px;border:1px solid var(--oktane-border);border-radius:8px;background:#fff;color:var(--oktane-text);font-size:11px}
        .arrow{color:var(--oktane-muted)}
        .facts{display:grid;grid-template-columns:1fr 1fr;gap:9px}
        .fact{padding:11px;border:1px solid var(--oktane-border);border-radius:9px;background:#fff}
        .fact span{display:block;color:var(--oktane-muted);font-size:9px;text-transform:uppercase;letter-spacing:.08em}
        .fact strong{display:block;margin-top:5px;font-size:11px;color:var(--oktane-navy)}
        .next-action{grid-column:1;width:min(260px,100%);justify-self:start;margin-left:0;margin-right:auto}
        .next-action .facts{display:block}
        .next-action .fact{padding:9px 11px}
        .fields{grid-column:1/-1}
        .resource-head{display:flex;align-items:center;gap:8px;margin-bottom:11px}
        .resource-head h3{margin:0}
        .resource-count{padding:3px 7px;border-radius:999px;background:var(--oktane-info-bg);color:var(--oktane-info-text);font-size:9px;font-weight:800}
        .resource-empty{padding:13px;border:1px dashed var(--oktane-border);border-radius:9px;color:var(--oktane-muted);font-size:11px}
        .chips{display:flex;flex-wrap:wrap;gap:6px}
        .chip{padding:5px 8px;border-radius:6px;background:var(--oktane-canvas);color:var(--oktane-text);font:10px ui-monospace,SFMono-Regular,monospace}
        .resource-chip{padding:7px 10px;border:1px solid var(--oktane-border);border-radius:8px;background:#fff;color:var(--oktane-navy);font-size:11px;font-weight:700}
        @media(max-width:760px){.detail-content{grid-template-columns:1fr}.fields{grid-column:auto}}

        .history{width:100%;border:1px solid var(--oktane-border);border-radius:10px;overflow:hidden}
        .history-row{display:grid;grid-template-columns:150px 1fr 140px 110px;gap:10px;align-items:center;padding:10px 12px;border-bottom:1px solid var(--oktane-border);font-size:11px}
        .history-row:last-child{border-bottom:0}
        .history-run{display:flex;align-items:center;gap:7px}
        .run-status{display:inline-flex;padding:4px 7px;border-radius:999px;font-size:9px;font-weight:650}
        .run-status.success{background:var(--oktane-success-bg);color:var(--oktane-success-text)}
        .run-status.failed{background:var(--oktane-danger-bg);color:var(--oktane-danger-text)}
        .run-status.running{background:var(--oktane-warning-bg);color:var(--oktane-warning-text)}
        .history-meta{color:var(--oktane-muted)}
        .export{display:inline-block;padding:7px 9px;border:1px solid var(--oktane-border);border-radius:7px;color:var(--oktane-navy);text-decoration:none;text-align:center}
        .export:hover{border-color:#CBD5E1;background:var(--oktane-canvas)}
        @media(max-width:760px){.history-row{grid-template-columns:1fr}}

        .history-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px}
        .history-head h3{margin:0;color:var(--oktane-navy)}
        .confirm-overlay{position:fixed;inset:0;z-index:40;display:grid;place-items:center;padding:20px;background:rgba(13,17,23,0);opacity:0;visibility:hidden;transition:.2s}
        .confirm-overlay.open{background:rgba(13,17,23,.45);opacity:1;visibility:visible}

        .company-dropdown{margin-top:11px;border:1px solid var(--oktane-border);border-radius:9px;background:var(--oktane-canvas)}
        .company-dropdown summary{padding:9px 11px;display:flex;align-items:center;justify-content:space-between;gap:8px;color:var(--oktane-text);font-size:10px;font-weight:700;cursor:pointer;list-style:none}
        .company-dropdown summary::-webkit-details-marker{display:none}
        .company-dropdown summary:after{content:'⌄';color:var(--oktane-muted)}
        .company-list{padding:0 10px 9px}
        .company-item{display:grid;grid-template-columns:16px 1fr auto;gap:7px;padding:8px 2px;border-top:1px solid var(--oktane-border)}
        .company-icon.success{color:var(--oktane-success)}
        .company-icon.running{color:var(--oktane-info)}
        .company-icon.failed{color:var(--oktane-danger)}
        .company-icon.pending{color:var(--oktane-muted)}
        .company-name{font-size:10px;font-weight:750;color:var(--oktane-navy)}
        .company-meta{margin-top:3px;color:var(--oktane-muted);font-size:9px;line-height:1.45}
        .company-result{color:var(--oktane-muted);font-size:9px;text-align:right}
        .company-error{color:var(--oktane-danger)}
        .company-filter{display:flex;flex-wrap:wrap;gap:5px;padding:9px 2px 6px}
        .cf-btn{padding:4px 8px;border:1px solid var(--oktane-border);border-radius:999px;background:#fff;color:var(--oktane-muted);font-size:9px;font-weight:700;cursor:pointer}
        .cf-btn:hover{border-color:#CBD5E1}
        .cf-btn.active{background:var(--oktane-navy);border-color:var(--oktane-navy);color:#fff}
        .company-empty{padding:12px 4px;color:var(--oktane-muted);font-size:10px;text-align:center}
    </style>
</head>
<body><div class="layout">
    @include('admin.partials.sidebar', ['active' => 'services'])
    <main class="main"><h1>Serviços</h1>
        @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
        <section class="panel"><table><thead><tr><th>Serviço</th><th>Status</th><th>Agenda</th><th>Última execução</th><th>Ações</th></tr></thead><tbody>
            @forelse($services as $service)
                @php
                    $lastRun = $service->runs->first();
                    $frequency = (int) $service->frequency_minutes;
                    if ($frequency > 0 && $frequency % 1440 === 0) {
                        $amount = intdiv($frequency, 1440);
                        $scheduleLabel = 'A cada '.$amount.' '.($amount === 1 ? 'dia' : 'dias');
                    } elseif ($frequency > 0 && $frequency % 60 === 0) {
                        $amount = intdiv($frequency, 60);
                        $scheduleLabel = 'A cada '.$amount.' '.($amount === 1 ? 'hora' : 'horas');
                    } else {
                        $scheduleLabel = 'A cada '.$frequency.' '.($frequency === 1 ? 'minuto' : 'minutos');
                    }
                @endphp

                <tr data-service-id="{{ $service->id }}"><td><button class="service-title" type="button" data-detail="service-{{ $service->id }}" aria-expanded="false">{{ $service->name }}</button></td>
                    <td><span class="pill {{ $service->active ? '' : 'off' }}" data-role="service-status">{{ $service->active ? 'Ativo' : 'Pausado' }}</span></td>
                    <td><strong>{{ $scheduleLabel }}</strong></td>
                    <td data-role="run-progress">@if($lastRun?->started_at)<strong>{{ $lastRun->started_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i:s') }}</strong>@else<span class="muted">Nunca executado</span>@endif</td>
                    <td><div style="display:flex;gap:7px;align-items:center"><form method="POST" action="{{ route('admin.services.run', $service) }}">@csrf<button class="action" type="submit">Rodar agora</button></form>
                        @if($service->active)<form method="POST" action="{{ route('admin.services.pause', $service) }}">@csrf<button class="action" type="submit">Pausar</button></form>
                        @else<form method="POST" action="{{ route('admin.services.resume', $service) }}">@csrf<button class="action" type="submit">Ativar</button></form>@endif
                        <button class="toggle" type="button" data-detail="service-{{ $service->id }}" title="Ver detalhes" aria-label="Ver detalhes"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button></div></td></tr>
                <tr class="detail-row"><td colspan="5"><div class="detail" id="service-{{ $service->id }}"><div class="detail-content">
                    <div class="next-action"><div class="facts"><div class="fact"><span>Próximo acionamento</span><strong>{{ $service->active ? (optional($service->next_run_at)->timezone(config('app.display_timezone'))->format('d/m/Y H:i:s') ?: 'Aguardando acionamento') : 'Somente manual' }}</strong></div></div></div>
                    <div class="fields"><div class="history-head"><h3>Relatórios por execução</h3><form method="POST" action="{{ route('admin.services.runs.clear', $service) }}" data-clear-reports data-service-name="{{ $service->name }}">@csrf<button class="action confirm-danger" type="submit">Limpar relatórios</button></form></div><div class="history">@forelse($service->runs as $run)<div class="history-row"><strong class="history-run"><span>#{{ $run->id }}</span><span class="run-status {{ $run->status }}">{{ $run->status === 'success' ? 'Concluída' : ($run->status === 'partial' ? 'Com ressalvas' : ($run->status === 'failed' ? 'Falhou' : 'Executando')) }}</span></strong><span class="history-meta">{{ optional($run->started_at)->timezone(config('app.display_timezone'))->format('d/m/Y H:i:s') }}@if($service->resource === 'webposto-new-records') @forelse($run->new_records_by_resource as $resource => $total) &middot; {{ $resource }}: {{ $total }} novos @empty &middot; Nenhuma inserção nova @endforelse &middot; {{ $run->updated }} atualizados @else &middot; {{ $run->inserted }} novos &middot; {{ $run->updated }} atualizados &middot; {{ $run->changes_count }} detalhados @endif</span><span>{{ $run->started_at ? $run->started_at->diffForHumans($run->finished_at, true) : '—' }}</span><a class="export" href="{{ route('admin.services.runs.export', [$service, $run]) }}">Exportar Excel</a></div>@empty<div style="padding:14px;color:var(--oktane-muted);font-size:11px">Nenhuma execução registrada.</div>@endforelse</div></div>
                </div></div></td></tr>
            @empty
                <tr><td colspan="5"><div class="empty"><div class="empty-icon">◇</div><strong>Nenhum serviço configurado</strong></div></td></tr>
            @endforelse
        </tbody></table></section>
    </main>
    <div class="confirm-overlay" id="reports-clear-modal"><div class="confirm-box" role="dialog" aria-modal="true" aria-labelledby="reports-clear-title"><h2 id="reports-clear-title">Limpar relatórios?</h2><p id="reports-clear-message"></p><div class="confirm-actions" style="display:flex;justify-content:flex-end;gap:9px;margin-top:20px"><button class="action" id="reports-clear-cancel" type="button">Cancelar</button><button class="action confirm-danger" id="reports-clear-confirm" type="button">Sim, limpar</button></div></div></div>
</div><div class="drawer-overlay" id="drawer-overlay"></div><aside class="queue-drawer" id="queue-drawer"><div class="drawer-head"><div><h2>Central de execu&ccedil;&atilde;o</h2><p>Fila, conclu&iacute;dos e relat&oacute;rios em tempo real</p></div><button class="drawer-close" id="drawer-close" type="button">&times;</button></div><div class="drawer-tabs"><button class="drawer-tab active" type="button" data-pane="live">Em andamento</button><button class="drawer-tab" type="button" data-pane="completed">Conclu&iacute;dos <span id="completed-count"></span></button></div><section class="drawer-pane active" id="pane-live"><div class="queue-summary"><div class="queue-stat"><span>Total</span><strong id="queue-total">0</strong></div><div class="queue-stat"><span>Executando</span><strong id="queue-running">0</strong></div><div class="queue-stat"><span>Aguardando</span><strong id="queue-waiting">0</strong></div></div><div id="queue-live"></div></section><section class="drawer-pane" id="pane-completed"><div style="display:flex;align-items:center;margin-bottom:12px"><span class="muted" style="font-size:11px">20 execu&ccedil;&otilde;es mais recentes</span><button class="drawer-refresh" id="drawer-refresh" type="button">Atualizar</button><form method="POST" action="{{ route('admin.services.runs.clear-completed') }}" onsubmit="return confirm('Limpar todo o histórico concluído? Os dados sincronizados não serão apagados.')">@csrf<button class="drawer-refresh confirm-danger" type="submit">Limpar concluídas</button></form></div><div id="completed-live"></div></section></aside><script>
const toggleServiceDetail=detailId=>{const panel=document.getElementById(detailId);if(!panel)return;const open=panel.classList.toggle('open');document.querySelectorAll('[data-detail="'+detailId+'"]').forEach(control=>{control.classList.toggle('open',open);control.setAttribute('aria-expanded',open?'true':'false')})};document.querySelectorAll('[data-detail]').forEach(control=>control.addEventListener('click',()=>toggleServiceDetail(control.dataset.detail)));
const reportsModal=document.getElementById('reports-clear-modal'),reportsMessage=document.getElementById('reports-clear-message');let pendingReportsForm=null;const closeReportsModal=()=>{reportsModal.classList.remove('open');pendingReportsForm=null};document.querySelectorAll('[data-clear-reports]').forEach(form=>form.addEventListener('submit',event=>{event.preventDefault();pendingReportsForm=form;reportsMessage.textContent='Os relatórios concluídos de '+form.dataset.serviceName+' serão removidos. Os dados sincronizados não serão apagados.';reportsModal.classList.add('open')}));document.getElementById('reports-clear-cancel').onclick=closeReportsModal;document.getElementById('reports-clear-confirm').onclick=()=>{if(!pendingReportsForm)return;const form=pendingReportsForm;closeReportsModal();form.submit()};reportsModal.addEventListener('click',event=>{if(event.target===reportsModal)closeReportsModal()});
const duration=s=>{if(s===null)return '—';s=Math.max(0,Math.floor(Number(s)||0));const h=Math.floor(s/3600),m=Math.floor((s%3600)/60),x=s%60;return h?`${h}h ${m}min`:(m?`${m}min ${x}s`:`${x}s`)};
let liveData={services:[],completed:[],queue:{total:0,running:0,waiting:0,jobs:[]}};
const escapeHtml=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
const localDate=value=>value?new Date(value).toLocaleString('pt-BR'):'-';
const runTables=run=>{const rows=Object.entries(run.new_records_by_resource||{}).filter(([,total])=>total>0).map(([resource,total])=>escapeHtml(resource)+': '+total+' novos');const inserted=rows.join(' &middot; ')||(run.status==='running'?'Aguardando inserções novas':'Nenhuma inserção nova');return inserted+' &middot; '+Number(run.updated||0)+' atualizados'};
const companyStatus={pending:'Aguardando',running:'Processando',success:'Concluído',partial:'Com ressalvas',failed:'Falhou'};
const companyIcon={pending:'○',running:'●',success:'✓',partial:'!',failed:'✕'};
const companyFilterState={};
const companyFilterLabels={all:'Todos',success:'Sincronizados',failed:'Falharam',active:'Em andamento'};
window.setCompanyFilter=(runId,value)=>{companyFilterState[runId]=value;renderDrawer()};
const companyDropdown=(run,open=false)=>{const companies=run.companies||[];if(!companies.length)return '';const done=companies.filter(c=>['success','failed'].includes(c.status)).length;const filter=companyFilterState[run.id]||'all';const isActive=c=>c.status==='running'||c.status==='pending';const counts={all:companies.length,success:companies.filter(c=>c.status==='success').length,failed:companies.filter(c=>c.status==='failed').length,active:companies.filter(isActive).length};const filtered=companies.filter(c=>filter==='all'?true:(filter==='active'?isActive(c):c.status===filter));const filterBar='<div class="company-filter">'+['all','success','failed','active'].map(key=>'<button type="button" class="cf-btn'+(filter===key?' active':'')+'" onclick="setCompanyFilter('+run.id+',\''+key+'\')">'+companyFilterLabels[key]+' ('+counts[key]+')</button>').join('')+'</div>';const items=filtered.length?filtered.map(company=>{const progress=(company.current_page?' · página '+company.current_page:'')+(company.current_cursor!==null&&company.current_cursor!==undefined?' · cursor '+company.current_cursor:'');const current=company.status==='running'&&company.current_resource?'Tabela: '+escapeHtml(company.current_resource)+progress:companyStatus[company.status]||company.status;const result=company.inserted+' novos'+(company.skipped?' · '+company.skipped+' pendentes':'');const error=company.error?'<div class="company-meta company-error">'+escapeHtml(company.error)+'</div>':'';return '<div class="company-item"><span class="company-icon '+escapeHtml(company.status)+'">'+(companyIcon[company.status]||'○')+'</span><div><div class="company-name">'+escapeHtml(company.empresa_nome)+' · '+company.empresa_codigo+'</div><div class="company-meta">'+current+'</div>'+error+'</div><span class="company-result">'+result+'</span></div>'}).join(''):'<div class="company-empty">Nenhum posto neste filtro.</div>';return '<details class="company-dropdown" '+(open?'open':'')+'><summary><span>Postos desta execução</span><span>'+done+'/'+companies.length+' finalizados</span></summary><div class="company-list">'+filterBar+items+'</div></details>'};
function renderDrawer(){
 const q=liveData.queue;
 document.getElementById('queue-count').textContent=q.total;
 document.getElementById('queue-total').textContent=q.total;
 document.getElementById('queue-running').textContent=q.running;
 document.getElementById('queue-waiting').textContent=q.waiting;
 document.getElementById('completed-count').textContent='('+liveData.completed.length+')';
 const running=liveData.services.filter(s=>s.run?.status==='running').map(s=>'<article class="live-card"><div class="live-top"><span class="live-name">'+escapeHtml(s.name)+'</span><span class="live-state">Executando &middot; '+duration(s.run.duration_seconds)+'</span></div><div class="live-meta">'+runTables(s.run)+'</div>'+companyDropdown(s.run,true)+'<div class="live-progress"><i></i></div></article>');
 const jobs=q.jobs.filter(j=>j.status==='waiting').map(j=>'<article class="live-card"><div class="live-top"><span class="live-name">'+escapeHtml(j.name)+'</span><span class="live-state">Na fila</span></div><div class="live-meta">Fila '+escapeHtml(j.queue)+' &middot; aguardando '+duration(j.waiting_seconds)+'</div></article>');
 document.getElementById('queue-live').innerHTML=[...running,...jobs].join('')||'<div class="drawer-empty">Fila vazia. Nenhum service em execu&ccedil;&atilde;o.</div>';
 document.getElementById('completed-live').innerHTML=liveData.completed.map(run=>'<article class="completed-card"><div class="live-top"><span class="live-name">#'+run.id+' &middot; '+escapeHtml(run.service_name)+'</span><span class="live-state">'+(run.status==='success'?'Concluída':(run.status==='partial'?'Concluída com ressalvas':'Falhou'))+'</span></div><div class="live-meta">'+runTables(run)+'<br>Tempo: '+duration(run.duration_seconds)+'</div>'+companyDropdown(run,false)+'<div class="completed-actions"><span class="completed-date">'+localDate(run.finished_at)+'</span><a class="export" href="'+escapeHtml(run.export_url)+'">Gerar relatório Excel</a></div></article>').join('')||'<div class="drawer-empty">Nenhuma execução concluída.</div>';
}
function renderReportHistories(){
 liveData.services.forEach(service=>{const history=document.querySelector('#service-'+service.id+' .history');if(!history)return;const runs=liveData.completed.filter(run=>run.service_id===service.id).slice(0,5);history.innerHTML=runs.map(run=>'<div class="history-row"><strong class="history-run"><span>#'+run.id+'</span><span class="run-status '+escapeHtml(run.status)+'">'+(run.status==='success'?'Concluída':'Falhou')+'</span></strong><span class="history-meta">'+localDate(run.started_at)+' &middot; '+runTables(run)+'</span><span>'+duration(run.duration_seconds)+'</span><a class="export" href="'+escapeHtml(run.export_url)+'">Exportar Excel</a></div>').join('')||'<div style="padding:14px;color:var(--oktane-muted);font-size:11px">Nenhuma execução registrada.</div>'});
}
async function refreshServices(){
 try{
  const response=await fetch(@json(route('admin.services.status'))+'?t='+Date.now(),{cache:'no-store',headers:{Accept:'application/json'}});
  if(!response.ok)return;
  const data=await response.json();liveData=data;
  data.services.forEach(service=>{
   const row=document.querySelector('[data-service-id="'+service.id+'"]');if(!row)return;
   const badge=row.querySelector('[data-role=service-status]');badge.textContent=service.active?'Ativo':'Pausado';badge.classList.toggle('off',!service.active);
   const target=row.querySelector('[data-role=run-progress]'),run=service.run;
   if(!run){target.innerHTML='<span class="muted">Nunca executado</span>';return}
   target.innerHTML='<strong>'+localDate(run.started_at)+'</strong>';
  });renderDrawer();renderReportHistories();
 }catch(e){}
}
const drawer=document.getElementById('queue-drawer'),overlay=document.getElementById('drawer-overlay');const closeDrawer=()=>{drawer.classList.remove('open');overlay.classList.remove('open')};document.getElementById('queue-open').onclick=()=>{drawer.classList.add('open');overlay.classList.add('open');refreshServices()};document.getElementById('drawer-close').onclick=closeDrawer;document.querySelectorAll('.drawer-tab').forEach(tab=>tab.addEventListener('click',()=>{document.querySelectorAll('.drawer-tab').forEach(x=>x.classList.toggle('active',x===tab));document.querySelectorAll('.drawer-pane').forEach(pane=>pane.classList.toggle('active',pane.id==='pane-'+tab.dataset.pane));if(tab.dataset.pane==='completed')refreshServices()}));document.getElementById('drawer-refresh').onclick=refreshServices;overlay.onclick=closeDrawer;document.addEventListener('keydown',e=>{if(e.key==='Escape')closeDrawer()});
setInterval(()=>{liveData.services.forEach(s=>{if(s.run?.status==='running')s.run.duration_seconds++});liveData.queue.jobs.forEach(j=>j.waiting_seconds++);renderDrawer()},1000);setInterval(refreshServices,3000);refreshServices();
</script></body></html>
