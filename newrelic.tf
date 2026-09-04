# ---------------------------------------------------------------------------
# Extensao New Relic para Lambda.
#
# O layer e' anexado as DUAS funcoes (ver `local.extra_layers`). A extensao roda
# ao lado do runtime PHP, drena o log group da funcao e envia metricas de
# invocacao/erro/duracao para o New Relic — sem exigir wrapper de handler, que o
# Bref nao suporta.
#
# O ARN do layer e' especifico da regiao: ver o comentario da variavel
# `newrelic_layer_arn` em variables.tf para onde conferir o valor vigente.
# ---------------------------------------------------------------------------

locals {
  newrelic_env = var.newrelic_enabled ? {
    NEW_RELIC_ACCOUNT_ID                   = var.newrelic_account_id
    NEW_RELIC_LAMBDA_EXTENSION_ENABLED     = "true"
    NEW_RELIC_EXTENSION_SEND_FUNCTION_LOGS = "true"
    NEW_RELIC_DISTRIBUTED_TRACING_ENABLED  = "true"
    # A license key nunca vai em variavel de ambiente em claro: a extensao le o
    # segredo do Secrets Manager pelo nome abaixo.
    NEW_RELIC_LICENSE_KEY_SECRET = var.newrelic_license_key_secret_id
  } : {}
}
