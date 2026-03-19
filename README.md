# Music Bot - DJ con IA

Bot de musica colaborativo para labs, oficinas y espacios compartidos. Los usuarios piden canciones desde una web estilo Spotify, una IA (Groq) las procesa, y el bot las reproduce automaticamente con mpv.

## Arquitectura

```
Usuarios (web)  --->  PHP Panel + API  --->  SQLite
                                                ^
Python Bot (Tkinter)  <----  poll cada 15s  ----|
      |
      v
   yt-dlp + mpv (reproduccion local)
```

**Python** (`main.py` + `services.py`) — Bot de escritorio que gestiona la cola, busca en YouTube, reproduce con mpv, y consulta la IA.

**PHP** (`web/`) — Panel web SPA donde los usuarios piden canciones, votan, reaccionan, y configuran el sistema.

**SQLite** — Base de datos compartida con canciones, votos, playlists, schedule y configuracion.

## Funcionalidades

### Reproduccion
- Cola de reproduccion con prioridades y votos
- Buffer interno pre-generado (15 canciones listas)
- Busqueda en YouTube via yt-dlp
- Reproduccion con mpv (audio o video, toggle en caliente)
- Control de volumen desde la web en tiempo real
- Skip/Pause desde la web
- Deduplicacion inteligente (cola + buffer, case insensitive)

### IA (Groq)
- Sugerencias musicales basadas en solicitudes de usuarios
- Comentarios de DJ generados por IA para cada cancion
- Contexto historico: la IA aprende que canciones gustan en el lab
- Deteccion de solicitudes directas (bypass IA para "ponme X")

### Sistema de listas
- **Dorada** (score >= 5) — Exitos del lab
- **Verde** (score >= 1) — Aprobadas
- **Blanca** — Neutras
- **Negra** — Blacklist automatica (2+ skips por downvotes)

### Playlists automaticas
| Playlist | Cuando | Criterio |
|----------|--------|----------|
| Sesion diaria | 20:00 cada dia | Canciones del dia por votos |
| Lo mejor de la semana | Domingos 20:00 | Top 20 por votos netos |
| Mas escuchadas | Dia 1 de cada mes | Top 25 por reproducciones |
| Favoritas del lab | Cuando hay 20+ gold | Todas las doradas |

### Auto-aprendizaje
- Buffer por horario: rellena con canciones populares a la misma hora en dias anteriores
- Contexto para Groq: envia canciones doradas como referencia de gustos
- Mezcla 60% doradas + 40% sugerencias IA en el buffer

### Automatizacion
- Auto-start: el bot arranca en modo automatico
- Schedule pre-armado con 8 bloques horarios (configurable desde la web)
- Preset automatico por clima y reacciones del publico
- Racha visible de canciones sin downvotes

### Web (panel.php)
- UI estilo Spotify (dark theme, layout con sidebar)
- Caratulas de YouTube en player, cola, historial y playlists
- Iconos Lucide profesionales
- Reacciones rapidas con iconos animados (fuego, musica, corazon, luna, calavera)
- Ranking de DJs por canciones doradas/verdes
- Calendario con historial por dia
- Ajustes: ambiente, ciudad, video, auto-mode, auto-fill
- Responsive (mobile oculta sidebar)

## Requisitos

### Servidor (PHP)
- PHP 7.4+ con SQLite3
- Servidor web (Apache, Nginx, o `php -S`)

### Cliente (Python)
- Python 3.10+
- mpv (reproduccion de audio/video)
- Cuenta en [Groq](https://console.groq.com/) (API key gratuita)

## Instalacion

### 1. Clonar

```bash
git clone <url> music-bot
cd music-bot
```

### 2. Servidor web

Copiar `web/` al servidor PHP. Configurar la API key:

```bash
# Opcion A: variable de entorno
export MUSICBOT_API_KEY="tu_clave_secreta"

# Opcion B: editar web/config.php directamente
```

Los dominios de email permitidos se configuran en `web/config.php` funcion `validar_email()`.

### 3. Bot Python

```bash
# Linux
chmod +x run.sh
./run.sh

# Windows
run.bat
```

Al primer arranque se abre la ventana de **Configuracion** donde se ingresan:

| Campo | Descripcion |
|-------|-------------|
| Servidor URL | URL del servidor PHP (ej: `https://tuserver.com/musica_vote`) |
| API Key servidor | La misma clave que configuraste en el servidor |
| Groq API Key | Tu API key de [Groq Console](https://console.groq.com/) |
| Groq Modelo | Modelo a usar (default: `llama-3.3-70b-versatile`) |
| Ciudad | Ciudad para el clima (default: `Valdivia`) |

La configuracion se guarda en `config.json` (excluido de git).

### 4. Instalar mpv

```bash
# Arch Linux
sudo pacman -S mpv

# Ubuntu/Debian
sudo apt install mpv

# macOS
brew install mpv

# Windows
# Descargar de https://mpv.io/
```

## Schedule por defecto

El sistema viene con un horario pre-armado para un lab universitario:

| Hora | Preset |
|------|--------|
| 08-10 | Chill & Lo-fi |
| 10-12 | Estudio tranquilo |
| 12-13 | Pop actual |
| 13-14 | Cumbia & Latina |
| 14-16 | Indie & Alternativo |
| 16-17 | Rock clasico |
| 17-18 | Reggaeton & Urbano |
| 18-20 | Fiesta total |

Se inserta automaticamente si la tabla `dj_schedule` esta vacia. Modificable desde la web en la seccion Horario.

## Estructura

```
music-bot/
├── main.py              # Bot Python (Tkinter GUI + logica)
├── services.py          # Groq, YouTube, Player, API client, Clima
├── config.json          # Configuracion local (no se sube a git)
├── run.sh / run.bat     # Scripts de ejecucion
├── build.sh / build.bat # Build con PyInstaller
├── requirements.txt     # Dependencias Python
└── web/
    ├── config.php       # Config DB, auth, helpers
    ├── api.php          # API REST para Python
    ├── panel.php        # Panel web SPA
    ├── migrations.php   # Migraciones de DB
    ├── index.php        # Login
    ├── logout.php       # Logout
    ├── style.css        # Estilos Spotify-like
    └── db/              # SQLite (no se sube a git)
```

## Licencia

MIT
