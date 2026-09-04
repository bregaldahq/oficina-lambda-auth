locals {
  name_prefix = "oficina-${var.environment}"

  common_tags = {
    Project     = "oficina-mecanica"
    Environment = var.environment
    ManagedBy   = "terraform"
    Repo        = "oficina-lambda-auth"
  }

  auth_function_name       = "${local.name_prefix}-auth-cpf"
  authorizer_function_name = "${local.name_prefix}-jwt-authorizer"
  api_name                 = "${local.name_prefix}-api"

  # Layer do runtime PHP mantido pelo Bref. A conta 534081306603 e' a oficial do Bref.
  bref_php_layer_arn = "arn:aws:lambda:${var.aws_region}:534081306603:layer:${var.bref_layer_name}:${var.bref_layer_version}"

  private_subnet_ids = split(",", nonsensitive(data.aws_ssm_parameter.private_subnet_ids.value))

  db_secret_arn   = nonsensitive(data.aws_ssm_parameter.db_secret_arn.value)
  auth_secret_arn = nonsensitive(data.aws_ssm_parameter.auth_secret_arn.value)

  # Layers extras (New Relic) aplicados as duas funcoes quando habilitado.
  extra_layers = var.newrelic_enabled ? [var.newrelic_layer_arn] : []
}
