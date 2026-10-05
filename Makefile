all: target/release/net.nosial.federation.ncc target/web/net.nosial.federation.ncc target/debug/net.nosial.federation.ncc test-plugin
target/release/net.nosial.federation.ncc:
	ncc build --configuration release --log-level debug
target/web/net.nosial.federation.ncc:
	ncc build --configuration web_release --log-level debug
target/debug/net.nosial.federation.ncc:
	ncc build --configuration debug --log-level debug

TEST_PLUGIN = tests/TestPlugin/target/release/net.nosial.test_plugin.ncc
TEST_COMPOSE = docker compose -f docker-compose.yml -f docker-compose.test.yml

test-plugin: $(TEST_PLUGIN)
$(TEST_PLUGIN): $(shell find tests/TestPlugin/src -type f) tests/TestPlugin/project.yml
	cd tests/TestPlugin && ncc build --configuration release --log-level debug

# Starts the docker environment from Dockerfile.test, with the TestPlugin installed and enabled
test-env:
	$(TEST_COMPOSE) up -d --build

test-env-down:
	$(TEST_COMPOSE) down -v

test: $(TEST_PLUGIN)
	phpunit --configuration phpunit.xml


docs:
	phpdoc --config phpdoc.dist.xml

clean:
	rm -f target/release/net.nosial.federation.ncc
	rm -f target/web/net.nosial.federation.ncc
	rm -f target/debug/net.nosial.federation.ncc
	rm -rf tests/TestPlugin/target
	rm -rf target/docs
	rm -rf target/cache

.PHONY: all install clean test test-plugin test-env test-env-down docs
