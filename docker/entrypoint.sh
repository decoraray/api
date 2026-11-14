#!/bin/sh
# Start php-fpm and nginx; if either exits, exit too so Docker restarts the
# container (restart: unless-stopped) instead of leaving a half-dead API.
set -e

# .env is mounted at runtime, so the config cache must be built here, not at
# image build time. Failure is not fatal: Laravel just runs uncached.
php artisan config:cache || echo "WARN: config:cache failed, running uncached" >&2
php artisan route:cache  || echo "WARN: route:cache failed, running uncached" >&2
php artisan event:cache  || echo "WARN: event:cache failed, running uncached" >&2

php-fpm -F &
fpm=$!
nginx -g 'daemon off;' &
web=$!

trap 'kill -TERM $fpm $web 2>/dev/null' TERM INT

# POSIX sh has no `wait -n`; poll both children instead.
while kill -0 $fpm 2>/dev/null && kill -0 $web 2>/dev/null; do
  sleep 2
done
echo "php-fpm or nginx exited; stopping container" >&2
kill -TERM $fpm $web 2>/dev/null || true
wait
exit 1
