#!/usr/bin/env bash
# Monta o zip enviado para as duas Lambdas.
#
# As duas funcoes compartilham EXATAMENTE o mesmo artefato — o que muda entre elas
# e' so' o `handler` configurado no Terraform. Isso garante que o JwtProvider que
# emite o token e o que valida sejam o mesmo byte a byte.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

OUT="${1:-build/lambda.zip}"

rm -rf build
mkdir -p "$(dirname "$OUT")"

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative

zip -qr "$OUT" \
  src \
  vendor \
  handler-auth.php \
  handler-authorizer.php \
  composer.json \
  -x '*.git*'

echo "pacote gerado: $OUT ($(du -h "$OUT" | cut -f1))"
