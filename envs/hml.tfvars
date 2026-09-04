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

newrelic_enabled = true
# Conferir em https://layers.newrelic-external.com/ (regiao us-east-1).
newrelic_layer_arn             = "arn:aws:lambda:us-east-1:451483290750:layer:NewRelicLambdaExtension:81"
newrelic_license_key_secret_id = "NEW_RELIC_LICENSE_KEY"
