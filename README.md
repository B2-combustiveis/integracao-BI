# Integração API

API em Laravel para centralizar integrações com serviços externos. O WebPosto é a primeira integração disponível. O ambiente local utiliza Docker Compose com dois serviços:

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

DB_WEBPOSTO_HOST=db
DB_WEBPOSTO_PORT=3306
DB_WEBPOSTO_DATABASE=webposto
DB_WEBPOSTO_USERNAME=integracao
DB_WEBPOSTO_PASSWORD=troque_esta_senha

DB_BI_HOST=db
DB_BI_PORT=3306
DB_BI_DATABASE=bi
DB_BI_USERNAME=integracao
DB_BI_PASSWORD=troque_esta_senha
```

Gere um Bearer Token aleatório com:

```bash
openssl rand -hex 32
```

Guarde o resultado para cadastrá-lo na tabela `api_tokens` depois que os containers estiverem ativos. Nunca inclua tokens, senhas ou a key real do WebPosto no Git. O arquivo `.env` já está listado no `.gitignore`.

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

A documentação interativa Swagger estará disponível em:

```text
http://localhost:8000/docs
```

Use o botão **Authorize** para informar o Bearer Token e executar as rotas diretamente pela documentação. A especificação OpenAPI pode ser consumida separadamente em `http://localhost:8000/docs/openapi`.

## Banco de dados

O MySQL cria automaticamente a base configurada em `DB_DATABASE`. As migrations criam as tabelas da aplicação, incluindo `api_tokens`.

O mesmo container também cria duas bases separadas: `webposto`, usada para armazenar os dados brutos recebidos da API externa, e `bi`, destinada aos dados tratados que serão consumidos pela ferramenta analítica. O Laravel acessa essas bases pelas conexões correspondentes:

```php
DB::connection('webposto')->table('nome_da_tabela');
DB::connection('bi')->table('nome_da_tabela');
```

Em instalações novas, o script `docker/mysql/init/01-create-webposto.sh` cria as duas bases e concede ao usuário da aplicação acesso a elas. Esses scripts são executados pelo MySQL somente quando o volume é inicializado pela primeira vez.

Os tokens da nossa API são administrados diretamente na tabela `api_tokens`; nenhuma variável do `.env` cria ou sobrescreve esses registros. Cadastre o primeiro token com:

```sql
INSERT INTO api_tokens (nome, token, ativo, created_at, updated_at)
VALUES ('integracao_principal', 'COLE_O_TOKEN_GERADO', 1, NOW(), NOW());
```

As credenciais externas do WebPosto ficam na tabela `webposto.webposto_credentials`, vinculadas ao `empresaCodigo`. A URL e o token não ficam no `.env`, e o token é criptografado com a `APP_KEY`. Cadastre ou substitua uma credencial pelo comando interativo (o token não aparece no terminal):

```bash
docker compose exec app php artisan webposto:credential 4604
```

O comando usa `https://web.qualityautomacao.com.br/` como URL padrão. Para outra instalação, informe `--url=https://endereco-do-webposto/`.

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

Para consultar os dados importados ou tratados, crie conexões no DBeaver com o mesmo host, porta, usuário e senha, alterando apenas o database para `webposto` ou `bi`.

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

Todas as rotas autenticadas usam `auth.database-token`, que consulta a tabela `api_tokens` e exige que o registro esteja ativo.

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

URL e token são armazenados por empresa na tabela `webposto.webposto_credentials`. Somente os timeouts globais permanecem no `.env`:

```env
WEBPOSTO_CONNECT_TIMEOUT=5
WEBPOSTO_TIMEOUT=30
```

O cliente central `WebPostoClient` seleciona a credencial ativa pelo `empresa_codigo`, acrescenta a chave como query parameter `?chave=`, aplica os timeouts e interpreta respostas JSON ou texto sem expor a chave ao consumidor da nossa API.

### Consulta de empresas

Nossa rota:

```http
GET /api/webposto/empresas?empresa_codigo=4604
```

Endpoint consultado no WebPosto:

```http
GET /INTEGRACAO/EMPRESAS?chave=CHAVE_DE_INTEGRACAO
```

Exemplo:

```bash
curl --max-time 40 \
  -H "Authorization: Bearer SEU_TOKEN_DA_NOSSA_API" \
  -H "Accept: application/json" \
  'http://localhost:8000/api/webposto/empresas?empresa_codigo=4604'
```

A resposta da nossa API contém:

- sucesso ou falha da chamada;
- endpoint consultado;
- status HTTP devolvido pelo WebPosto;
- tempo de resposta em milissegundos;
- tipo do conteúdo;
- quantidade de registros, quando identificável;
- payload original do WebPosto em `data`;
- horário de recebimento.

Quando a resposta é bem-sucedida, os itens de `resultados` passam por duas etapas idempotentes. Primeiro, são inseridos ou atualizados em `webposto.empresas`, preservando o retorno bruto da origem. Depois, CNPJ e CEP são reduzidos a dígitos, espaços são normalizados e UF/sigla são padronizadas antes da carga em `bi.dim_empresas`. O objeto `storage` apresenta contagens separadas em `raw` e `bi`, incluindo registros inseridos, atualizados, inalterados e ignorados.

