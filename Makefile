.PHONY: install test stan package fmt validate

install:
	composer install

test:
	vendor/bin/phpunit

stan:
	vendor/bin/phpstan analyse --no-progress

package:
	./scripts/build-package.sh

fmt:
	terraform fmt -recursive

validate:
	terraform init -backend=false && terraform validate
