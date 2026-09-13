# Sales API

API Laravel 12 para receber vendas por webhook ou CSV e creditar pontos de forma assíncrona.

## Requisitos e instalação

- PHP 8.5
- Laravel 12
- Composer
- SQLite para desenvolvimento (ou outro banco relacional configurado no `.env`)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Defina um segredo forte em `PARTNER_WEBHOOK_SECRET` e mantenha `QUEUE_CONNECTION=database`. O seeder cria os clientes 145 e 178, um usuário demonstrativo e imprime um token Sanctum aleatório no terminal.

```bash
php artisan queue:work
php artisan test
```

## Endpoints

### Webhook de vendas

`POST /api/webhooks/sales`

```json
{"external_id":"SALE-92831","customer_id":145,"amount":350.00,"occurred_at":"2026-08-20T14:30:00"}
```

Uma venda nova responde `201`; uma venda já recebida responde `200` com `status: duplicate`.

### Importação CSV

`POST /api/imports/sales` recebe `multipart/form-data`, com o campo `file`, e retorna `202 Accepted` com `import_id`.

```csv
external_id,customer_id,amount,occurred_at
SALE-92831,145,350.00,2026-08-20T14:30:00
```

O arquivo é processado em fila e cada linha fica auditada em `csv_import_rows`.

### Saldo de pontos

`GET /api/customers/{id}/points` exige `Authorization: Bearer TOKEN_DO_SEEDER` e responde:

```json
{"customer_id":145,"points":35}
```

## Autenticação HMAC

Webhook e CSV exigem `X-Webhook-Signature`: o hexadecimal de HMAC SHA-256 do corpo **bruto**, usando `PARTNER_WEBHOOK_SECRET`.

```bash
body='{"external_id":"SALE-92831","customer_id":145,"amount":350.00,"occurred_at":"2026-08-20T14:30:00"}'
signature=$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$PARTNER_WEBHOOK_SECRET" -hex | sed 's/^.* //')
curl -X POST http://localhost:8000/api/webhooks/sales -H 'Content-Type: application/json' -H "X-Webhook-Signature: $signature" --data "$body"
```

No CSV, a assinatura cobre os bytes completos do `multipart/form-data`; o cliente parceiro deve montar e assinar essa requisição antes de enviá-la.

## Decisões técnicas

- `CreateSaleService` é compartilhado por webhook e CSV.
- `sales.external_id` tem constraint única, garantindo idempotência de entrada.
- `amount` é inteiro em centavos: `350.00` é armazenado como `35000`.
- `ProcessSalePoints` usa fila `database`, transação, `lockForUpdate`, `points_processed_at` e incremento atômico no cliente. Assim, o mesmo job não pontua duas vezes e vendas simultâneas não perdem saldo.
- O CSV usa `SplFileObject`, processa linha a linha, continua após erros e registra auditoria.
- Eloquent é usado diretamente; repositories não acrescentariam valor ao escopo.

## Erros, produção e escala

Jobs de pontos possuem três tentativas com backoff e logs com IDs relevantes. Importações e linhas CSV também são auditadas e logadas, sem secrets.

Em produção: HTTPS, banco gerenciado, workers supervisionados, secrets fora do repositório, object storage, backups e monitoramento. Com maior volume, evoluir para chunks, filas dedicadas, múltiplos workers, batches, índices revisados e arquivamento/particionamento de dados.

## Melhorias com mais tempo

- Endpoint autenticado para consultar importações e linhas.
- Testes de concorrência contra banco servidor.
- Object storage e importações segmentadas para arquivos gigantes.

## Uso de Inteligência Artificial

OpenAI Codex foi utilizado como apoio à implementação, revisão de testes, condições de corrida, código repetitivo e documentação. Todo código incorporado foi revisado; as decisões arquiteturais foram analisadas pelo candidato, que é responsável por compreender e defender a solução.
