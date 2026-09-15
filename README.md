# Sales API

API REST em Laravel 12 para receber vendas de parceiros por webhook ou CSV e creditar pontos aos clientes de forma assíncrona.

## Requisitos

- PHP 8.5, com extensões `mbstring` e `xml` habilitadas;
- Composer;
- Laravel 12;
- banco relacional — SQLite é a configuração local padrão;
- worker de fila em execução.

## Instalação e execução

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Defina no `.env` um segredo seguro para autenticar integrações:

```env
PARTNER_WEBHOOK_SECRET=troque-por-um-segredo-seguro
QUEUE_CONNECTION=database
```

Crie as tabelas e inclua os clientes de demonstração:

```bash
php artisan migrate --seed
```

Esse comando executa `CustomerSeeder` e cria/atualiza os clientes de IDs `145` e `178`. Também cria o usuário local `demo@example.com` e imprime no terminal um token Sanctum aleatório para testar a consulta protegida.

Para recriar o banco local do zero:

```bash
php artisan migrate:fresh --seed
```

### Opção A: execução local com Composer

Instale as dependências do Vite e suba a aplicação, worker, logs e Vite com um único comando:

```bash
npm install
composer run dev
```

O script usa `php artisan queue:listen --tries=3`, portanto os jobs são processados localmente pela fila configurada no `.env`.

Execute os testes:

```bash
php artisan test
```

### Opção B: desenvolvimento com Docker Compose

O projeto possui uma configuração Docker Compose própria, sem Laravel Sail. Ela sobe PHP 8.5/FPM, Nginx, MySQL 8.4, Redis 7.4 e um worker separado para a fila Redis.

Prepare o `.env` e instale dependências dentro do container:

```bash
cp .env.example .env
docker compose build
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d
```

Crie o banco e os dados demonstrativos:

```bash
docker compose exec app php artisan migrate:fresh --seed
```

A API ficará disponível em `http://localhost:8000`. O Compose substitui as configurações locais por MySQL e Redis; a fila usa `QUEUE_CONNECTION=redis` e o serviço `queue` executa `php artisan queue:work redis --tries=3`.

Comandos úteis:

```bash
docker compose exec app php artisan test
docker compose logs -f queue
docker compose down
docker compose down -v # remove também os volumes locais de MySQL e Redis
```

## Modelagem de dados

| Tabela | Finalidade |
| --- | --- |
| `customers` | Clientes existentes, com `name` e saldo inteiro em `points_balance`. |
| `sales` | Venda recebida: `external_id`, cliente, `amount`, origem, ocorrência e estado da pontuação. |
| `csv_imports` | Controle do arquivo, status, contadores e datas de processamento. |
| `csv_import_rows` | Auditoria por linha: posição, identificador externo, status e erro. |
| `jobs` / `failed_jobs` | Fila `database` e registro de jobs que esgotaram tentativas. |
| `personal_access_tokens` | Tokens da autenticação Sanctum. |

`sales.amount` é armazenado em centavos como inteiro: `350.00` é persistido como `35000`. A regra é `intdiv($amount, 1000)`: cada R$ 10,00 gera um ponto e frações são descartadas.

## Endpoints

### Receber venda por webhook

`POST /api/webhooks/sales`

```json
{
  "external_id": "SALE-92831",
  "customer_id": 145,
  "amount": 350.00,
  "occurred_at": "2026-08-20T14:30:00"
}
```

- `201 Created`: venda criada e job de pontos enviado à fila.
- `200 OK`: venda já recebida; não cria nem pontua novamente.
- `401 Unauthorized`: assinatura inválida.
- `422 Unprocessable Entity`: payload inválido, com mensagens em PT-BR.

### Importar CSV

`POST /api/imports/sales`

Campo multipart obrigatório: `file`. Limite: 10 MB. Cabeçalho obrigatório:

```csv
external_id,customer_id,amount,occurred_at
SALE-92831,145,350.00,2026-08-20T14:30:00
SALE-92832,178,120.50,2026-08-20T14:35:00
```

A resposta é `202 Accepted` com `import_id` e status inicial `pending`. O arquivo é lido em streaming pelo worker; linhas inválidas, falhas e duplicidades ficam registradas em `csv_import_rows` sem interromper as demais.

### Consultar pontos

`GET /api/customers/{id}/points`

Envie o token impresso pelo seeder:

```bash
curl http://localhost:8000/api/customers/145/points \
  -H 'Authorization: Bearer TOKEN_IMPRESSO_NO_SEEDER'
```

Resposta:

```json
{"customer_id":145,"points":35}
```

## Autenticação HMAC