### Consulta de grupos de produtos

```http
GET /api/webposto/produto-grupos?empresa_codigo=4604
```

A rota consulta `/INTEGRACAO/GRUPO` e sincroniza o retorno bruto em `webposto.produto_grupos`. Como a resposta de origem não informa a empresa, a aplicação registra o `empresaCodigo` correspondente à credencial utilizada. A chave única combina empresa e grupo, permitindo que códigos iguais existam em contextos empresariais diferentes. Registros existentes só são atualizados quando algum campo retornado pelo Web Posto muda.

### Consulta de subgrupos de produtos

```http
GET /api/webposto/produto-subgrupos?empresa_codigo=4604
```

A rota consulta `/INTEGRACAO/CONSULTAR_SUB_GRUPO_REDE` e sincroniza a lista em `webposto.produto_subgrupos`. Cada subgrupo é vinculado ao respectivo registro de `produto_grupos` pela combinação técnica de empresa e `grupoCodigo`. O campo aninhado `produtoSubGrupo2` é preservado como JSON. Subgrupos cujo grupo pai ainda não tenha sido importado são contabilizados em `missing_parent_group` e não são gravados até a sincronização do grupo.

### Consulta de produtos

```http
GET /api/webposto/produtos?empresa_codigo=4604&limite=1000
```

`empresa_codigo` seleciona internamente a credencial, enquanto somente `chave` e `limite` são enviados para `/INTEGRACAO/PRODUTO`. O limite é obrigatório e aceita valores de 1 a 1000. O retorno é sincronizado em `webposto.produtos`, ligado ao grupo pelo `grupoCodigo` e ao subgrupo pela combinação de empresa, grupo e `subGrupo1Codigo`; produtos sem subgrupo continuam válidos. Códigos dos níveis 2 e 3 e códigos de barras também são preservados. A resposta expõe `ultimo_codigo` para acompanhamento do retorno paginado.

### Consulta de produtos por empresa

```http
GET /api/webposto/produto-empresas?empresa_codigo=4604&limite=2000
```

A rota consulta `/INTEGRACAO/PRODUTO_EMPRESA` enviando chave e limite. O retorno é armazenado em `webposto.produto_empresas` e representa a configuração comercial/operacional do produto naquela empresa: preços, custo, estoque, estoque mínimo, situação e LMC. A relação usa `empresaCodigo + produtoCodigo` como FK para `produtos`; registros cujo produto pai ainda não foi importado são informados em `missing_parent_product`.

### Consulta de produtos LMC/LMP

```http
GET /api/webposto/produto-lmc-lmp?empresa_codigo=4604
```

A rota consulta `/INTEGRACAO/PRODUTO_LMC_LMP`, que recebe somente a chave. Como o retorno não contém empresa, a aplicação registra o `empresaCodigo` da credencial utilizada e sincroniza os dados em `webposto.produto_lmc_lmp`. A chave de negócio combina empresa e `produtoLmcCodigo`; os campos de mesmo nome presentes em `produtos` e `produto_empresas` permitem o cruzamento lógico com esse cadastro.

### Consulta de grupos de clientes

```http
GET /api/webposto/cliente-grupos?empresa_codigo=4604
```

A rota consulta `/INTEGRACAO/GRUPO_CLIENTE`, que recebe somente a chave. Como o retorno não contém empresa, o `empresaCodigo` da credencial é registrado em `webposto.cliente_grupos`. A tabela preserva os limites em litros e reais, valores disponíveis, bloqueio financeiro por vencimento e dias de tolerância. A chave única combina empresa e `grupoCodigo`.

### Consulta de clientes

```http
GET /api/webposto/clientes?empresa_codigo=4604
```

A rota consulta `/INTEGRACAO/CLIENTE` e sincroniza os 39 campos do retorno em `webposto.clientes`. O vínculo com o grupo utiliza `clienteGrupoCodigo`, nunca a descrição. O código `0` é tratado como cliente sem grupo; códigos positivos precisam existir em `cliente_grupos`. Contatos, centros de custo, frota, faturamento e limites/bloqueios são preservados como JSON.

### Consulta de clientes por empresa

```http
GET /api/webposto/cliente-empresas?empresa_codigo=4604
```

A rota consulta `/INTEGRACAO/CLIENTE_EMPRESA` e materializa em `webposto.cliente_empresas` o vínculo comercial explícito entre `empresaCodigo` e `clienteCodigo`, incluindo situação ativa e uso de prazo. Registros cujo cliente pai ainda não foi importado são contabilizados em `missing_parent_client`.

Quando o WebPosto responde com erro, nossa API retorna `502 Bad Gateway` e preserva em `upstream.http_status` o status original. Falhas de conexão ou timeout retornam `504 Gateway Timeout`, e configuração ausente retorna `503 Service Unavailable`.

## Segurança

- Não versione `.env` ou arquivos de backup contendo segredos.
- Troque as senhas de exemplo antes de publicar ou implantar o projeto.
- Não registre Bearer Tokens ou a key do WebPosto nos logs.
- O armazenamento atual do token em texto é temporário. Antes da produção, migre para hash criptográfico e implemente rotação e expiração.
- Não exponha a porta `3307` em produção; mantenha o MySQL acessível apenas pela rede interna.
