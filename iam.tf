# ---------------------------------------------------------------------------
# IAM minimo por funcao. Duas roles distintas de proposito: o authorizer roda
# fora da VPC e nao pode ler o segredo de banco.
# ---------------------------------------------------------------------------

data "aws_iam_policy_document" "lambda_assume_role" {
  statement {
    effect  = "Allow"
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["lambda.amazonaws.com"]
    }
  }
}

# --- auth-cpf --------------------------------------------------------------

resource "aws_iam_role" "auth" {
  name               = "${local.auth_function_name}-role"
  assume_role_policy = data.aws_iam_policy_document.lambda_assume_role.json
}

data "aws_iam_policy_document" "auth" {
  statement {
    sid    = "WriteOwnLogs"
    effect = "Allow"
    actions = [
      "logs:CreateLogStream",
      "logs:PutLogEvents",
    ]
    resources = ["${aws_cloudwatch_log_group.auth.arn}:*"]
  }

  statement {
    sid       = "ReadAuthAndDbSecrets"
    effect    = "Allow"
    actions   = ["secretsmanager:GetSecretValue"]
    resources = compact([local.auth_secret_arn, local.db_secret_arn, var.newrelic_license_key_secret_id])
  }
}

resource "aws_iam_role_policy" "auth" {
  name   = "${local.auth_function_name}-policy"
  role   = aws_iam_role.auth.id
  policy = data.aws_iam_policy_document.auth.json
}

# Necessario para criar/derrubar as ENIs da funcao dentro da VPC. Anexado SO' aqui:
# o authorizer roda fora da VPC e nao precisa dessa permissao.
resource "aws_iam_role_policy_attachment" "auth_vpc_access" {
  role       = aws_iam_role.auth.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSLambdaVPCAccessExecutionRole"
}

# --- jwt-authorizer --------------------------------------------------------

resource "aws_iam_role" "authorizer" {
  name               = "${local.authorizer_function_name}-role"
  assume_role_policy = data.aws_iam_policy_document.lambda_assume_role.json
}

data "aws_iam_policy_document" "authorizer" {
  statement {
    sid    = "WriteOwnLogs"
    effect = "Allow"
    actions = [
      "logs:CreateLogStream",
      "logs:PutLogEvents",
    ]
    resources = ["${aws_cloudwatch_log_group.authorizer.arn}:*"]
  }

  # Somente o segredo de auth. O de banco fica de fora por principio de menor
  # privilegio: o authorizer nunca fala com o RDS.
  statement {
    sid       = "ReadAuthSecret"
    effect    = "Allow"
    actions   = ["secretsmanager:GetSecretValue"]
    resources = compact([local.auth_secret_arn, var.newrelic_license_key_secret_id])
  }
}

resource "aws_iam_role_policy" "authorizer" {
  name   = "${local.authorizer_function_name}-policy"
  role   = aws_iam_role.authorizer.id
  policy = data.aws_iam_policy_document.authorizer.json
}
