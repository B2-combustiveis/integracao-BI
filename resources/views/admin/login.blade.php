<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acesso · Integração BI</title>
    @include('admin.partials.favicons')
    @include('admin.partials.theme')
    <style>
        body{min-height:100vh;display:grid;place-items:center;padding:24px;background-image:radial-gradient(circle at 18% 10%,rgba(255,122,0,.07) 0,transparent 34%),radial-gradient(circle at 92% 84%,rgba(18,58,109,.08) 0,transparent 30%)}
        .shell{width:min(100%,440px)}
        .login-brand{display:flex;align-items:center;gap:12px;margin-bottom:30px}
        .login-brand img{height:40px;width:auto}
        .card{padding:34px;border:1px solid var(--oktane-border);border-radius:var(--radius-lg);background:rgba(255,255,255,.92);box-shadow:0 28px 80px rgba(13,17,23,.12);backdrop-filter:blur(18px)}
        .card h1{margin:0 0 9px;font-size:26px;letter-spacing:-.03em;color:var(--oktane-navy)}
        .lead{margin:0 0 27px;color:var(--oktane-muted);font-size:14px;line-height:1.55}
        label{display:block;margin-bottom:9px;color:var(--oktane-text);font-size:13px;font-weight:650}
        .field{position:relative}
        .field input{width:100%;padding:14px 46px 14px 15px}
        .toggle-visibility{position:absolute;right:8px;top:6px;bottom:6px;padding:0 9px;border:0;background:none;color:var(--oktane-muted);cursor:pointer}
        .error{margin:9px 0 0;color:var(--oktane-danger);font-size:12px}
        button.submit{width:100%;margin-top:20px;padding:14px;border:0;border-radius:var(--radius-sm);background:var(--oktane-navy);color:#fff;font-weight:700;cursor:pointer;transition:.2s;font-size:14px}
        button.submit:hover{background:#1a2433;transform:translateY(-1px)}
        .help{margin:19px 0 0;text-align:center;color:var(--oktane-muted);font-size:12px}
        .help code{padding:2px 5px;border-radius:5px;background:var(--oktane-canvas);color:var(--oktane-text)}
    </style>
</head>
<body><main class="shell">
    <div class="login-brand"><img src="{{ asset('brand/oktane-logo.svg') }}" alt="Oktane"></div>
    <section class="card"><h1>Bem-vindo de volta</h1><p class="lead">Use um token ativo da nossa API para acessar os dados e diagnósticos locais. Nenhuma consulta externa será executada.</p>
        @if(session('error'))<div class="flash" style="border-color:#FCA5A5;background:var(--oktane-danger-bg);color:var(--oktane-danger-text)">{{ session('error') }}</div>@endif
        <form method="POST" action="{{ route('admin.login.store') }}">@csrf
            <label for="token">Token de acesso</label><div class="field"><input id="token" name="token" type="password" value="{{ old('token') }}" autocomplete="current-password" autofocus placeholder="Cole o token da tabela api_tokens"><button class="toggle-visibility" type="button" aria-label="Mostrar token" onclick="const i=document.getElementById('token');i.type=i.type==='password'?'text':'password'">◉</button></div>
            @error('token')<p class="error">{{ $message }}</p>@enderror
            <button class="submit" type="submit">Entrar no painel</button>
        </form><p class="help">Acesso validado localmente em <code>api_tokens</code></p>
    </section>
</main></body></html>
