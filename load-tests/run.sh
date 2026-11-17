#!/usr/bin/env bash
# Executed by the developer, not by the deployed application.
set -euo pipefail

usage() {
  cat <<'EOF'
SymSvel load tests (local operator action)
Usage:
  bash load-tests/run.sh --url URL [--profile smoke|load|stress|spike|soak] \
    [--paths /,/apiopen/languages] [--label NAME] [--results-dir DIR] \
    [--allow-non-loopback]

The default profile is smoke. Only GET requests are generated.
Non-loopback hosts require --allow-non-loopback (also for custom local hostnames).
Never benchmark an external host without explicit authorization.
EOF
}

url=''
profile='smoke'
paths='/'
label='symsvel'
results_dir='load-tests/results'
allow_non_loopback='0'

while (($#)); do
  case "$1" in
    --url|--profile|--paths|--label|--results-dir)
      if (($# < 2)) || [[ -z "$2" ]]; then
        echo "Missing value for $1" >&2
        exit 2
      fi
      case "$1" in
        --url) url="$2" ;;
        --profile) profile="$2" ;;
        --paths) paths="$2" ;;
        --label) label="$2" ;;
        --results-dir) results_dir="$2" ;;
      esac
      shift 2 ;;
    --allow-non-loopback) allow_non_loopback='1'; shift ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ ! "$profile" =~ ^(smoke|load|stress|spike|soak)$ ]]; then
  echo 'Invalid load test profile.' >&2
  exit 2
fi
if [[ ! "$label" =~ ^[a-zA-Z0-9._-]{1,60}$ ]]; then
  echo 'Label must use 1–60 safe characters.' >&2
  exit 2
fi
if [[ ! "$url" =~ ^https?://(localhost|127\.0\.0\.1|\[::1\])(:[0-9]{1,5})?/?$ ]]; then
  if [[ ! "$url" =~ ^https?://[a-zA-Z0-9.-]+(:[0-9]{1,5})?/?$ ]] || [[ "$allow_non_loopback" != '1' ]]; then
    echo 'Only loopback HTTP(S) targets are permitted by default. Non-loopback targets require explicit --allow-non-loopback.' >&2
    exit 2
  fi
fi
if [[ "$url" =~ :([0-9]{1,5})/?$ ]]; then
  port="${BASH_REMATCH[1]}"
  if ((10#$port < 1 || 10#$port > 65535)); then
    echo 'Invalid TCP port.' >&2
    exit 2
  fi
fi
if [[ ! "$paths" =~ ^/[A-Za-z0-9/_.,-]+$ ]] || [[ "$paths" == *'..'* ]] || [[ "$paths" == *//* ]]; then
  echo 'Paths must be comma-separated literal absolute paths without queries, credentials or traversal.' >&2
  exit 2
fi
if [[ "$results_dir" == -* ]]; then
  echo 'Results directory may not start with a dash.' >&2
  exit 2
fi
if ! command -v k6 >/dev/null 2>&1; then
  echo 'k6 is not installed. Install it independently before running load tests.' >&2
  exit 127
fi

mkdir -p "$results_dir"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
summary="$results_dir/$timestamp-$label-$profile-summary.json"
echo "Target: $url | profile: $profile | GET paths: $paths"
echo "Summary: $summary"
echo 'No build, database mutation, authentication or remote service installation will be performed.'

SYMSVEL_LOAD_URL="$url" \
SYMSVEL_LOAD_PROFILE="$profile" \
SYMSVEL_LOAD_PATHS="$paths" \
SYMSVEL_LOAD_LABEL="$label" \
SYMSVEL_ALLOW_NON_LOOPBACK="$allow_non_loopback" \
  k6 run --summary-export "$summary" "$(cd "$(dirname "$0")" && pwd)/scenarios.js"
