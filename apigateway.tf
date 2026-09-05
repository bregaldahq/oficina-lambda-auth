# ---------------------------------------------------------------------------
# HTTP API (API Gateway v2) — porta de entrada unica da aplicacao.
# ---------------------------------------------------------------------------

resource "aws_apigatewayv2_api" "this" {
  name          = local.api_name
  protocol_type = "HTTP"
  description   = "Porta de entrada da Oficina Mecanica (${var.environment})."

  cors_configuration {
    allow_origins = var.cors_allow_origins
    allow_methods = ["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"]
    allow_headers = ["Authorization", "Content-Type", "X-Request-Id", "X-Webhook-Token"]
    max_age       = 300
  }
}

# ---------------------------------------------------------------------------
# VPC Link — nas MESMAS subnets privadas em que o NLB interno do repo de k8s
# foi criado (adendo 4 dos Contratos). Nao inventar outra selecao de subnet.
# ---------------------------------------------------------------------------

resource "aws_security_group" "vpc_link" {
  name        = "${local.name_prefix}-vpclink-sg"
  description = "ENIs do VPC Link do API Gateway. Somente egress para dentro da VPC."
  vpc_id      = nonsensitive(data.aws_ssm_parameter.vpc_id.value)

  tags = { Name = "${local.name_prefix}-vpclink-sg" }
}

resource "aws_vpc_security_group_egress_rule" "vpc_link_to_vpc" {
  security_group_id = aws_security_group.vpc_link.id
  description       = "Alcanca o NLB interno na porta 80."
  cidr_ipv4         = nonsensitive(data.aws_ssm_parameter.vpc_cidr.value)
  from_port         = 80
  to_port           = 80
  ip_protocol       = "tcp"
}

resource "aws_apigatewayv2_vpc_link" "this" {
  name               = "${local.name_prefix}-vpclink"
  subnet_ids         = local.private_subnet_ids
  security_group_ids = [aws_security_group.vpc_link.id]
}

# ---------------------------------------------------------------------------
# Integracoes
# ---------------------------------------------------------------------------

resource "aws_apigatewayv2_integration" "auth_cpf" {
  api_id                 = aws_apigatewayv2_api.this.id
  integration_type       = "AWS_PROXY"
  integration_uri        = aws_lambda_function.auth.invoke_arn
  payload_format_version = "2.0"
  timeout_milliseconds   = 20000
}

# HTTP_PROXY via VPC Link -> listener :80 do NLB interno, cujo ARN e' publicado
# no SSM pelo repo `oficina-infra-k8s` (adendo 1). Este repo nao cria load balancer.
resource "aws_apigatewayv2_integration" "app_proxy" {
  api_id             = aws_apigatewayv2_api.this.id
  integration_type   = "HTTP_PROXY"
  integration_method = "ANY"
  integration_uri    = nonsensitive(data.aws_ssm_parameter.nlb_listener_arn.value)

  connection_type = "VPC_LINK"
  connection_id   = aws_apigatewayv2_vpc_link.this.id

  payload_format_version = "1.0"
  timeout_milliseconds   = 29000

  request_parameters = {
    "overwrite:path" = "$request.path"
  }
}

# ---------------------------------------------------------------------------
# Authorizer REQUEST com simple response (secao 5 dos Contratos)
# ---------------------------------------------------------------------------

resource "aws_apigatewayv2_authorizer" "jwt" {
  api_id           = aws_apigatewayv2_api.this.id
  name             = "${local.name_prefix}-jwt-authorizer"
  authorizer_type  = "REQUEST"
  authorizer_uri   = aws_lambda_function.authorizer.invoke_arn
  identity_sources = ["$request.header.Authorization"]

  authorizer_payload_format_version = "2.0"
  enable_simple_responses           = true
  authorizer_result_ttl_in_seconds  = var.authorizer_result_ttl_seconds
}

# ---------------------------------------------------------------------------
# Rotas
# ---------------------------------------------------------------------------

# Identificacao por CPF. Sem authorizer: e' aqui que o cliente obtem o token.
resource "aws_apigatewayv2_route" "auth_cpf" {
  api_id             = aws_apigatewayv2_api.this.id
  route_key          = "POST /auth/cpf"
  target             = "integrations/${aws_apigatewayv2_integration.auth_cpf.id}"
  authorization_type = "NONE"
}

# Todo o resto da aplicacao, atras do authorizer. `POST /api/auth/login` tambem
# passa por aqui, mas o proprio authorizer libera essa rota sem token.
resource "aws_apigatewayv2_route" "app_proxy" {
  api_id             = aws_apigatewayv2_api.this.id
  route_key          = "ANY /api/{proxy+}"
  target             = "integrations/${aws_apigatewayv2_integration.app_proxy.id}"
  authorization_type = "CUSTOM"
  authorizer_id      = aws_apigatewayv2_authorizer.jwt.id
}

# ---------------------------------------------------------------------------
# Permissoes de invocacao
# ---------------------------------------------------------------------------

resource "aws_lambda_permission" "auth_cpf" {
  statement_id  = "AllowInvokeFromHttpApi"
  action        = "lambda:InvokeFunction"
  function_name = aws_lambda_function.auth.function_name
  principal     = "apigateway.amazonaws.com"
  source_arn    = "${aws_apigatewayv2_api.this.execution_arn}/*/*"
}

resource "aws_lambda_permission" "authorizer" {
  statement_id  = "AllowInvokeFromHttpApiAuthorizer"
  action        = "lambda:InvokeFunction"
  function_name = aws_lambda_function.authorizer.function_name
  principal     = "apigateway.amazonaws.com"
  source_arn    = "${aws_apigatewayv2_api.this.execution_arn}/authorizers/${aws_apigatewayv2_authorizer.jwt.id}"
}

# ---------------------------------------------------------------------------
# Stage $default com access log JSON e throttling
# ---------------------------------------------------------------------------

resource "aws_cloudwatch_log_group" "api_access" {
  name              = "/aws/apigateway/${local.api_name}/access"
  retention_in_days = var.log_retention_days
}

resource "aws_apigatewayv2_stage" "default" {
  api_id      = aws_apigatewayv2_api.this.id
  name        = "$default"
  auto_deploy = true

  access_log_settings {
    destination_arn = aws_cloudwatch_log_group.api_access.arn

    # Uma linha JSON por request, no mesmo espirito do log estruturado da
    # aplicacao (secao 7 dos Contratos) — `correlation_id` amarra os dois lados.
    format = jsonencode({
      timestamp       = "$context.requestTime"
      level           = "info"
      message         = "apigw.request.completed"
      service         = "oficina-apigw"
      env             = var.environment
      correlation_id  = "$context.requestId"
      method          = "$context.httpMethod"
      path            = "$context.path"
      route           = "$context.routeKey"
      status          = "$context.status"
      protocol        = "$context.protocol"
      duration_ms     = "$context.responseLatency"
      response_length = "$context.responseLength"
      source_ip       = "$context.identity.sourceIp"
      user_agent      = "$context.identity.userAgent"
      integration_err = "$context.integrationErrorMessage"
      authorizer_err  = "$context.authorizer.error"
      customer_id     = "$context.authorizer.customerId"
      role            = "$context.authorizer.role"
    })
  }

  default_route_settings {
    throttling_rate_limit    = var.throttling_rate_limit
    throttling_burst_limit   = var.throttling_burst_limit
    detailed_metrics_enabled = true
  }
}
