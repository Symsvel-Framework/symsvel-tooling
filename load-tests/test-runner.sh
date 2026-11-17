#!/usr/bin/env bash
# Pure launcher checks; the fake k6 executable never performs HTTP requests.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/bin"
cat > "$tmp/bin/k6" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "${SYMSVEL_LOAD_URL}" "${SYMSVEL_LOAD_PROFILE}" "${SYMSVEL_LOAD_PATHS}" "${SYMSVEL_ALLOW_NON_LOOPBACK}" > "${SYMSVEL_TEST_MARKER}"
EOF
chmod +x "$tmp/bin/k6"
export PATH="$tmp/bin:$PATH"
export SYMSVEL_TEST_MARKER="$tmp/marker"

expect_fail() {
  local name="$1"
  shift
  rm -f "$SYMSVEL_TEST_MARKER"
  if bash "$root/load-tests/run.sh" "$@" >"$tmp/output" 2>&1; then
    echo "Expected rejection: $name" >&2
    exit 1
  fi
  if [[ -e "$SYMSVEL_TEST_MARKER" ]]; then
    echo "Benchmark was started despite invalid arguments: $name" >&2
    exit 1
  fi
}
expect_fail 'no url' --profile smoke
expect_fail 'invalid profile' --url http://127.0.0.1:8000 --profile infinite
expect_fail 'remote without opt-in' --url https://production.example.test
expect_fail 'credentials' --url http://user:password@localhost:8000
expect_fail 'path traversal' --url http://localhost:8000 --paths /../private
expect_fail 'query containing credentials' --url http://localhost:8000 --paths '/?api_key=secret'
expect_fail 'invalid port' --url http://localhost:99999
expect_fail 'invalid label' --url http://localhost --label '../bad'

bash "$root/load-tests/run.sh" --url http://127.0.0.1:8000 \
  --profile smoke --paths '/,/apiopen/languages' \
  --label local --results-dir "$tmp/results" >"$tmp/output"
test "$(sed -n '1p' "$SYMSVEL_TEST_MARKER")" = 'http://127.0.0.1:8000'
test "$(sed -n '2p' "$SYMSVEL_TEST_MARKER")" = 'smoke'
test "$(sed -n '3p' "$SYMSVEL_TEST_MARKER")" = '/,/apiopen/languages'
test "$(sed -n '4p' "$SYMSVEL_TEST_MARKER")" = '0'

bash "$root/load-tests/run.sh" --url https://project.local \
  --allow-non-loopback --profile load --results-dir "$tmp/results" >"$tmp/output"
test "$(sed -n '1p' "$SYMSVEL_TEST_MARKER")" = 'https://project.local'
test "$(sed -n '4p' "$SYMSVEL_TEST_MARKER")" = '1'

echo 'Load test launcher safety checks passed (no HTTP calls).'
