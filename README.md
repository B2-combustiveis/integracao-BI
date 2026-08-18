# Integração WebPosto

API em Laravel para integração com o WebPosto. O ambiente local utiliza Docker Compose com dois serviços:

- `app`: Laravel 13 com PHP 8.3, disponível na porta `8000`.
- `db`: MySQL 8.4, disponível para ferramentas externas na porta `3307`.

Os containers se comunicam pela rede interna do Docker. Por isso, o Laravel acessa o banco usando `db:3306`, enquanto DBeaver e outras ferramentas instaladas no host usam `localhost:3307`.

## Requisitos

- Docker Engine;
- Docker Compose v2;
- Git.

Confira a instalação:

```bash
docker version
docker compose version
```

Não é necessário instalar PHP, Composer ou MySQL diretamente na máquina.

## Configuração inicial

Clone o projeto, entre na pasta e crie o arquivo de ambiente:

```bash
cp .env.example .env
```

Edite o `.env` e configure ao menos:

```env
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=integracao_v1
DB_USERNAME=integracao
DB_PASSWORD=troque_esta_senha
DB_ROOT_PASSWORD=troque_a_senha_root

TMP_API_TOKEN=adicione_um_token_seguro
WEBPOSTO_API_KEY=adicione_a_key_do_webposto
```

Gere um Bearer Token aleatório com:

```bash
openssl rand -hex 32
```

Coloque o resultado em `TMP_API_TOKEN`. Nunca inclua tokens, senhas ou a key real do WebPosto no Git. O arquivo `.env` já está listado no `.gitignore`.

## Subindo o ambiente

Construa e inicie os containers:

```bash
docker compose up --build -d
```

Na primeira inicialização, o container da aplicação executa automaticamente:

1. `composer install`;
2. migrations do Laravel;
3. seeder do banco;
4. servidor HTTP com múltiplos workers.

Gere a chave da aplicação caso `APP_KEY` ainda esteja vazia:

```bash
docker compose exec app php artisan key:generate
```

Reinicie a aplicação para que todos os workers carreguem a chave gerada:

```bash
docker compose restart app
```

Aguarde até o log informar que o servidor PHP iniciou:

```bash
docker compose logs -f app
```

Use `Ctrl+C` para sair da visualização dos logs; isso não encerra os containers.

Confira os containers:

```bash
docker compose ps
```

A aplicação estará disponível em:

```text
http://localhost:8000
```

## Banco de dados

O MySQL cria automaticamente a base configurada em `DB_DATABASE`. As migrations criam as tabelas da aplicação, incluindo `api_tokens`.

O `DatabaseSeeder` lê `TMP_API_TOKEN` do `.env` e cria ou atualiza o registro ativo chamado `integracao_webposto`. O token não fica escrito no código ou na migration.

Valide a conexão por dentro do container:

```bash
docker compose exec app php artisan db:show
```

Consulte os tokens sem exibir seu conteúdo:

```bash
docker compose exec app php artisan tinker --execute="dump(DB::table('api_tokens')->select('id', 'nome', 'ativo')->get());"
```

### Conexão pelo DBeaver

Use uma conexão MySQL com os seguintes dados:

| Campo | Valor local |
|---|---|
| Host | `localhost` |
| Porta | `3307` |
| Database | `integracao_v1` |
| Usuário | valor de `DB_USERNAME` |
| Senha | valor de `DB_PASSWORD` |

URL JDBC:

```text
jdbc:mysql://localhost:3307/integracao_v1?allowPublicKeyRetrieval=true&useSSL=false&sslMode=DISABLED
```

Se o DBeaver mostrar `Public Key Retrieval is not allowed`, abra **Driver properties** e configure:

```text
allowPublicKeyRetrieval=true
sslMode=DISABLED
useSSL=false
```

Essas opções são destinadas somente ao ambiente local. Em produção, configure TLS e não exponha publicamente a porta do MySQL.

## Autenticação da API

As chamadas autenticadas enviam o token no header HTTP:

```http
Authorization: Bearer SEU_TOKEN
Accept: application/json
```

Há dois mecanismos temporários durante o desenvolvimento:

- `auth.token`: compara o Bearer Token com `TMP_API_TOKEN`.
- `auth.database-token`: consulta a tabela `api_tokens` e exige que o registro esteja ativo.

O mecanismo baseado em banco retorna:

- `200 OK`: token válido e ativo;
- `401 Unauthorized`: token ausente, inválido ou inativo;
- `503 Service Unavailable`: banco indisponível.

## Rotas de diagnóstico

### Teste da aplicação e do banco

```http
GET /api/teste
```

Exemplo:

```bash
curl --max-time 10 \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Accept: application/json" \
  http://localhost:8000/api/teste
```

A resposta informa o estado da requisição e da conexão MySQL, sem expor credenciais.

### Verificação do token no banco

```http
GET /api/verificar-token
```

Exemplo:

```bash
curl --max-time 10 \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Accept: application/json" \
  http://localhost:8000/api/verificar-token
```

Resposta esperada:

```json
{
  "status": true,
  "token_status": "valid",
  "authenticated": true,
  "token": {
    "id": 1,
    "name": "integracao_webposto"
  },
  "verified_at": "2026-08-18T15:24:54+00:00"
}
```

O valor completo do token nunca é devolvido pela API.

## Validação da instalação

Depois da configuração inicial, execute estas verificações.

Os dois serviços devem aparecer como ativos e o banco deve estar saudável:

```bash
docker compose ps
```

Confira a conexão Laravel/MySQL:

```bash
docker compose exec app php artisan db:show
```

Confira a rota autenticada pelo token salvo no banco:

```bash
curl --fail --max-time 10 \
  -H "Authorization: Bearer SEU_TOKEN" \
  -H "Accept: application/json" \
  http://localhost:8000/api/verificar-token
```

A instalação está pronta quando a chamada retorna HTTP `200` com `"token_status":"valid"`.

Se a aplicação ainda estiver inicializando, acompanhe:

```bash
docker compose logs --tail=100 app db
```

## Comandos úteis

Recriar somente a aplicação após alterar o Compose:

```bash
docker compose up -d --force-recreate app
```

Reconstruir a imagem após alterar o Dockerfile:

```bash
docker compose build app
docker compose up -d --force-recreate app
```

Executar os testes:

```bash
docker compose exec app php artisan test
```

Parar os containers sem apagar o banco:

```bash
docker compose down
```

Ver as últimas linhas de log:

```bash
docker compose logs --tail=100 app db
```

> Não execute `docker compose down -v` se quiser preservar os dados. A opção `-v` remove os volumes, incluindo o volume do MySQL.

## Integração com o WebPosto

A chave fornecida pelo WebPosto deve ser definida somente no `.env`:

```env
WEBPOSTO_API_KEY=sua_key_real
```

Ela é disponibilizada à aplicação por `config/integration.php`. Os endpoints e serviços responsáveis pelas chamadas ao WebPosto serão implementados nas próximas etapas.

## Segurança

- Não versione `.env` ou arquivos de backup contendo segredos.
- Troque as senhas de exemplo antes de publicar ou implantar o projeto.
- Não registre Bearer Tokens ou a key do WebPosto nos logs.
- O armazenamento atual do token em texto é temporário. Antes da produção, migre para hash criptográfico e implemente rotação e expiração.
- Não exponha a porta `3307` em produção; mantenha o MySQL acessível apenas pela rede interna.
