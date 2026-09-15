<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Painel de controle · Integração BI</title>
    @include('admin.partials.favicons')
    @include('admin.partials.theme')
    <style>
        .cards{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:12px;margin-bottom:26px}
        .metric{padding:18px;border:1px solid var(--oktane-border);border-radius:var(--radius-lg);background:#fff;box-shadow:var(--shadow-card)}
        .metric span{display:block;color:var(--oktane-muted);font-size:11px;text-transform:uppercase;letter-spacing:.08em}
        .metric strong{display:block;margin-top:9px;font-size:26px;letter-spacing:-.03em;color:var(--oktane-navy)}
        .connections{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
        .connection{padding:16px;border:1px solid var(--oktane-border);border-radius:var(--radius-md);background:#fff;box-shadow:var(--shadow-card)}
        .connection-top{display:flex;justify-content:space-between}
        .connection strong{font-size:14px;color:var(--oktane-navy)}
        .connection dl{display:grid;grid-template-columns:1fr auto;gap:7px;margin:15px 0 0;color:var(--oktane-muted);font-size:11px}
        .connection dd{margin:0;color:var(--oktane-text)}
        .table-panel{position:relative;border:1px solid var(--oktane-border);border-radius:var(--radius-lg);overflow:hidden;background:#fff;box-shadow:var(--shadow-card)}
        .toolbar{display:flex;gap:10px;padding:13px;border-bottom:1px solid var(--oktane-border)}
        .search{flex:1;max-width:360px}
        td.name{color:var(--oktane-navy);font-family:ui-monospace,SFMono-Regular,monospace}
        .empty{color:var(--oktane-warning-text)}
        .footer-time{margin-top:12px;color:var(--oktane-muted);font-size:11px;text-align:right}
        .table-loading{position:absolute;z-index:8;inset:65px 0 0;display:flex;align-items:flex-start;justify-content:center;padding-top:70px;background:rgba(247,248,250,.9);backdrop-filter:blur(2px);opacity:0;visibility:hidden;pointer-events:none;transition:opacity .16s,visibility 0s linear .16s}
        .table-loading.active{opacity:1;visibility:visible;pointer-events:auto;transition-delay:0s}
        .table-loading-content{display:flex;align-items:center;gap:11px;padding:12px 16px;border:1px solid var(--oktane-border);border-radius:11px;background:#fff;box-shadow:var(--shadow-modal);color:var(--oktane-navy);font-size:12px;font-weight:650}
        .table-spinner{width:17px;height:17px;border:2px solid rgba(255,122,0,.2);border-top-color:var(--oktane-orange);border-radius:50%;animation:spin .7s linear infinite}
        @media(max-width:1050px){.cards{grid-template-columns:repeat(3,1fr)}}
        @media(max-width:760px){.cards{grid-template-columns:repeat(2,1fr)}.connections{grid-template-columns:1fr}.table-panel{overflow:auto}table{min-width:680px}}
        .toolbar .button{margin-left:auto;white-space:nowrap}
        .sync-badge{display:inline-block;margin-left:8px;padding:3px 7px;border-radius:999px;background:var(--oktane-info-bg);color:var(--oktane-info-text);font:9px Inter,ui-sans-serif,system-ui,sans-serif;text-transform:uppercase;letter-spacing:.06em}
        .reload-button{padding:6px 9px;font-size:10px}
        .reload-state{display:block;margin-top:5px;color:var(--oktane-warning-text);font-size:9px;max-width:240px}
        .reload-state.failed{color:var(--oktane-danger)}
        .source-picker{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:16px 18px;margin-bottom:24px;border:1px solid var(--oktane-border);border-radius:var(--radius-lg);background:#fff;box-shadow:var(--shadow-card)}
        .source-picker strong{display:block;color:var(--oktane-navy);font-size:13px}.source-picker p{margin:3px 0 0;color:var(--oktane-muted);font-size:11px}
        .source-options{display:flex;gap:8px}.source-button{min-width:116px}.source-button.active{border-color:var(--oktane-orange);background:var(--oktane-orange);color:#fff}.source-button.active:hover{border-color:var(--oktane-orange-dark);background:var(--oktane-orange-dark);color:#fff}.source-button.loading{cursor:wait}
        .overview-content{position:relative}.overview-content[hidden]{display:none}.overview-loading{position:absolute;z-index:12;inset:0;display:none;align-items:flex-start;justify-content:center;padding-top:90px;background:rgba(247,248,250,.88);backdrop-filter:blur(2px);border-radius:var(--radius-lg)}
        .overview-loading.active{display:flex}.overview-placeholder{padding:54px 20px;border:1px dashed var(--oktane-border);border-radius:var(--radius-lg);background:#fff;text-align:center;color:var(--oktane-muted);font-size:12px}
        .source-only[hidden]{display:none}
        @media(max-width:760px){.source-picker{align-items:stretch;flex-direction:column}.source-options{width:100%}.source-button{flex:1;min-width:0}}
    </style>
</head>
<body><div class="layout">
    @include('admin.partials.sidebar', ['active' => 'dashboard'])
    <main class="main">@if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
        <header class="top"><div><h1>Visão geral</h1></div><div class="actions"><button class="button primary icon" id="refresh" title="Atualizar" aria-label="Atualizar" disabled>↻</button></div></header>
        <section class="source-picker" aria-label="Selecionar fonte da integração"><div><strong>Fonte da integração</strong></div><div class="source-options"><button class="button source-button" type="button" data-source="webposto">WebPosto</button><button class="button source-button" type="button" data-source="alterdata">Alterdata</button></div></section>
        <div class="overview-placeholder" id="overview-placeholder">Selecione WebPosto ou Alterdata para carregar o painel.</div>
        <div class="overview-content" id="overview-content" hidden>
            <div class="overview-loading" id="overview-loading" role="status" aria-live="polite"><div class="table-loading-content"><i class="table-spinner"></i><span id="overview-loading-text">Carregando integração...</span></div></div>
            <section class="cards" id="metrics"></section>
            <section class="section"><div class="section-head"><div><h2>Conexões</h2></div></div><div class="connections" id="connections"></div></section>
            <section class="section"><div class="section-head"><div><h2 id="tables-title">Tabelas sincronizadas</h2></div></div><div class="table-panel"><div class="toolbar"><input class="search" id="search" placeholder="Buscar tabela..."><select class="filter source-only" id="reload-company" aria-label="Empresa para recarga"></select><select class="filter source-only" id="filter"><option value="all">Todas</option><option value="new-records-sync">Dados novos</option></select><button class="button" id="refresh-tables" type="button">&#8635; Atualizar tabelas</button></div><div class="table-loading" id="table-loading" role="status" aria-live="polite"><div class="table-loading-content"><i class="table-spinner"></i><span id="table-loading-text">Carregando dados...</span></div></div><table><thead><tr><th>Tabela</th><th>Registros</th><th>Colunas</th><th>Tamanho</th><th>Última atualização</th><th>Ações</th></tr></thead><tbody id="table-body"></tbody></table></div><div class="footer-time">Atualizado em <span id="generated-at"></span></div></section>
        </div>
    </main></div><div class="modal-overlay" id="reload-modal"><div class="modal"><div class="modal-head"><div><h2>Confirmar rebase</h2><p id="reload-modal-message"></p></div><button class="modal-close" id="reload-modal-close" type="button">&times;</button></div><div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px"><button class="button" id="reload-cancel" type="button">Não, cancelar</button><button class="button primary" id="reload-confirm" type="button">Sim, fazer rebase</button></div></div></div><div class="toast" id="toast">Painel atualizado com sucesso.</div>
<script>
let state={credentials:[],tables:[],reloads:[]};let selectedSource=null;const fmt=new Intl.NumberFormat('pt-BR');const date=v=>v?new Intl.DateTimeFormat('pt-BR',{dateStyle:'short',timeStyle:'short'}).format(new Date(v.replace(' ','T'))):'—';const bytes=v=>v<1024?v+' B':v<1048576?(v/1024).toFixed(1)+' KB':(v/1048576).toFixed(1)+' MB';
const metricLabels={webposto:{base_1:'Postos B1',base_2:'Postos B2',tables:'Tabelas WebPosto',api_tokens:'Tokens WebPosto'},alterdata:{companies:'Empresas Alterdata',employees:'Colaboradores',tables:'Tabelas Alterdata',api_tokens:'Tokens Alterdata'}};
function render(data){state=data;const labels=metricLabels[data.source]||{};document.getElementById('metrics').innerHTML=Object.entries(labels).map(([key,label])=>`<div class="metric"><span>${label}</span><strong>${fmt.format(data.summary[key]??0)}</strong></div>`).join('');document.getElementById('connections').innerHTML=data.connections.map(c=>`<article class="connection ${c.status==='offline'?'offline':''}"><div class="connection-top"><strong>${c.label}</strong><span class="status"><i class="dot"></i>${c.status==='online'?'Online':'Offline'}</span></div><dl><dt>Banco</dt><dd>${c.database??'—'}</dd><dt>Latência</dt><dd>${c.latency_ms===null?'—':c.latency_ms+' ms'}</dd></dl></article>`).join('');document.getElementById('tables-title').textContent='Tabelas sincronizadas · '+(data.source==='alterdata'?'Alterdata':'WebPosto');document.getElementById('generated-at').textContent=date(data.generated_at);renderTables()}
const reloadUrl=@json(route('admin.tables.reload',['table'=>'__table__']));const csrf=@json(csrf_token());const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));function renderTables(){const company=document.getElementById('reload-company');const filter=document.getElementById('filter');const isWebPosto=selectedSource==='webposto';company.hidden=!isWebPosto;filter.hidden=!isWebPosto;const previous=company.value;const orderedCredentials=[...(state.credentials||[])].sort((a,b)=>Number(b.onboarding_status==='sincronizado')-Number(a.onboarding_status==='sincronizado'));company.innerHTML=orderedCredentials.map(c=>`<option value="${c.empresa_codigo}">${esc(c.empresa_nome)} · ${c.empresa_codigo}${c.onboarding_status==='sincronizado'?'':' (não sincronizado)'}</option>`).join('')||'<option value="">Nenhum posto cadastrado</option>';if(previous&&[...company.options].some(o=>o.value===previous))company.value=previous;const empresa=Number(company.value);const q=document.getElementById('search').value.toLowerCase();const f=filter.value;const rows=(state.tables||[]).filter(t=>t.name.toLowerCase().includes(q)&&(!isWebPosto||f==='all'||(f==='new-records-sync'&&t.new_records_sync)));document.getElementById('table-body').innerHTML=rows.map(t=>{const allowed=isWebPosto&&['venda_itens','abastecimentos','bombas','bicos','tanques','cartoes','administradoras','produto_grupos','produto_subgrupos','produtos','produto_empresas','produto_lmc_lmp','lmcs','vales_funcionario','funcionario_funcoes','caixas','caixas_apresentados','planos_conta_gerencial','planos_conta_contabil','contas_bancarias','movimentos_conta','funcionarios','estoque_periodos','fornecedores','compras','compra_itens','titulos_pagar','cliente_grupos','clientes','cliente_empresas','formas_pagamento','pdvs','vendas','venda_formas_pagamento','titulos_receber'].includes(t.name);const run=(state.reloads||[]).find(r=>r.empresa_codigo===empresa&&r.resource===t.name);const busy=run&&['queued','running'].includes(run.status);const labels={queued:'Aguardando',running:'Executando',success:'Concluída',failed:'Falhou'};const action=allowed?`<form method="POST" action="${reloadUrl.replace('__table__',t.name)}" data-reload="${t.name}"><input type="hidden" name="_token" value="${csrf}"><input type="hidden" name="empresa_codigo" value="${empresa}"><button class="button reload-button" type="submit" ${busy||!empresa?'disabled':''}>${busy?'Recarga em andamento':'Recarregar dados'}</button>${run?`<span class="reload-state ${run.status==='failed'?'failed':''}">${labels[run.status]||run.status} · ${date(run.finished_at||run.started_at)}${run.error?' · '+esc(run.error):''}</span>`:''}</form>`:'—';return `<tr><td class="name">${t.name}${allowed?'<span class="sync-badge">Carga validada</span>':''}${t.new_records_sync?'<span class="sync-badge">Dados novos</span>':''}</td><td class="${t.records===0?'empty':''}">${fmt.format(t.records)}</td><td>${fmt.format(t.columns)}</td><td>${bytes(t.size_bytes)}</td><td class="muted">${date(t.last_update)}</td><td>${action}</td></tr>`}).join('')||'<tr><td colspan="6" class="muted">Nenhuma tabela encontrada.</td></tr>'}
const companySelect=document.getElementById('reload-company');
const tableLoading=document.getElementById('table-loading');
const tableLoadingText=document.getElementById('table-loading-text');
const overviewContent=document.getElementById('overview-content');
const overviewPlaceholder=document.getElementById('overview-placeholder');
const overviewLoading=document.getElementById('overview-loading');
const overviewLoadingText=document.getElementById('overview-loading-text');
const refreshButton=document.getElementById('refresh');
const refreshTablesButton=document.getElementById('refresh-tables');
let activeRefreshController=null;
let activeRefreshPromise=null;

function setTableLoading(active,message='Carregando dados do posto...'){
    tableLoadingText.textContent=message;
    tableLoading.classList.toggle('active',active);
    tableLoading.setAttribute('aria-busy',active?'true':'false');
    companySelect.disabled=active;
    document.getElementById('filter').disabled=active;
    refreshTablesButton.disabled=active;
}

function setOverviewLoading(active,message='Carregando integração...'){
    overviewLoadingText.textContent=message;
    overviewLoading.classList.toggle('active',active);
    document.querySelectorAll('.source-button').forEach(button=>{
        button.disabled=active;
        button.classList.toggle('loading',active&&button.dataset.source===selectedSource);
    });
}

function selectedCompany(){
    const value=companySelect.value;
    return value?Number(value):null;
}

function refreshOverview({silent=false,company=selectedCompany(),force=false,tableFeedback=false}={}){
    if(!selectedSource)return Promise.resolve(false);
    if(activeRefreshPromise&&!force)return activeRefreshPromise;
    if(force&&activeRefreshController)activeRefreshController.abort();

    const controller=new AbortController();
    const startedAt=Date.now();
    activeRefreshController=controller;
    if(tableFeedback)setTableLoading(true);
    if(!state.source)setOverviewLoading(true,`Carregando ${selectedSource==='alterdata'?'Alterdata':'WebPosto'}...`);
    if(!silent){refreshButton.disabled=true;refreshButton.classList.add('loading')}

    const request=(async()=>{
        let failed=false;
        try{
            const overviewUrl=new URL(@json(route('admin.overview')),window.location.origin);
            overviewUrl.searchParams.set('source',selectedSource);
            if(selectedSource==='webposto'&&company)overviewUrl.searchParams.set('empresa_codigo',company);
            const response=await fetch(overviewUrl,{headers:{Accept:'application/json'},cache:'no-store',signal:controller.signal});
            if(!response.ok)throw new Error('Falha ao atualizar o painel.');
            const data=await response.json();
            if(controller.signal.aborted)return false;
            render(data);
            if(!silent){const toast=document.getElementById('toast');toast.classList.add('show');setTimeout(()=>toast.classList.remove('show'),2200)}
            return true;
        }catch(error){
            if(error.name==='AbortError')return false;
            failed=true;
            if(tableFeedback)setTableLoading(true,'Não foi possível carregar os dados do posto.');
            else if(!silent)alert('Não foi possível atualizar o painel. Verifique sua sessão e as conexões.');
            return false;
        }finally{
            if(activeRefreshController===controller){
                const minimum=tableFeedback?500:0;
                const remaining=Math.max(0,minimum-(Date.now()-startedAt));
                if(remaining)await new Promise(resolve=>setTimeout(resolve,remaining));
                if(failed&&tableFeedback)await new Promise(resolve=>setTimeout(resolve,1300));
                activeRefreshController=null;
                activeRefreshPromise=null;
                setOverviewLoading(false);
                if(tableFeedback)setTableLoading(false);
                refreshButton.disabled=false;refreshButton.classList.remove('loading')
            }
        }
    })();
    activeRefreshPromise=request;
    return request;
}

document.getElementById('search').addEventListener('input',renderTables);
document.getElementById('filter').addEventListener('change',renderTables);
refreshButton.addEventListener('click',()=>refreshOverview({force:true,tableFeedback:true}));
refreshTablesButton.addEventListener('click',()=>refreshOverview({force:true,tableFeedback:true}));
companySelect.addEventListener('change',()=>refreshOverview({company:selectedCompany(),force:true,tableFeedback:true}));
document.querySelectorAll('.source-button').forEach(button=>button.addEventListener('click',()=>{
    const source=button.dataset.source;
    if(!source||source===selectedSource)return;
    if(activeRefreshController)activeRefreshController.abort();
    selectedSource=source;
    state={credentials:[],tables:[],reloads:[]};
    document.querySelectorAll('.source-button').forEach(item=>item.classList.toggle('active',item===button));
    overviewPlaceholder.hidden=true;
    overviewContent.hidden=false;
    refreshButton.disabled=true;
    document.getElementById('metrics').innerHTML='';
    document.getElementById('connections').innerHTML='';
    document.getElementById('table-body').innerHTML='';
    void refreshOverview({force:true});
}));
setInterval(()=>{if(selectedSource&&!document.hidden&&!activeRefreshPromise)refreshOverview({silent:true})},5000);

const reloadModal=document.getElementById('reload-modal'),reloadMessage=document.getElementById('reload-modal-message'),reloadConfirm=document.getElementById('reload-confirm');let pendingReload=null;const closeReload=()=>{reloadModal.classList.remove('open');pendingReload=null;reloadConfirm.disabled=false};document.getElementById('table-body').addEventListener('submit',e=>{const form=e.target.closest('[data-reload]');if(!form)return;e.preventDefault();const table=form.dataset.reload,company=document.getElementById('reload-company').selectedOptions[0]?.textContent||'empresa selecionada';const effects={venda_itens:'venda_itens e abastecimentos',abastecimentos:'abastecimentos',bombas:'bombas e bicos',bicos:'bicos',tanques:'tanques e bicos',cartoes:'cartoes',administradoras:'administradoras e cartões',produto_grupos:'grupos de produtos',produto_subgrupos:'subgrupos de produtos',produtos:'produtos',produto_empresas:'produtos por empresa',produto_lmc_lmp:'produtos LMC/LMP',lmcs:'livros de movimentacao de combustiveis',vales_funcionario:'vales de funcionarios',funcionario_funcoes:'funcoes de funcionarios',caixas:'caixas e caixas apresentados',caixas_apresentados:'caixas apresentados',planos_conta_gerencial:'planos de conta gerenciais',planos_conta_contabil:'planos de conta contabeis',contas_bancarias:'contas bancarias e movimentos de conta',movimentos_conta:'movimentos de conta',funcionarios:'funcionarios',estoque_periodos:'estoque por periodo',fornecedores:'fornecedores',compras:'compras',compra_itens:'itens de compra',titulos_pagar:'títulos a pagar',cliente_grupos:'grupos de clientes',clientes:'clientes e vínculos de empresa',cliente_empresas:'vínculos de clientes com empresas',formas_pagamento:'formas de pagamento',pdvs:'pontos de venda',vendas:'vendas, formas de pagamento, itens, abastecimentos e cartões',venda_formas_pagamento:'formas de pagamento das vendas',titulos_receber:'títulos a receber'};reloadMessage.textContent='Esta operação removerá os registros atuais e reconstruirá '+(effects[table]||table)+' para '+company+'. Deseja realmente continuar?';pendingReload=form;reloadModal.classList.add('open')});document.getElementById('reload-cancel').onclick=closeReload;document.getElementById('reload-modal-close').onclick=closeReload;reloadModal.addEventListener('click',e=>{if(e.target===reloadModal)closeReload()});reloadConfirm.onclick=()=>{if(!pendingReload)return;reloadConfirm.disabled=true;reloadConfirm.textContent='Enviando...';pendingReload.submit()};
</script></body></html>
