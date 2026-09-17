<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WebPosto · Integração BI</title>
    @include('admin.partials.favicons')
    @include('admin.partials.theme')
    <style>
        .credential-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px;margin-bottom:14px;border:1px solid var(--oktane-border);border-radius:var(--radius-md);background:#fff;box-shadow:var(--shadow-card)}
        .credential-toolbar .search{flex:1;min-width:220px;max-width:360px}
        .credential-toolbar .filter{min-width:190px}
        .credentials{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}
        .credential{display:flex;flex-direction:column;padding:16px;border:1px solid var(--oktane-border);border-radius:var(--radius-md);background:#fff;box-shadow:var(--shadow-card);transition:border-color .15s,box-shadow .15s}
        .credential:hover{border-color:#CBD5E1;box-shadow:0 4px 14px rgba(13,17,23,.07)}
        .credential-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid var(--oktane-border)}
        .credential .code{font-weight:700;font-size:14px;color:var(--oktane-navy)}
        .credential .url{margin:4px 0 0;color:var(--oktane-muted);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .credential-action{margin-top:auto;padding-top:14px}
        .initial-progress{height:6px;margin-top:12px;border-radius:999px;background:var(--oktane-canvas);overflow:hidden}
        .initial-progress i{display:block;height:100%;border-radius:999px;background:var(--oktane-orange);transition:width .35s}
        .initial-meta{margin-top:7px;color:var(--oktane-muted);font-size:10px}
        .initial-error{margin-top:7px;color:var(--oktane-danger);font-size:10px;line-height:1.45}
        .credential-submit{margin-top:18px}
        .credentials-empty{grid-column:1/-1;padding:50px 20px;text-align:center;color:var(--oktane-muted);font-size:12px;border:1px dashed var(--oktane-border);border-radius:var(--radius-md)}
        @media(max-width:760px){.credential-toolbar .search{max-width:none}}
    </style>
</head>
<body><div class="layout">
    @include('admin.partials.sidebar', ['active' => 'webposto'])
    <main class="main">@if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
        <header class="top"><div><h1>WebPosto</h1><p class="subtitle">Credenciais, carga inicial e status de sincronização por posto.</p></div><div class="actions"><button class="button icon" id="refresh" title="Atualizar" aria-label="Atualizar" type="button">↻</button><button class="button primary" id="add-credential" type="button">+ Nova credencial</button></div></header>
        <section class="section" style="margin-top:0"><div class="section-head"><div><h2>Postos cadastrados</h2><p id="credential-count" aria-live="polite">0 postos</p></div></div>
            <div class="credential-toolbar"><input class="search" id="credential-search" placeholder="Buscar posto por nome ou código..."><select class="filter" id="credential-base-filter" aria-label="Filtrar postos por base"><option value="all">Todas as bases</option><option value="b1">B1</option><option value="b2">B2</option><option value="chimba">Chimba</option></select><select class="filter" id="credential-status-filter" aria-label="Filtrar postos por status de sincronização"><option value="all">Todos os status</option><option value="sincronizado">Sincronizados</option><option value="aguardando_sincronizacao">Não sincronizados</option></select></div>
            <div class="credentials" id="credentials"></div>
        </section>
        <div class="footer-time" style="margin-top:12px;color:var(--oktane-muted);font-size:11px;text-align:right">Atualizado em <span id="generated-at"></span></div>
    </main></div><div class="modal-overlay {{ $errors->hasAny(['base', 'base_url', 'token']) ? 'open' : '' }}" id="credential-modal"><div class="modal"><div class="modal-head"><div><h2>Cadastrar credencial</h2></div><button class="modal-close" id="credential-close" type="button">&times;</button></div><form method="POST" action="{{ route('admin.credentials.store') }}">@csrf<label>Base<select name="base" required><option value="b1" @selected(old('base') === 'b1')>B1</option><option value="b2" @selected(old('base') === 'b2')>B2</option><option value="chimba" @selected(old('base') === 'chimba')>Chimba</option></select></label>@error('base')<div class="form-error">{{ $message }}</div>@enderror<label>URL do WebPosto<input type="url" name="base_url" required value="{{ old('base_url','https://web.qualityautomacao.com.br/') }}"></label>@error('base_url')<div class="form-error">{{ $message }}</div>@enderror<label>Token<input type="password" name="token" required autocomplete="new-password" placeholder="Token fornecido pelo WebPosto"></label>@error('token')<div class="form-error">{{ $message }}</div>@enderror<button class="button primary credential-submit" type="submit">Validar e cadastrar</button></form></div></div><div class="toast" id="toast">Painel atualizado com sucesso.</div>
<script>
const initial=@json($overview);let state=initial;const syncCompanyUrl=@json(route('admin.credentials.synchronize',['empresa'=>'__empresa__']));const csrf=@json(csrf_token());const date=v=>v?new Intl.DateTimeFormat('pt-BR',{dateStyle:'short',timeStyle:'short'}).format(new Date(v.replace(' ','T'))):'—';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
function onboardingLabel(c){return c.onboarding_status==="sincronizado"?"Sincronizado":"Não sincronizado"}
function credentialCard(c){const run=c.initial_sync;const busy=run&&['queued','running'].includes(run.status);const synchronized=c.onboarding_status==='sincronizado';const total=Number(run?.total_resources||0),position=Number(run?.current_position||0),percent=total?Math.min(100,Math.round(position/total*100)):0;const progress=run?`<div class="initial-progress"><i style="width:${run.status==='success'?100:percent}%"></i></div><div class="initial-meta">${run.status==='queued'?'Aguardando na fila':run.status==='running'?'Sincronizando '+esc(run.current_resource||'dados')+' · '+position+'/'+total:run.status==='failed'?'Carga interrompida':run.status==='success'?'Carga inicial concluída':''}</div>${run.error?`<div class="initial-error">${esc(run.error)}</div>`:''}`:'';const label=busy?'Sincronizando...':synchronized?'Ressincronizar tudo':run?.status==='failed'?'Tentar novamente':'Sincronizar dados';const confirmation=synchronized?' onsubmit="return confirm(\'Esta operação buscará novamente todos os dados deste posto. Deseja continuar?\')"':'';const action=`<form class="credential-action" method="POST" action="${syncCompanyUrl.replace('__empresa__',c.empresa_codigo)}"${confirmation}><input type="hidden" name="_token" value="${csrf}"><button class="button ${synchronized?'':'primary'}" type="submit" ${busy?'disabled':''}>${label}</button></form>`;const baseLabel=c.base==='chimba'?'Chimba':String(c.base||'').toUpperCase();return `<article class="credential"><div class="credential-head"><div><div class="code">${esc(c.empresa_nome)}</div><p class="url">Código ${c.empresa_codigo} · ${esc(baseLabel)}</p></div><span class="pill ${synchronized?'':'off'}">${onboardingLabel(c)}</span></div>${progress}${action}</article>`}
function renderCredentials(){const q=(document.getElementById('credential-search')?.value||'').trim().toLowerCase();const baseFilter=document.getElementById('credential-base-filter')?.value||'all';const statusFilter=document.getElementById('credential-status-filter')?.value||'all';const list=state.credentials.filter(c=>(baseFilter==='all'||c.base===baseFilter)&&(statusFilter==='all'||c.onboarding_status===statusFilter)&&(!q||c.empresa_nome.toLowerCase().includes(q)||String(c.empresa_codigo).includes(q)));const count=list.length;document.getElementById('credential-count').textContent=count+' '+(count===1?'posto':'postos');document.getElementById('credentials').innerHTML=list.length?list.map(credentialCard).join(''):'<div class="credentials-empty">Nenhum posto encontrado para esse filtro.</div>'}
function render(data){state=data;renderCredentials();document.getElementById('generated-at').textContent=date(data.generated_at)}

const refreshButton=document.getElementById('refresh');
let activeRefreshController=null;
let activeRefreshPromise=null;

function refreshOverview({silent=false,force=false}={}){
    if(activeRefreshPromise&&!force)return activeRefreshPromise;
    if(force&&activeRefreshController)activeRefreshController.abort();

    const controller=new AbortController();
    activeRefreshController=controller;
    if(!silent){refreshButton.disabled=true;refreshButton.classList.add('loading')}

    const request=(async()=>{
        try{
            const response=await fetch(@json(route('admin.overview', ['source' => 'webposto'])),{headers:{Accept:'application/json'},cache:'no-store',signal:controller.signal});
            if(!response.ok)throw new Error('Falha ao atualizar o painel.');
            const data=await response.json();
            if(controller.signal.aborted)return false;
            render(data);
            if(!silent){const toast=document.getElementById('toast');toast.classList.add('show');setTimeout(()=>toast.classList.remove('show'),2200)}
            return true;
        }catch(error){
            if(error.name==='AbortError')return false;
            if(!silent)alert('Não foi possível atualizar a página. Verifique sua sessão e as conexões.');
            return false;
        }finally{
            if(activeRefreshController===controller){
                activeRefreshController=null;
                activeRefreshPromise=null;
                if(!silent){refreshButton.disabled=false;refreshButton.classList.remove('loading')}
            }
        }
    })();
    activeRefreshPromise=request;
    return request;
}

document.getElementById('credential-search').addEventListener('input',renderCredentials);
document.getElementById('credential-base-filter').addEventListener('change',renderCredentials);
document.getElementById('credential-status-filter').addEventListener('change',renderCredentials);
refreshButton.addEventListener('click',()=>refreshOverview({force:true}));
setInterval(()=>{if(!document.hidden&&!activeRefreshPromise)refreshOverview({silent:true})},5000);

render(initial);

const credentialModal=document.getElementById('credential-modal');const closeCredential=()=>credentialModal.classList.remove('open');document.getElementById('add-credential').onclick=()=>credentialModal.classList.add('open');document.getElementById('credential-close').onclick=closeCredential;credentialModal.addEventListener('click',e=>{if(e.target===credentialModal)closeCredential()});document.addEventListener('keydown',e=>{if(e.key==='Escape')closeCredential()});
</script></body></html>
