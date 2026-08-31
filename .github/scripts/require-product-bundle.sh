#!/usr/bin/env bash

# Adds sulu/product-bundle to composer.json for the "product bundle" workflow.
#
# The bundle requires an unreleased sulu/sulu from a fork. Composer honours a
# "repositories" entry and an inline alias only in the root package, so a consumer has
# to restate both - which is why they live here and not in the committed composer.json.
# Once sulu/sulu#9046 is released and sulu/product-bundle requires a tag again, this
# script shrinks to the single "composer require" at the bottom.

set -eu

composer config repositories.sulu-fork '{"type":"vcs","url":"https://github.com/Prokyonn/sulu.git","no-api":true,"canonical":false}'
composer config minimum-stability dev
composer config prefer-stable true

composer require --no-update "sulu/sulu:dev-feature/content-resolver-path as 3.0.9"
composer require --no-update --dev "sulu/product-bundle:3.0.x-dev"
