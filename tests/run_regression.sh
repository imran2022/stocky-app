#!/usr/bin/env bash
# Runs every tests/Regression/*.php script and prints one PASS/FAIL line each.
#   RESET_CMD='<command that restores a clean test database>'  (run before every script; scripts write to the DB)
#   ./tests/run_regression.sh [pattern]      e.g. ./tests/run_regression.sh audit_b4_
# Exit code is the number of failed scripts. Point .env at a THROW-AWAY database, never at live data.
cd "$(dirname "$0")/.." || exit 1
pattern="${1:-}"; fail=0
for f in tests/Regression/*"$pattern"*.php; do
  case "$(basename "$f")" in _*) continue;; esac
  [ -n "$RESET_CMD" ] && bash -c "$RESET_CMD" >/dev/null 2>&1
  res=$(AUDIT_FK_OFF=1 timeout 300 php "$f" 2>&1 | tail -3 | tr '\n' ' ' | cut -c1-160)
  case "$res" in *PASS*) echo "PASS $(basename "$f")";; *) echo "FAIL $(basename "$f") | $res"; fail=$((fail+1));; esac
done
echo "failed scripts: $fail"; exit $fail
