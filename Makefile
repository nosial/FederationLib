all: build/release/net.nosial.federation.ncc build/debug/net.nosial.federation.ncc
build/release/net.nosial.federation.ncc:
	ncc build --configuration release --log-level debug
build/debug/net.nosial.federation.ncc:
	ncc build --configuration debug --log-level debug

test:
	phpunit --configuration phpunit.xml


docs:
	phpdoc --config phpdoc.dist.xml

clean:
	rm build/release/net.nosial.federation.ncc
	rm build/debug/net.nosial.federation.ncc
	rm target/docs
	rm target/cache

.PHONY: all install clean test docs