<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alterdata · Integração BI</title>
    @include('admin.partials.favicons')
    @include('admin.partials.theme')
    <style>
        .summary{display:grid;grid-template-columns:repeat(3,minmax(0,180px));gap:12px;margin-bottom:18px}.metric{padding:16px 18px;border:1px solid var(--oktane-border);border-radius:var(--radius-md);background:#fff;box-shadow:var(--shadow-card)}
        .metric span{display:block;color:var(--oktane-muted);font-size:10px;font-weight:650;text-transform:uppercase;letter-spacing:.06em}.metric strong{display:block;margin-top:7px;color:var(--oktane-navy);font-size:23px}
        .panel{position:relative;overflow:hidden;border:1px solid var(--oktane-border);border-radius:var(--radius-lg);background:#fff;box-shadow:var(--shadow-card)}.toolbar{display:flex;gap:10px;padding:14px;border-bottom:1px solid var(--oktane-border)}
        .toolbar .search{flex:1;max-width:420px}.toolbar .filter{width:180px}.count{margin-left:auto;align-self:center;color:var(--oktane-muted);font-size:11px;font-weight:650}
        table{width:100%;border-collapse:collapse}th,td{padding:12px 15px;border-bottom:1px solid var(--oktane-border);text-align:left;font-size:11px}th{color:var(--oktane-muted);font-size:9px;text-transform:uppercase;letter-spacing:.06em}td.name{color:var(--oktane-navy);font-weight:700}
        .pill{display:inline-flex;padding:4px 8px;border-radius:999px;background:var(--oktane-success-bg);color:var(--oktane-success-text);font-size:9px;font-weight:700}.pill.off{background:var(--oktane-muted-bg);color:var(--oktane-muted)}.muted{color:var(--oktane-muted)}.empty{text-align:center;padding:38px!important;color:var(--oktane-muted)}
        .loading-layer{position:absolute;z-index:5;inset:59px 0 0;display:flex;align-items:flex-start;justify-content:center;padding-top:75px;background:rgba(247,248,250,.9);backdrop-filter:blur(2px)}.loading-layer[hidden]{display:none}.loading-box{display:flex;align-items:center;gap:10px;padding:12px 16px;border:1px solid var(--oktane-border);border-radius:11px;background:#fff;box-shadow:var(--shadow-modal);color:var(--oktane-navy);font-size:12px;font-weight:650}
        @media(max-width:800px){.summary{grid-template-columns:1fr}.toolbar{flex-wrap:wrap}.toolbar .search,.toolbar .filter{max-width:none;width:100%}.count{margin-left:0}table{min-width:760px}.panel{overflow:auto}}
    </style>
</head>
<body>
<div class="layout">
    @include('admin.partials.sidebar', ['active' => 'alterdata'])
    <main class="main">
        <header class="top"><div><h1>Alterdata</h1><p class="subtitle">Empresas disponíveis para a integração do Departamento Pessoal.</p></div><div class="actions"><button class="button icon" id="refresh" title="Atualizar" aria-label="Atualizar" type="button">↻</button></div></header>
        <section class="summary" aria-label="Resumo das empresas"><article class="metric"><span>Total</span><strong id="total">—</strong></article><article class="metric"><span>Ativas</span><strong id="active">—</strong></article><article class="metric"><span>Inativas</span><strong id="inactive">—</strong></article></section>
        <section class="panel">
            <div class="toolbar"><input class="search" id="search" placeholder="Buscar por empresa, código ou CNPJ..."><select class="filter" id="status" aria-label="Filtrar empresas por status"><option value="all">Todas</option><option value="active">Ativas</option><option value="inactive">Inativas</option></select><span class="count" id="count">0 empresas</span></div>
            <div class="loading-layer" id="loading" role="status" aria-live="polite"><div class="loading-box"><i class="table-spinner"></i>Carregando empresas...</div></div>
            <table><thead><tr><th>Empresa</th><th>ID Alterdata</th><th>Código externo</th><th>CNPJ/CPF</th><th>Status</th><th>Última consulta</th></tr></thead><tbody id="companies"><tr><td colspan="6" class="empty">Carregando empresas...</td></tr></tbody></table>
        </section>
    </main>
</div>
<script>
const endpoint=@json(route('admin.alterdata.companies'));
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
const formatDate=value=>value?new Intl.DateTimeFormat('pt-BR',{dateStyle:'short',timeStyle:'short'}).format(new Date(String(value).replace(' ','T'))):'—';
let companies=[];
function render(){const query=document.getElementById('search').value.trim().toLowerCase();const status=document.getElementById('status').value;const filtered=companies.filter(company=>{const active=Number(company.ativa)===1;const matchesStatus=status==='all'||(status==='active'&&active)||(status==='inactive'&&!active);const haystack=[company.nome,company.alterdata_id,company.externo_id,company.cpf_cnpj].join(' ').toLowerCase();return matchesStatus&&(!query||haystack.includes(query))});document.getElementById('count').textContent=`${filtered.length} ${filtered.length===1?'empresa':'empresas'}`;document.getElementById('companies').innerHTML=filtered.length?filtered.map(company=>`<tr><td class="name">${esc(company.nome||'Sem nome')}</td><td>${esc(company.alterdata_id)}</td><td>${esc(company.externo_id||'—')}</td><td>${esc(company.cpf_cnpj||'—')}</td><td><span class="pill ${Number(company.ativa)===1?'':'off'}">${Number(company.ativa)===1?'Ativa':'Inativa'}</span></td><td class="muted">${formatDate(company.ultima_consulta_em)}</td></tr>`).join(''):'<tr><td colspan="6" class="empty">Nenhuma empresa encontrada.</td></tr>'}
async function load(){const loading=document.getElementById('loading');const refresh=document.getElementById('refresh');loading.hidden=false;refresh.disabled=true;refresh.classList.add('loading');try{const response=await fetch(`${endpoint}?t=${Date.now()}`,{cache:'no-store',headers:{Accept:'application/json'}});if(!response.ok)throw new Error(`HTTP ${response.status}`);const data=await response.json();companies=data.companies||[];document.getElementById('total').textContent=data.summary?.total??companies.length;document.getElementById('active').textContent=data.summary?.active??0;document.getElementById('inactive').textContent=data.summary?.inactive??0;render()}catch(error){document.getElementById('companies').innerHTML='<tr><td colspan="6" class="empty">Não foi possível carregar as empresas.</td></tr>'}finally{loading.hidden=true;refresh.disabled=false;refresh.classList.remove('loading')}}
document.getElementById('search').addEventListener('input',render);document.getElementById('status').addEventListener('change',render);document.getElementById('refresh').addEventListener('click',load);load();
</script>
</body>
</html>
