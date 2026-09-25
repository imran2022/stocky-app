#!/bin/bash
# Orchestrates the concurrency stress test in build_concurrency_stress.php:
# setup -> N parallel OS-process workers racing on the same stock row ->
# verify -> cleanup. See that file's header for what each invariant means.
#
# Usage: bash tests/Regression/run_concurrency_stress.sh [workers] [creates_per_worker] [qty_per_sale]
set -e
cd "$(dirname "$0")/../.."

WORKERS="${1:-30}"
CREATES_PER_WORKER="${2:-9}"
QTY_PER_SALE="${3:-2}"
OUT_DIR="$(mktemp -d)"

echo "Setting up dedicated test product + stock row..."
SETUP_JSON=$(php tests/Regression/build_concurrency_stress.php setup)
echo "$SETUP_JSON"

PRODUCT_ID=$(php -r '$d=json_decode($argv[1],true); echo $d["product_id"];' "$SETUP_JSON")
WAREHOUSE_ID=$(php -r '$d=json_decode($argv[1],true); echo $d["warehouse_id"];' "$SETUP_JSON")
CLIENT_ID=$(php -r '$d=json_decode($argv[1],true); echo $d["client_id"];' "$SETUP_JSON")
USER_ID=$(php -r '$d=json_decode($argv[1],true); echo $d["user_id"];' "$SETUP_JSON")
UNIT_ID=$(php -r '$d=json_decode($argv[1],true); echo $d["unit_id"];' "$SETUP_JSON")
STARTING_QTE=$(php -r '$d=json_decode($argv[1],true); echo $d["starting_qte"];' "$SETUP_JSON")

TOTAL=$((WORKERS * CREATES_PER_WORKER))
echo "Launching $WORKERS parallel OS processes x $CREATES_PER_WORKER creates each ($TOTAL total attempts), qty=$QTY_PER_SALE each, starting stock=$STARTING_QTE..."

for i in $(seq 1 "$WORKERS"); do
  php tests/Regression/build_concurrency_stress.php worker "w$i" "$PRODUCT_ID" "$WAREHOUSE_ID" "$CLIENT_ID" "$USER_ID" "$CREATES_PER_WORKER" "$QTY_PER_SALE" "$UNIT_ID" \
    > "$OUT_DIR/worker_$i.jsonl" 2>"$OUT_DIR/worker_$i.err" &
done
wait
echo "All workers finished. Output in $OUT_DIR"

echo "Verifying invariants..."
php tests/Regression/build_concurrency_stress.php verify "$PRODUCT_ID" "$WAREHOUSE_ID" "$STARTING_QTE"

echo "Cleaning up test data..."
php tests/Regression/build_concurrency_stress.php cleanup "$PRODUCT_ID" "$WAREHOUSE_ID"
