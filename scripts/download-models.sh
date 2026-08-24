#!/usr/bin/env bash
set -euo pipefail

# Deploy-time downloader for the local voice models served from /models/*.
# Models are gitignored (public/models/) so they are fetched here or on first
# run. Includes whisper tiny (STT), the piper Spanish TTS voice, and the piper
# runtime WASM (onnx + phonemize).
#
# Note: the runtime app never fetches models from a remote URL — it only loads
# from the local /models/* path. This script is the one-time provisioning step.

MODEL_DIR="public/models"
TINY_URL="https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-tiny.bin"
TINY_FILE="$MODEL_DIR/ggml-tiny.bin"

PIPER_BASE="https://huggingface.co/diffusionstudio/piper-voices/resolve/main"
PIPER_VOICE="es_ES-sharvard-medium"
PIPER_PATH="es/es_ES/sharvard/medium"
PIPER_ONNX="$MODEL_DIR/piper/$PIPER_PATH/$PIPER_VOICE.onnx"
PIPER_JSON="$MODEL_DIR/piper/$PIPER_PATH/$PIPER_VOICE.onnx.json"

PIPER_WASM_BASE="https://cdn.jsdelivr.net/npm/@diffusionstudio/piper-wasm@1.0.0/build"
PIPER_WASM_DIR="$MODEL_DIR/piper/wasm"

ONNX_DIR="$MODEL_DIR/piper/onnx"
ONNX_NODE_DIR="node_modules/onnxruntime-web/dist"
ONNX_CDN_BASE="https://cdnjs.cloudflare.com/ajax/libs/onnxruntime-web/1.27.0"

mkdir -p "$MODEL_DIR/piper/$PIPER_PATH" "$PIPER_WASM_DIR" "$ONNX_DIR"

fetch_if_missing() {
    local url="$1"
    local dest="$2"
    if [ -f "$dest" ]; then
        echo "Already present: $dest"
    else
        echo "Downloading $dest…"
        curl -L --fail --progress-bar "$url" -o "$dest"
    fi
}

# Whisper STT
fetch_if_missing "$TINY_URL" "$TINY_FILE"

# Piper Spanish TTS voice (onnx + config)
fetch_if_missing "$PIPER_BASE/$PIPER_PATH/$PIPER_VOICE.onnx" "$PIPER_ONNX"
fetch_if_missing "$PIPER_BASE/$PIPER_PATH/$PIPER_VOICE.onnx.json" "$PIPER_JSON"

# Piper phonemize runtime wasm
fetch_if_missing "$PIPER_WASM_BASE/piper_phonemize.wasm" "$PIPER_WASM_DIR/piper_phonemize.wasm"
fetch_if_missing "$PIPER_WASM_BASE/piper_phonemize.data" "$PIPER_WASM_DIR/piper_phonemize.data"

# onnxruntime wasm: prefer the installed node_modules copy (matches the bundled
# JS version), else fall back to the matching CDN release.
if [ -d "$ONNX_NODE_DIR" ]; then
    cp -n "$ONNX_NODE_DIR"/*.wasm "$ONNX_DIR/" 2>/dev/null || true
else
    for f in ort-wasm-simd-threaded.wasm ort-wasm-simd-threaded.jsep.wasm \
        ort-wasm-simd-threaded.jspi.wasm ort-wasm-simd-threaded.asyncify.wasm; do
        fetch_if_missing "$ONNX_CDN_BASE/$f" "$ONNX_DIR/$f"
    done
fi

echo "Done. Voice models and runtime wasm are ready under $MODEL_DIR."
