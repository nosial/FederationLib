all: target/release/net.nosial.federation.ncc target/web/net.nosial.federation.ncc target/debug/net.nosial.federation.ncc
target/release/net.nosial.federation.ncc:
	ncc build --configuration release --log-level debug
target/web/net.nosial.federation.ncc:
	ncc build --configuration web_release --log-level debug
target/debug/net.nosial.federation.ncc:
	ncc build --configuration debug --log-level debug

test:
	phpunit --configuration phpunit.xml


docs:
	phpdoc --config phpdoc.dist.xml

clean:
	rm -f target/release/net.nosial.federation.ncc
	rm -f target/web/net.nosial.federation.ncc
	rm -f target/debug/net.nosial.federation.ncc
	rm -rf target/docs
	rm -rf target/cache

.PHONY: all install clean test docs