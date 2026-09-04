# Publicacao dos parametros que este repositorio possui (secao 2 dos Contratos).
# Sao os unicos pontos de acoplamento que outros repos podem consumir.

resource "aws_ssm_parameter" "apigw_endpoint" {
  name        = "/oficina/${var.environment}/apigw/endpoint"
  type        = "String"
  value       = aws_apigatewayv2_stage.default.invoke_url
  description = "URL base publica da API."
  overwrite   = true
}

resource "aws_ssm_parameter" "apigw_api_id" {
  name        = "/oficina/${var.environment}/apigw/api_id"
  type        = "String"
  value       = aws_apigatewayv2_api.this.id
  description = "Id do HTTP API."
  overwrite   = true
}
