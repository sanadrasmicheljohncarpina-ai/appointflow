#!/bin/sh
set -eu
find app config public public_booking setup scripts cron -name "*.php" -print0 | xargs -0 -n1 php -l
