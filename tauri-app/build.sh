#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────
# build.sh - Build Music Bot con Tauri + React + Vite
#
# Uso:
#   chmod +x build.sh && ./build.sh
#   ./build.sh dev     # modo desarrollo con hot-reload
# ─────────────────────────────────────────────────────────────

set -e

cd "$(dirname "$0")"

echo "=== Music Bot (Tauri) ==="

# Verificar dependencias
check_cmd() {
    if ! command -v "$1" &>/dev/null; then
        echo "ERROR: $1 no encontrado. $2"
        exit 1
    fi
}

check_cmd node "Instala Node.js 18+"
check_cmd cargo "Instala Rust: curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh"
check_cmd mpv "Instala mpv: sudo pacman -S mpv"

echo "Node: $(node --version)"
echo "Cargo: $(cargo --version | cut -d' ' -f2)"

# Instalar dependencias npm si faltan
if [ ! -d "node_modules" ]; then
    echo "Instalando dependencias npm..."
    npm install
fi

# Buscar puerto libre (rango 5100-5999)
find_free_port() {
    for port in $(seq 5100 5999); do
        if ! ss -tlnp | grep -q ":${port} " 2>/dev/null; then
            echo "$port"
            return
        fi
    done
    echo "5199"
}

MODE="${1:-build}"

if [ "$MODE" = "dev" ]; then
    PORT=$(find_free_port)
    echo ""
    echo "=== Modo desarrollo ==="
    echo "Puerto: $PORT"
    echo ""

    # Actualizar devUrl en tauri.conf.json
    sed -i "s|\"devUrl\": \"http://localhost:[0-9]*\"|\"devUrl\": \"http://localhost:${PORT}\"|" src-tauri/tauri.conf.json

    VITE_PORT=$PORT npx tauri dev
else
    echo ""
    echo "Compilando producción..."
    npx tauri build

    echo ""
    echo "=== Build completo ==="
    echo "Binario en: src-tauri/target/release/musicbot"
    echo ""
    echo "NOTA: Se necesita mpv instalado en el sistema"
fi
