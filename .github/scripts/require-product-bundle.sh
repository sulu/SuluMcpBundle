#!/usr/bin/env bash

# Adds sulu/product-bundle to composer.json for the "product bundle" workflow.
#
# The bundle has no release and requires the unreleased sulu/sulu 3.1. Composer honours
# the "@dev" of its constraint only in the root package, so both dev branches are
# required here and not in the committed composer.json. Once sulu/sulu 3.1 and the
# product bundle are released, this script and the separate workflow can go.

set -eu

composer require --no-update "sulu/sulu:3.1.x-dev"
composer require --no-update --dev "sulu/product-bundle:3.0.x-dev"
