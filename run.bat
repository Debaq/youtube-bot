@echo off
REM run.bat - Lanza Music Bot (Windows)
REM Uso: doble click

cd /d "%~dp0"

if not exist .venv (
    echo Creando venv...
    python -m venv .venv
    call .venv\Scripts\activate.bat
    pip install -q -r requirements.txt
) else (
    call .venv\Scripts\activate.bat
)

if not exist .env (
    copy .env.example .env
    echo No existe .env, se copio desde .env.example.
    echo Edita .env con tus credenciales y vuelve a ejecutar.
    pause
    exit /b 1
)

python main.py
