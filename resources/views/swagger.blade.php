<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Integração API</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui.css">
    <style>
        html { box-sizing: border-box; overflow-y: scroll; }
        *, *::before, *::after { box-sizing: inherit; }
        :root { color-scheme: dark; }
        body { margin: 0; background: #0f172a; }
        .swagger-ui { color: #e2e8f0; }
        .swagger-ui .info .title,
        .swagger-ui .info p,
        .swagger-ui .info li,
        .swagger-ui .info table,
        .swagger-ui .scheme-container label,
        .swagger-ui .opblock-tag,
        .swagger-ui .opblock-description-wrapper p,
        .swagger-ui .opblock-external-docs-wrapper p,
        .swagger-ui .opblock-title_normal p,
        .swagger-ui .response-col_status,
        .swagger-ui .response-col_description,
        .swagger-ui .parameter__name,
        .swagger-ui .parameter__type,
        .swagger-ui .parameter__in,
        .swagger-ui .tab li,
        .swagger-ui label,
        .swagger-ui h1,
        .swagger-ui h2,
        .swagger-ui h3,
        .swagger-ui h4,
        .swagger-ui h5 { color: #e2e8f0; }
        .swagger-ui .info a,
        .swagger-ui .opblock-tag:hover { color: #60a5fa; }
        .swagger-ui .scheme-container,
        .swagger-ui section.models,
        .swagger-ui .model-container,
        .swagger-ui .responses-inner,
        .swagger-ui .opblock-body { background: #111827; }
        .swagger-ui .scheme-container { box-shadow: 0 1px 3px rgb(0 0 0 / 45%); }
        .swagger-ui section.models,
        .swagger-ui .model-container { border-color: #334155; }
        .swagger-ui section.models h4,
        .swagger-ui section.models .model-title,
        .swagger-ui .model,
        .swagger-ui .model-title { color: #e2e8f0; }
        .swagger-ui input[type=text],
        .swagger-ui input[type=password],
        .swagger-ui input[type=search],
        .swagger-ui textarea,
        .swagger-ui select {
            color: #f8fafc;
            background: #1e293b;
            border-color: #475569;
        }
        .swagger-ui textarea.curl,
        .swagger-ui .highlight-code,
        .swagger-ui .microlight { color: #e2e8f0; background: #020617 !important; }
        .swagger-ui .dialog-ux .modal-ux { color: #e2e8f0; background: #111827; border-color: #475569; }
        .swagger-ui .dialog-ux .modal-ux-header { border-color: #334155; }
        .swagger-ui .dialog-ux .modal-ux-header h3 { color: #f8fafc; }
        .swagger-ui .auth-container { border-color: #334155; }
        .swagger-ui .btn { color: #e2e8f0; border-color: #64748b; }
        .swagger-ui svg { fill: currentColor; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-bundle.js" crossorigin></script>
    <script>
        window.onload = () => {
            window.ui = SwaggerUIBundle({
                url: @json(route('docs.openapi')),
                dom_id: '#swagger-ui',
                deepLinking: true,
                persistAuthorization: true,
                displayRequestDuration: true,
                filter: true,
                tryItOutEnabled: true,
                validatorUrl: null,
                presets: [SwaggerUIBundle.presets.apis],
                layout: 'BaseLayout',
            });
        };
    </script>
</body>
</html>
