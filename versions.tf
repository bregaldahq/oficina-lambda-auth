terraform {
  required_version = ">= 1.6.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.60"
    }
  }

  # Backend parametrizado via -backend-config no `terraform init`.
  #   terraform init -backend-config=backend-hml.hcl
  backend "s3" {}
}
