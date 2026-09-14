#!/usr/bin/env bash

# Adds sulu/product-bundle and the sulu/sulu 3.1 it requires to composer.json for the
# "product bundle" workflow. The bundle is optional, so neither is part of the committed
# composer.json.

set -eu

composer require --no-update "sulu/sulu:3.1.x-dev"
composer require --no-update --dev "sulu/product-bundle:3.0.x-dev"
