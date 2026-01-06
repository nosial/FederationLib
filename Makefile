all: target/release/net.nosial.federation.ncc target/debug/net.nosial.federation.ncc
target/release/net.nosial.federation.ncc:
	ncc build --configuration release --log-level debug
target/debug/net.nosial.federation.ncc:
	ncc build --configuration debug --log-level debug

test:
	phpunit --configuration phpunit.xml


docs:
	phpdoc --config phpdoc.dist.xml

clean:
	rm target/release/net.nosial.federation.ncc
	rm target/debug/net.nosial.federation.ncc
	rm target/docs
	rm target/cache

.PHONY: all install clean test docs