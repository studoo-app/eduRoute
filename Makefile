PARALLELISM := $(shell nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 4)

.PHONY: all
all: install phpcbf phpcs phpstan rector phpunit phpbench

.PHONY: ci
ci: install phpcs phpstan rector phpunit

.PHONY: install
install: vendor/composer/installed.json

vendor/composer/installed.json: composer.json composer.lock
	@composer install $(INSTALL_FLAGS)
	@touch -c composer.json composer.lock vendor/composer/installed.json

.PHONY: phpunit
phpunit:
	@vendor/bin/phpunit

.PHONY: phpcbf
phpcbf:
	@vendor/bin/phpcbf --parallel=$(PARALLELISM) || true

.PHONY: phpcs
phpcs:
	@vendor/bin/phpcs --parallel=$(PARALLELISM) $(PHPCS_FLAGS)

.PHONY: phpstan
phpstan:
	@vendor/bin/phpstan analyse --memory-limit=-1

# Signale le code encore modernisable (sans le modifier).
# Informatif jusqu'à la fin de la phase 2 (préfixe "-") : supprimer le "-" ensuite.
.PHONY: rector
rector:
	-@vendor/bin/rector process --dry-run

# Applique les modernisations proposées par Rector : relire le diff ensuite !
.PHONY: rector-fix
rector-fix:
	@vendor/bin/rector process

# Xdebug fausse fortement les mesures : on le coupe dans les sous-processus
.PHONY: phpbench
phpbench:
	@XDEBUG_MODE=off vendor/bin/phpbench run -l dots --report aggregate --retry-threshold=15 --php-config='{"xdebug.mode":"off"}' $(PHPBENCH_OPTIONS)

# Compare au benchmark de référence stocké localement (tag "baseline_upstream")
.PHONY: phpbench-compare
phpbench-compare:
	@XDEBUG_MODE=off vendor/bin/phpbench run -l dots --report aggregate --retry-threshold=15 --php-config='{"xdebug.mode":"off"}' --ref=baseline_upstream $(PHPBENCH_OPTIONS)
