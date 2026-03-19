#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────
# build.sh - Congela Music Bot con PyInstaller (Linux y Windows)
#
# Uso:
#   Linux:   chmod +x build.sh && ./build.sh
#   Windows: bash build.sh   (desde Git Bash / MSYS2)
#            o ejecutar build.bat
# ─────────────────────────────────────────────────────────────

set -e

APP_NAME="MusicBot"
ENTRY="main.py"
ICON_WIN="icon.ico"

echo "=== Build $APP_NAME ==="

# Detectar SO
case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*) OS="windows" ;;
    Linux*)               OS="linux"   ;;
    Darwin*)              OS="macos"   ;;
    *)                    OS="unknown" ;;
esac
echo "SO detectado: $OS"

# Verificar Python
if ! command -v python3 &>/dev/null && ! command -v python &>/dev/null; then
    echo "ERROR: Python no encontrado. Instala Python 3.10+"
    exit 1
fi
PY=$(command -v python3 || command -v python)
echo "Python: $($PY --version)"

# Crear/activar venv si no existe
if [ ! -d ".venv" ]; then
    echo "Creando venv..."
    $PY -m venv .venv
fi

if [ "$OS" = "windows" ]; then
    source .venv/Scripts/activate
else
    source .venv/bin/activate
fi

# Instalar dependencias + PyInstaller
echo "Instalando dependencias..."
pip install -q -r requirements.txt
pip install -q pyinstaller

# Limpiar builds anteriores
rm -rf build/ dist/

# Construir argumentos de PyInstaller
ARGS=(
    --name "$APP_NAME"
    --onedir
    --windowed
    --noconfirm
    --clean
    # Incluir services.py como módulo
    --hidden-import=services
    # Dependencias que PyInstaller a veces no detecta
    --hidden-import=groq
    --hidden-import=yt_dlp
    --hidden-import=bs4
    --hidden-import=dotenv
    --hidden-import=requests
    --hidden-import=tkinter
    --collect-all=groq
    --collect-all=yt_dlp
    # Incluir .env.example como referencia
    --add-data=".env.example:."
)

# Icono (solo si existe)
if [ -f "$ICON_WIN" ]; then
    ARGS+=(--icon "$ICON_WIN")
fi

# Separador de ruta en --add-data: ; en Windows, : en Linux/Mac
if [ "$OS" = "windows" ]; then
    # Reemplazar : por ; en --add-data para Windows
    FIXED_ARGS=()
    for arg in "${ARGS[@]}"; do
        if [[ "$arg" == *".env.example:"* ]]; then
            FIXED_ARGS+=("${arg/:/;}")
        else
            FIXED_ARGS+=("$arg")
        fi
    done
    ARGS=("${FIXED_ARGS[@]}")
fi

echo ""
echo "Ejecutando PyInstaller..."
pyinstaller "${ARGS[@]}" "$ENTRY"

# Copiar .env.example al directorio de salida
cp .env.example "dist/$APP_NAME/"

echo ""
echo "=== Build completo ==="
echo "Salida: dist/$APP_NAME/"
echo ""
echo "Para ejecutar:"
if [ "$OS" = "windows" ]; then
    echo "  dist\\$APP_NAME\\$APP_NAME.exe"
else
    echo "  dist/$APP_NAME/$APP_NAME"
fi
echo ""
echo "IMPORTANTE: El usuario necesita tener instalado:"
echo "  - mpv (https://mpv.io) o VLC"
echo "  - ffmpeg (https://ffmpeg.org)"
echo "  - Un archivo .env con las credenciales (copiar .env.example)"
