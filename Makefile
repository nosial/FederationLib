# Variables
DEFAULT_CONFIGURATION ?= release
LOG_LEVEL = debug

# Default Target
all: release debug

# Build Steps
release:
	ncc build --config=release --log-level $(LOG_LEVEL)
debug:
	ncc build --config=debug --log-level $(LOG_LEVEL)


install: release
	ncc package install --package=build/release/net.nosial.federation.ncc --skip-dependencies --build-source --reinstall -y --log-level $(LOG_LEVEL)

test: release
	[ -f phpunit.xml ] || { echo "phpunit.xml not found"; exit 1; }
	phpunit

clean:
	rm -rf build

.PHONY: all install test clean release debug