output "api_endpoint" {
  description = "URL base publica da API."
  value       = aws_apigatewayv2_stage.default.invoke_url
}

output "api_id" {
  description = "Id do HTTP API."
  value       = aws_apigatewayv2_api.this.id
}

output "auth_cpf_function_name" {
  description = "Nome da Lambda de identificacao por CPF."
  value       = aws_lambda_function.auth.function_name
}

output "jwt_authorizer_function_name" {
  description = "Nome da Lambda authorizer."
  value       = aws_lambda_function.authorizer.function_name
}

output "vpc_link_id" {
  description = "Id do VPC Link usado pela integracao HTTP_PROXY."
  value       = aws_apigatewayv2_vpc_link.this.id
}

output "bref_layer_arn" {
  description = "ARN do layer Bref efetivamente aplicado. Conferir contra runtimes.bref.sh."
  value       = local.bref_php_layer_arn
}

output "aws_account_id" {
  description = "Conta em que o stack foi aplicado."
  value       = data.aws_caller_identity.current.account_id
}
