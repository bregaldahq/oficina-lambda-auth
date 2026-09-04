# ---------------------------------------------------------------------------
# Log groups criados explicitamente (e nao pela primeira invocacao) para que a
# retencao seja gerenciada e o IAM possa ser escopado ao ARN exato.
# ---------------------------------------------------------------------------

resource "aws_cloudwatch_log_group" "auth" {
  name              = "/aws/lambda/${local.auth_function_name}"
  retention_in_days = var.log_retention_days
}

resource "aws_cloudwatch_log_group" "authorizer" {
  name              = "/aws/lambda/${local.authorizer_function_name}"
  retention_in_days = var.log_retention_days
}

# ---------------------------------------------------------------------------
# auth-cpf — DENTRO da VPC, nas subnets privadas, com o SG cliente de banco.
# ---------------------------------------------------------------------------

resource "aws_lambda_function" "auth" {
  function_name = local.auth_function_name
  role          = aws_iam_role.auth.arn

  # `provided.al2` + layer Bref: o runtime PHP vem do layer, nao da AWS.
  runtime = "provided.al2"
  handler = var.auth_handler
  layers  = concat([local.bref_php_layer_arn], local.extra_layers)

  filename         = var.lambda_package_path
  source_code_hash = try(filebase64sha256(var.lambda_package_path), null)

  memory_size = var.auth_memory_size
  timeout     = var.auth_timeout

  vpc_config {
    subnet_ids         = local.private_subnet_ids
    security_group_ids = [nonsensitive(data.aws_ssm_parameter.db_client_sg_id.value)]
  }

  environment {
    variables = merge(
      {
        APP_ENV        = var.environment
        AUTH_SECRET_ID = local.auth_secret_arn
        DB_SECRET_ID   = local.db_secret_arn
      },
      local.newrelic_env
    )
  }

  depends_on = [
    aws_cloudwatch_log_group.auth,
    aws_iam_role_policy_attachment.auth_vpc_access,
  ]
}

# ---------------------------------------------------------------------------
# jwt-authorizer — FORA da VPC de proposito: nao toca no banco, e manter a
# funcao fora da VPC evita ENI/cold start extra no caminho de TODA requisicao.
# ---------------------------------------------------------------------------

resource "aws_lambda_function" "authorizer" {
  function_name = local.authorizer_function_name
  role          = aws_iam_role.authorizer.arn

  runtime = "provided.al2"
  handler = var.authorizer_handler
  layers  = concat([local.bref_php_layer_arn], local.extra_layers)

  filename         = var.lambda_package_path
  source_code_hash = try(filebase64sha256(var.lambda_package_path), null)

  memory_size = var.authorizer_memory_size
  timeout     = var.authorizer_timeout

  environment {
    variables = merge(
      {
        APP_ENV        = var.environment
        AUTH_SECRET_ID = local.auth_secret_arn
      },
      local.newrelic_env
    )
  }

  depends_on = [aws_cloudwatch_log_group.authorizer]
}
