environment = "hml"
aws_region  = "us-east-1"

# Conferir a versao vigente em https://runtimes.bref.sh/ antes de aplicar.
bref_layer_name    = "php-82"
bref_layer_version = 71

log_retention_days     = 14
throttling_rate_limit  = 50
throttling_burst_limit = 100

auth_memory_size       = 512
authorizer_memory_size = 256

# Extensao New Relic para Lambda desligada: a versao 81 do layer publico nao esta
# acessivel a esta conta (AccessDenied em lambda:GetLayerVersion). Reativar depois
# de confirmar a versao vigente em https://layers.newrelic-external.com/
newrelic_enabled = false
# Conferir em https://layers.newrelic-external.com/ (regiao us-east-1).
newrelic_layer_arn = "arn:aws:lambda:us-east-1:451483290750:layer:NewRelicLambdaExtension:81"
# Deve ser o ARN (ou id) de um segredo do Secrets Manager, nunca o nome da variavel.
# Vazio = a policy nao referencia segredo nenhum (compact() descarta).
newrelic_license_key_secret_id = ""
