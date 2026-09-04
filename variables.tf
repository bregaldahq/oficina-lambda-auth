variable "aws_region" {
  description = "AWS region for every resource in this stack."
  type        = string
  default     = "us-east-1"
}

variable "environment" {
  description = "Environment name. Drives every resource name and every SSM path."
  type        = string

  validation {
    condition     = contains(["hml", "prod"], var.environment)
    error_message = "environment must be either \"hml\" or \"prod\"."
  }
}

# ---------------------------------------------------------------------------
# Runtime PHP (Bref)
# ---------------------------------------------------------------------------

variable "bref_layer_name" {
  description = <<-EOT
    Nome do layer de runtime PHP publicado pelo Bref. `php-82` = PHP 8.2 para
    funcoes acionadas por evento (o caso das duas Lambdas deste repositorio);
    `php-82-fpm` seria para o runtime FPM, que nao usamos aqui.
  EOT
  type        = string
  default     = "php-82"
}

variable "bref_layer_version" {
  description = <<-EOT
    Versao do layer Bref. NAO ha' valor "certo" fixo: o Bref publica versoes novas
    a cada release e a versao correta muda com o tempo e com a regiao.

    Onde conferir a versao vigente ANTES de aplicar:
      - https://runtimes.bref.sh/ (tabela por regiao e por layer), ou
      - aws lambda list-layer-versions --region us-east-1 \
          --layer-name arn:aws:lambda:us-east-1:534081306603:layer:php-82 \
          --query 'LayerVersions[0].Version'

    O default abaixo e' um ponto de partida e deve ser confirmado/atualizado no
    `envs/<env>.tfvars` do ambiente. Aplicar com uma versao inexistente falha no
    apply com ResourceNotFoundException — falha barulhenta, nao silenciosa.
  EOT
  type        = number
  default     = 71
}

# ---------------------------------------------------------------------------
# Pacote da aplicacao
# ---------------------------------------------------------------------------

variable "lambda_package_path" {
  description = "Caminho do zip com o codigo PHP (src/, vendor/ e os handlers). Gerado pelo CI."
  type        = string
  default     = "build/lambda.zip"
}

variable "auth_handler" {
  description = "Arquivo handler da Lambda auth-cpf."
  type        = string
  default     = "handler-auth.php"
}

variable "authorizer_handler" {
  description = "Arquivo handler da Lambda jwt-authorizer."
  type        = string
  default     = "handler-authorizer.php"
}

variable "auth_memory_size" {
  description = "Memoria (MB) da Lambda auth-cpf. Ela abre conexao com o RDS."
  type        = number
  default     = 512
}

variable "auth_timeout" {
  description = "Timeout (s) da Lambda auth-cpf."
  type        = number
  default     = 15
}

variable "authorizer_memory_size" {
  description = "Memoria (MB) do authorizer. So' valida assinatura: pouco e' suficiente."
  type        = number
  default     = 256
}

variable "authorizer_timeout" {
  description = "Timeout (s) do authorizer. O API Gateway corta em 10s de qualquer forma."
  type        = number
  default     = 5
}

variable "log_retention_days" {
  description = "Retencao dos log groups do CloudWatch, em dias."
  type        = number
  default     = 14
}

# ---------------------------------------------------------------------------
# API Gateway
# ---------------------------------------------------------------------------

variable "authorizer_result_ttl_seconds" {
  description = "TTL do cache do authorizer. 300s conforme secao 5 dos Contratos."
  type        = number
  default     = 300
}

variable "throttling_rate_limit" {
  description = "Requisicoes por segundo (steady state) no stage $default."
  type        = number
  default     = 100
}

variable "throttling_burst_limit" {
  description = "Burst de requisicoes no stage $default."
  type        = number
  default     = 200
}

variable "cors_allow_origins" {
  description = "Origens permitidas no CORS do HTTP API."
  type        = list(string)
  default     = ["*"]
}

# ---------------------------------------------------------------------------
# New Relic
# ---------------------------------------------------------------------------

variable "newrelic_enabled" {
  description = "Anexa o layer da extensao New Relic para Lambda nas duas funcoes."
  type        = bool
  default     = true
}

variable "newrelic_layer_arn" {
  description = <<-EOT
    ARN do layer da extensao New Relic para Lambda, especifico da REGIAO.

    Onde obter o ARN da regiao antes de aplicar:
      - https://layers.newrelic-external.com/ (lista oficial por regiao e runtime), ou
      - aws lambda list-layer-versions --region us-east-1 \
          --layer-name arn:aws:lambda:us-east-1:451483290750:layer:NewRelicLambdaExtension-ARM64 \
          --query 'LayerVersions[0].LayerVersionArn'

    Usamos a extensao "pura" (NewRelicLambdaExtension), que coleta logs e telemetria
    da funcao sem exigir wrapper de handler — o Bref/PHP nao tem agente NR de Lambda
    proprio. O default abaixo e' um placeholder plausivel e PRECISA ser confirmado.
  EOT
  type        = string
  default     = "arn:aws:lambda:us-east-1:451483290750:layer:NewRelicLambdaExtension:81"
}

variable "newrelic_account_id" {
  description = "Account ID do New Relic. Lido pela extensao via variavel de ambiente."
  type        = string
  default     = ""
}

variable "newrelic_license_key_secret_id" {
  description = <<-EOT
    Nome/ARN do segredo do Secrets Manager que guarda a license key do New Relic.
    Vazio desativa a leitura (a extensao fica inerte). Nunca passar a chave em tfvars.
  EOT
  type        = string
  default     = ""
}
