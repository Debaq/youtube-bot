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
    --hidden-import=requests
    --hidden-import=tkinter
    --hidden-import=pystray
    --hidden-import=PIL
    --collect-all=groq
    --collect-all=yt_dlp
)

# Icono (solo si existe)
if [ -f "$ICON_WIN" ]; then
    ARGS+=(--icon "$ICON_WIN")
fi

# Separador de ruta en --add-data: ; en Windows, : en Linux/Mac
if [ "$OS" = "windows" ]; then
    :
fi

echo ""
echo "Ejecutando PyInstaller..."
pyinstaller "${ARGS[@]}" "$ENTRY"

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
echo "NOTA: Se necesita mpv instalado en el sistema"
