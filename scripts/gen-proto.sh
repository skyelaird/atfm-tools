#!/usr/bin/env bash
# Generate PHP stubs from the .proto files in vendor-forks/atfm-protobuf/
# into public/proto-gen/ so they can be committed or deployed without
# needing protoc on the IONOS webspace.
#
# Prereqs:
#   - protoc installed locally (https://github.com/protocolbuffers/protobuf/releases)
#   - grpc_php_plugin only needed if you want gRPC stubs (optional)

set -euo pipefail

cd "$(dirname "$0")/.."

PROTO_SRC="vendor-forks/atfm-protobuf"
OUT_DIR="src/Proto"

if [[ ! -d "$PROTO_SRC" ]]; then
    echo "error: $PROTO_SRC not found. Did you run 'git submodule update --init'?" >&2
    exit 1
fi

command -v protoc >/dev/null || { echo "error: protoc not installed" >&2; exit 1; }

mkdir -p "$OUT_DIR"

echo "==> generating PHP stubs into $OUT_DIR"
find "$PROTO_SRC" -name '*.proto' -print0 | while IFS= read -r -d '' proto; do
    echo "    $proto"
    protoc \
        --proto_path="$PROTO_SRC" \
        --php_out="$OUT_DIR" \
        "$proto"
done

echo "==> done"
