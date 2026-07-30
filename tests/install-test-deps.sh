#!/bin/bash
#
# Install the PHPUnit toolchain for the unit suite. Runs INSIDE the tests-cli
# container, where the plugin is mounted under wp-content/plugins.
#
#   wp-env run tests-cli -- bash /var/www/html/wp-content/plugins/wp-soli-passport-plugin/tests/install-test-deps.sh
#
# Two pins matter:
#   phpunit ^9.6   the WordPress test suite still calls
#                  PHPUnit\Util\Test::parseTestMethodAnnotations(), removed in
#                  PHPUnit 10. The wp-env image ships 10, so it is downgraded here.
#   polyfills ^2.0 the matching Yoast polyfills range for PHPUnit <= 9.
#
# Everything lands in the container's composer home (a Docker volume), so the
# plugin itself needs no composer.json.

set -e

COMPOSER_HOME_DIR="$(composer -n global config home)"
POLYFILLS="${COMPOSER_HOME_DIR}/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php"

if [ -f "${POLYFILLS}" ] && phpunit --version 2>/dev/null | grep -q 'PHPUnit 9'; then
	echo "PHPUnit test dependencies already installed."
	exit 0
fi

echo "Installing PHPUnit 9 and the Yoast polyfills into the tests container..."
# -W so the transitive packages the image locked for PHPUnit 10 can be downgraded.
composer global require --dev --no-interaction --quiet -W \
	'phpunit/phpunit:^9.6' \
	'yoast/phpunit-polyfills:^2.0'

echo "Installed $(phpunit --version | head -1)."
