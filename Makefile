SHELL=/bin/bash

# DIRECTORIES
BASE_DIR = .
SRC_DIR = $(BASE_DIR)/src
VENDOR_DIR = $(BASE_DIR)/vendor
TESTS_DIR = $(BASE_DIR)/tests

# PHP BINARY
PHP_BIN = php
# COMPOSER STUFF
COMPOSER_BIN = composer
COMPOSER_LOCK = $(BASE_DIR)/composer.lock
# PHP CS FIXER STUFF
PHPCS_FIXER_SCRIPT = $(VENDOR_DIR)/bin/php-cs-fixer
PHPCS_FIXER_LOCK = $(BASE_DIR)/.php_cs.cache
# PHPUNIT STUFF
PHPUNIT_SCRIPT = $(VENDOR_DIR)/bin/phpunit
PHPUNIT_BOOTSTRAP_SCRIPT = $(TESTS_DIR)/Bootstrap.php
PHPUNIT_LOCK = $(BASE_DIR)/.phpunit.lock
# PHPSTAN STUFF
PHPSTAN_SCRIPT = $(VENDOR_DIR)/bin/phpstan
PHPSTAN_LOCK = $(BASE_DIR)/.phpstan.lock

# TARGET ALIASES
COMPOSER_TARGET = $(COMPOSER_LOCK)
PHPCS_FIX_TARGET = $(PHPCS_FIXER_LOCK)
PHPCS_CHECK_TARGET = $(PHPCS_FIXER_LOCK)
PHPUNIT_TARGET = $(PHPUNIT_LOCK)
PHPSTAN_TARGET = $(PHPSTAN_LOCK)
BUILD_TARGET = $(COMPOSER_TARGET) $(PHPUNIT_TARGET) $(PHPSTAN_TARGET) $(PHPCS_FIXER_TARGET)

# MACROS
macro_find_phpfiles = $(shell find $(1) -type f -name "*.php")

# SOURCECODE FILESETS
PHP_FILES = $(call macro_find_phpfiles,$(SRC_DIR))
PHP_CORE_FILES = $(call macro_find_phpfiles,$(CORE_DIR))
PHP_CORE_API_FILES = $(call macro_find_phpfiles,$(CORE_API_DIR))
PHP_TEST_FILES = $(call macro_find_phpfiles,$(TESTS_DIR))
PHP_UNITTEST_FILES = $(call macro_find_phpfiles,$(TESTS_DIR))

# TARGETS
.PHONY: all
all: build

.PHONY: clean
clean: mostlyclean
	@-test ! -d $(VENDOR_DIR) || rm -rfv $(VENDOR_DIR)/*

.PHONY: deps
deps: composer

.PHONY: build
build: $(BUILD_TARGET)

.PHONY: composer
composer: $(COMPOSER_TARGET)

$(COMPOSER_TARGET) $(PHPCS_FIXER_SCRIPT) $(PHPUNIT_SCRIPT) $(PHPSTAN_SCRIPT): $(BASE_DIR)/composer.json
	$(COMPOSER_BIN) update
	@touch $@


.PHONY: phpcs-check
phpcs-check: $(PHPCS_FIXER_TARGET)

$(PHPCS_CHECK_TARGET): $(PHPCS_FIXER_SCRIPT) $(PHP_FILES) $(COMPOSER_LOCK)
	$(PHP_BIN) $(PHPCS_FIXER_SCRIPT)  fix --config=.php_cs.dist -v --dry-run --allow-risky --stop-on-violation --using-cache=no
	@touch $@


.PHONY: phpcs-fix
phpcs-fix: $(PHPCS_FIXER_TARGET)

$(PHPCS_FIX_TARGET): $(PHPCS_FIXER_SCRIPT) $(PHP_FILES) $(COMPOSER_LOCK)
	$(PHP_BIN) $(PHPCS_FIXER_SCRIPT)  fix --config=.php_cs.dist -v
	@touch $@


.PHONY: test
test: $(PHPUNIT_TARGET)

.PHONY: phpunit
phpunit: $(PHPUNIT_TARGET)

$(PHPUNIT_TARGET): $(PHPUNIT_SCRIPT) $(PHP_FILES) $(PHP_UNITTEST_FILES)
	$(PHP_BIN) $(PHPUNIT_SCRIPT) --stderr --debug --bootstrap $(PHPUNIT_BOOTSTRAP_SCRIPT) $(TESTS_DIR)
	@touch $@


.PHONY: phpstan
phpstan: $(PHPSTAN_TARGET)

$(PHPSTAN_TARGET): $(PHPSTAN_SCRIPT) $(PHP_FILES) $(PHP_TEST_FILES)
	$(PHP_BIN) $(PHPSTAN_SCRIPT) analyse -c phpstan.neon $(SRC_DIR) $(TESTS_DIR)
	@touch $@
