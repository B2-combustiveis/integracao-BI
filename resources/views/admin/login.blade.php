<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acesso · Integração BI</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#07110f;color:#edf7f3;font-family:Inter,ui-sans-serif,system-ui,sans-serif;background-image:radial-gradient(circle at 18% 10%,#153f35 0,transparent 34%),radial-gradient(circle at 92% 84%,#17302b 0,transparent 30%)}
        .shell{width:min(100%,440px)}.brand{display:flex;align-items:center;gap:12px;margin-bottom:28px;color:#b8d6cd}.mark{display:grid;place-items:center;width:42px;height:42px;border:1px solid #2b5d50;border-radius:13px;background:#10231e;color:#65d6ae}.brand strong{display:block;color:#f5fbf8;font-size:15px}.brand span{font-size:12px;letter-spacing:.08em;text-transform:uppercase}
        .card{padding:34px;border:1px solid #28483f;border-radius:22px;background:rgba(11,27,23,.9);box-shadow:0 28px 80px #0008;backdrop-filter:blur(18px)}h1{margin:0 0 9px;font-size:27px;letter-spacing:-.04em}.lead{margin:0 0 27px;color:#8faea5;font-size:14px;line-height:1.55}label{display:block;margin-bottom:9px;color:#d4e6e0;font-size:13px;font-weight:650}.field{position:relative}input{width:100%;padding:14px 46px 14px 15px;border:1px solid #31564c;border-radius:12px;background:#081713;color:#fff;outline:none;font:inherit}input:focus{border-color:#5bd4aa;box-shadow:0 0 0 3px #4fd1a31f}.toggle{position:absolute;right:10px;bottom:8px;padding:7px;border:0;background:none;color:#8fb0a6;cursor:pointer}.error{margin:9px 0 0;color:#ff9d94;font-size:12px}.flash{margin:0 0 18px;padding:11px 13px;border:1px solid #713f3c;border-radius:10px;background:#351918;color:#ffb7b0;font-size:13px}button.submit{width:100%;margin-top:20px;padding:14px;border:0;border-radius:12px;background:#5dd6ad;color:#062219;font-weight:800;cursor:pointer;transition:.2s}button.submit:hover{background:#78e6bf;transform:translateY(-1px)}.help{margin:19px 0 0;text-align:center;color:#78968d;font-size:12px}.help code{color:#b5d4ca}
    </style>
</head>
<body><main class="shell">
    <div class="brand"><div class="mark">◆</div><div><strong>Integração BI</strong></div></div>
    <section class="card"><h1>Bem-vindo de volta</h1><p class="lead">Use um token ativo da nossa API para acessar os dados e diagnósticos locais. Nenhuma consulta externa será executada.</p>
        @if(session('error'))<div class="flash">{{ session('error') }}</div>@endif
        <form method="POST" action="{{ route('admin.login.store') }}">@csrf
            <label for="token">Token de acesso</label><div class="field"><input id="token" name="token" type="password" value="{{ old('token') }}" autocomplete="current-password" autofocus placeholder="Cole o token da tabela api_tokens"><button class="toggle" type="button" aria-label="Mostrar token" onclick="const i=document.getElementById('token');i.type=i.type==='password'?'text':'password'">◉</button></div>
            @error('token')<p class="error">{{ $message }}</p>@enderror
            <button class="submit" type="submit">Entrar no painel</button>
        </form><p class="help">Acesso validado localmente em <code>api_tokens</code></p>
    </section>
</main></body></html>
