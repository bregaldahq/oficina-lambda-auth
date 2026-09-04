# Acoplamento entre repositorios e' SOMENTE por SSM (secao 2 dos Contratos).
# Nada de `terraform_remote_state` apontando para o estado de outro repo.

data "aws_caller_identity" "current" {}

data "aws_ssm_parameter" "vpc_id" {
  name = "/oficina/${var.environment}/network/vpc_id"
}

data "aws_ssm_parameter" "private_subnet_ids" {
  name = "/oficina/${var.environment}/network/private_subnet_ids"
}

# SG que concede acesso ao RDS:3306. Criado pelo repo de banco e anexado aqui
# na Lambda auth-cpf — que e' a unica das duas que entra na VPC.
data "aws_ssm_parameter" "db_client_sg_id" {
  name = "/oficina/${var.environment}/db/client_sg_id"
}

data "aws_ssm_parameter" "db_secret_arn" {
  name = "/oficina/${var.environment}/db/secret_arn"
}

data "aws_ssm_parameter" "auth_secret_arn" {
  name = "/oficina/${var.environment}/auth/secret_arn"
}

# Listener :80 do NLB interno publicado pelo repo de k8s — alvo da integracao
# HTTP_PROXY via VPC Link.
data "aws_ssm_parameter" "nlb_listener_arn" {
  name = "/oficina/${var.environment}/nlb/listener_arn"
}

data "aws_ssm_parameter" "vpc_cidr" {
  name = "/oficina/${var.environment}/network/vpc_cidr"
}
