@echo off
REM build.bat - Congela Music Bot con PyInstaller (Windows nativo)
REM Uso: doble click o ejecutar desde cmd

set APP_NAME=MusicBot
set ENTRY=main.py

echo === Build %APP_NAME% ===

REM Verificar Python
python --version >nul 2>&1
if errorlevel 1 (
    echo ERROR: Python no encontrado. Instala Python 3.10+
    pause
    exit /b 1
)

REM Crear venv si no existe
if not exist .venv (
    echo Creando venv...
    python -m venv .venv
)

call .venv\Scripts\activate.bat

REM Instalar dependencias
echo Instalando dependencias...
pip install -q -r requirements.txt
pip install -q pyinstaller

REM Limpiar builds anteriores
if exist build rmdir /s /q build
if exist dist rmdir /s /q dist

echo.
echo Ejecutando PyInstaller...
pyinstaller ^
    --name %APP_NAME% ^
    --onedir ^
    --windowed ^
    --noconfirm ^
    --clean ^
    --hidden-import=services ^
    --hidden-import=groq ^
    --hidden-import=yt_dlp ^
    --hidden-import=bs4 ^
    --hidden-import=dotenv ^
    --hidden-import=requests ^
    --hidden-import=tkinter ^
    --collect-all=groq ^
    --collect-all=yt_dlp ^
    --add-data=".env.example;." ^
    %ENTRY%

REM Copiar .env.example
copy .env.example "dist\%APP_NAME%\" >nul

echo.
echo === Build completo ===
echo Salida: dist\%APP_NAME%\
echo.
echo Para ejecutar: dist\%APP_NAME%\%APP_NAME%.exe
echo.
echo IMPORTANTE: El usuario necesita tener instalado:
echo   - mpv (https://mpv.io) o VLC
echo   - ffmpeg (https://ffmpeg.org)
echo   - Un archivo .env con las credenciales (copiar .env.example)
echo.
pause