Webhook e upload CSV exigem o header `X-Webhook-Signature`. A assinatura é o hexadecimal de HMAC SHA-256 calculado sobre o **corpo bruto** da requisição, usando `PARTNER_WEBHOOK_SECRET`.

Exemplo de webhook:

```bash
body='{"external_id":"SALE-92831","customer_id":145,"amount":350.00,"occurred_at":"2026-08-20T14:30:00"}'
signature=$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$PARTNER_WEBHOOK_SECRET" -hex | sed 's/^.* //')

curl -X POST http://localhost:8000/api/webhooks/sales \
  -H 'Content-Type: application/json' \
  -H "X-Webhook-Signature: $signature" \
  --data "$body"
```

No CSV, a assinatura também deve cobrir os bytes completos do corpo multipart. Em uma integração real, o parceiro deve serializar o multipart uma única vez, gerar o HMAC daqueles bytes e enviar exatamente os mesmos bytes.

## Arquitetura e decisões técnicas

```text
Controller → FormRequest / Middleware → CreateSaleService → Model / Job
```

- Controllers permanecem finos; recebem HTTP e retornam respostas.
- Form Requests validam payloads HTTP; o importador usa `Validator` com as mesmas regras para cada linha, sem reutilizar Form Request fora do contexto HTTP.
- `VerifyWebhookSignature` valida HMAC com `hash_equals`.
- `CreateSaleService` concentra somente a criação compartilhada de vendas. Não há repositories nem services artificiais.
- `ProcessSalePoints` e `ImportSalesCsv` executam de forma assíncrona pela fila do Laravel.

## Integridade, idempotência e concorrência

### Integridade dos dados

- `sales.customer_id` possui foreign key real e exclusão restritiva, preservando histórico.
- `sales.external_id` possui constraint única no banco.
- Valores monetários são inteiros em centavos, sem `float` no armazenamento ou cálculo de pontos.

### Idempotência da entrada

Webhook e CSV chamam o mesmo `CreateSaleService`. Se o mesmo `external_id` chegar repetidamente, a constraint única impede uma segunda `Sale`; a aplicação trata essa condição como duplicidade esperada.

### Idempotência do job de pontos

O mesmo job pode ser reexecutado pela fila. Em transação, ele bloqueia a `Sale` com `lockForUpdate`, consulta `points_processed_at` e encerra sem novo crédito se ela já foi processada.

### Concorrência entre vendas diferentes

Duas vendas do mesmo cliente não usam read-modify-write do saldo. O crédito é feito com `increment()` atômico no banco, dentro da transação que marca a venda como processada.

## CSV, erros e logs

O arquivo é processado com `SplFileObject`, linha a linha, sem carregar seu conteúdo completo em memória. Cada linha recebe um status: `processed`, `duplicate`, `invalid` ou `failed`.

Os jobs usam três tentativas e backoff progressivo. Há logs estruturados para payload inválido, duplicidade de venda, processamento duplicado de pontos, falhas de processamento, jobs esgotados, início/fim de importação e linhas CSV inválidas ou com falha. Segredos e corpo completo de requisições não são registrados.

## Redis

O padrão é `QUEUE_CONNECTION=database`, adequado ao exercício. Para Redis, configure no `.env`:

```env
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_QUEUE=default
```

O servidor precisa ter Redis disponível e a extensão PHP `phpredis` habilitada, ou um cliente Redis equivalente instalado.

## Produção e escala

Em produção, a aplicação deve usar HTTPS, banco gerenciado, workers supervisionados por Supervisor/systemd/orquestrador, secrets fora do repositório, storage adequado, backups, logs centralizados e monitoramento.

Em maior escala, os principais gargalos são crescimento da fila, contenção no saldo de clientes muito ativos, arquivos muito grandes e crescimento das tabelas `sales` e `csv_import_rows`. Evoluções possíveis: importação por chunks, múltiplos jobs, filas dedicadas, `Bus::batch`, object storage, backpressure, índices revisados e arquivamento/particionamento futuro.

## Melhorias com mais tempo

- Endpoint autenticado para consultar uma importação e suas linhas.
- Testes de concorrência contra banco servidor além dos testes atuais.
- Upload em object storage e divisão de importações muito grandes em chunks.

## Uso de Inteligência Artificial

OpenAI Codex foi utilizado de forma **assistencial**: apoio à implementação, geração e revisão de testes, análise de condições de corrida, código repetitivo e revisão da documentação.

A arquitetura em camadas, a modelagem do banco de dados e as decisões técnicas foram desenhadas e avaliadas pelo candidato. Todo código incorporado foi revisado, adaptado quando necessário e é de responsabilidade do candidato compreender, explicar e defender a solução entregue.
