# ---------------------------------------------------------------------------
# Logs das Lambdas e do API Gateway para o New Relic, via Kinesis Firehose.
#
# Por que nao a extensao New Relic para Lambda: a auth-cpf roda dentro da VPC,
# que nao tem NAT. A extensao precisa alcancar log-api.newrelic.com a partir da
# funcao — sem rota de saida, ela falha em silencio e nenhum dado chega.
#
# Este caminho nao passa pela VPC. O CloudWatch Logs entrega as linhas a um
# Firehose por subscription filter, e o Firehose as envia ao endpoint do New
# Relic. Tres log groups alimentam a aba "Lambdas e gateway" do dashboard:
#
#   - access log do API Gateway: rota, status e latencia de cada requisicao,
#     inclusive os 401/403 que o authorizer barra antes do cluster;
#   - log das duas funcoes: a linha REPORT de cada invocacao traz a duracao e,
#     quando houve cold start, o `Init Duration`.
#
# Sem license key (plan de PR, ambiente sem New Relic), nada aqui e' criado.
# ---------------------------------------------------------------------------

locals {
  # So o booleano "tem chave?" deixa de ser sensivel — a chave em si continua.
  # Sem isto o for_each dos subscription filters e' recusado pelo Terraform.
  newrelic_logs_enabled = nonsensitive(var.newrelic_license_key != "")

  newrelic_log_groups = {
    api_access = aws_cloudwatch_log_group.api_access.name
    auth       = aws_cloudwatch_log_group.auth.name
    authorizer = aws_cloudwatch_log_group.authorizer.name
  }

  newrelic_firehose_endpoint = {
    us = "https://aws-api.newrelic.com/firehose/v1"
    eu = "https://aws-api.eu.newrelic.com/firehose/v1"
  }
}

# Destino obrigatorio do Firehose para o que o New Relic recusar. So recebe
# falhas; o resto vai direto ao endpoint.
resource "aws_s3_bucket" "newrelic_logs_backup" {
  count = local.newrelic_logs_enabled ? 1 : 0

  bucket        = "${local.name_prefix}-newrelic-logs-${data.aws_caller_identity.current.account_id}"
  force_destroy = true
}

resource "aws_s3_bucket_public_access_block" "newrelic_logs_backup" {
  count = local.newrelic_logs_enabled ? 1 : 0

  bucket                  = aws_s3_bucket.newrelic_logs_backup[0].id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_lifecycle_configuration" "newrelic_logs_backup" {
  count = local.newrelic_logs_enabled ? 1 : 0

  bucket = aws_s3_bucket.newrelic_logs_backup[0].id

  rule {
    id     = "expirar-falhas"
    status = "Enabled"

    filter {}

    expiration {
      days = 7
    }
  }
}

# --- Firehose -> New Relic ---------------------------------------------------

data "aws_iam_policy_document" "firehose_assume" {
  statement {
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["firehose.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "newrelic_firehose" {
  count = local.newrelic_logs_enabled ? 1 : 0

  name               = "${local.name_prefix}-newrelic-firehose"
  assume_role_policy = data.aws_iam_policy_document.firehose_assume.json
}

resource "aws_iam_role_policy" "newrelic_firehose" {
  count = local.newrelic_logs_enabled ? 1 : 0

  name = "backup-de-falhas"
  role = aws_iam_role.newrelic_firehose[0].id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect = "Allow"
      Action = [
        "s3:AbortMultipartUpload",
        "s3:GetBucketLocation",
        "s3:ListBucket",
        "s3:ListBucketMultipartUploads",
        "s3:PutObject",
      ]
      Resource = [
        aws_s3_bucket.newrelic_logs_backup[0].arn,
        "${aws_s3_bucket.newrelic_logs_backup[0].arn}/*",
      ]
    }]
  })
}

resource "aws_kinesis_firehose_delivery_stream" "newrelic_logs" {
  count = local.newrelic_logs_enabled ? 1 : 0

  name        = "${local.name_prefix}-newrelic-logs"
  destination = "http_endpoint"

  http_endpoint_configuration {
    name       = "New Relic"
    url        = local.newrelic_firehose_endpoint[var.newrelic_region]
    access_key = var.newrelic_license_key
    role_arn   = aws_iam_role.newrelic_firehose[0].arn

    # 1 MiB ou 60 s, o que vier primeiro. Um log aparece no New Relic em cerca
    # de um minuto — rapido o bastante para acompanhar uma demonstracao.
    buffering_size     = 1
    buffering_interval = 60
    retry_duration     = 60

    s3_backup_mode = "FailedDataOnly"

    s3_configuration {
      role_arn           = aws_iam_role.newrelic_firehose[0].arn
      bucket_arn         = aws_s3_bucket.newrelic_logs_backup[0].arn
      compression_format = "GZIP"
    }

    request_configuration {
      content_encoding = "GZIP"
    }
  }
}

# --- CloudWatch Logs -> Firehose ---------------------------------------------

data "aws_iam_policy_document" "logs_assume" {
  statement {
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["logs.amazonaws.com"]
    }

    condition {
      test     = "StringLike"
      variable = "aws:SourceArn"
      values   = ["arn:aws:logs:${var.aws_region}:${data.aws_caller_identity.current.account_id}:*"]
    }
  }
}

resource "aws_iam_role" "logs_to_firehose" {
  count = local.newrelic_logs_enabled ? 1 : 0

  name               = "${local.name_prefix}-logs-to-firehose"
  assume_role_policy = data.aws_iam_policy_document.logs_assume.json
}

resource "aws_iam_role_policy" "logs_to_firehose" {
  count = local.newrelic_logs_enabled ? 1 : 0

  name = "entregar-no-firehose"
  role = aws_iam_role.logs_to_firehose[0].id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["firehose:PutRecord", "firehose:PutRecordBatch"]
      Resource = aws_kinesis_firehose_delivery_stream.newrelic_logs[0].arn
    }]
  })
}

resource "aws_cloudwatch_log_subscription_filter" "newrelic" {
  for_each = local.newrelic_logs_enabled ? local.newrelic_log_groups : {}

  name            = "newrelic"
  log_group_name  = each.value
  filter_pattern  = ""
  destination_arn = aws_kinesis_firehose_delivery_stream.newrelic_logs[0].arn
  role_arn        = aws_iam_role.logs_to_firehose[0].arn

  # A policy precisa existir antes: o CloudWatch testa a entrega ao criar o filtro.
  depends_on = [aws_iam_role_policy.logs_to_firehose]
}
