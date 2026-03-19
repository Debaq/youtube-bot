#!/usr/bin/env bash
# run.sh - Lanza Music Bot
# Uso: ./run.sh

set -e
cd "$(dirname "$0")"

# ── Activar venv ──
if [ ! -d ".venv" ]; then
    echo "Creando venv..."
    python3 -m venv .venv
    source .venv/bin/activate
    pip install -q -r requirements.txt
else
    source .venv/bin/activate
fi

# ── Verificar .env ──
if [ ! -f ".env" ]; then
    cp .env.example .env
    echo "No existe .env, se copió desde .env.example."
    echo "Edita .env con tus credenciales y vuelve a ejecutar."
    exit 1
fi

# ── Lanzar bot ──
python main.py
