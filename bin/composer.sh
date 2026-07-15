#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

COMPOSE=(docker compose)

run_composer() {
  "${COMPOSE[@]}" run --rm composer "$@"
}

case "${1:-}" in
  validate)
    run_composer validate --strict --no-check-publish
    ;;
  install)
    run_composer install --no-interaction --prefer-dist
    ;;
  build|dump-autoload)
    run_composer dump-autoload -o --no-interaction
    ;;
  shell)
    "${COMPOSE[@]}" run --rm --entrypoint sh composer
    ;;
  ""|-h|--help|help)
    cat <<'EOF'
Usage: bin/composer.sh <command> [args...]

Commands:
  validate         Check composer.json
  install          Install dependencies and generate autoload
  build            Optimized dump-autoload (alias: dump-autoload)
  shell            Open shell inside composer container
  <composer-args>  Pass-through, e.g. bin/composer.sh show
EOF
    ;;
  *)
    run_composer "$@"
    ;;
esac
