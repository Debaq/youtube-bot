"""Servicios backend: Groq LLM, scraping web, reproductor y cliente API PHP."""

import json
import os
import platform
import socket
import subprocess
import shutil
import tempfile

import requests
from bs4 import BeautifulSoup

IS_WINDOWS = platform.system() == "Windows"
HAS_AF_UNIX = hasattr(socket, "AF_UNIX")


class GroqService:
    """Cliente para la API de Groq (LLMs)."""

    def __init__(self, api_key, model="llama-3.3-70b-versatile"):
        from groq import Groq
        self.client = Groq(api_key=api_key)
        self.model = model

    def comentar_cancion(self, titulo, artista, votos_up=0, votos_down=0):
        """Genera un comentario corto y divertido del DJ sobre la canción actual."""
        system_prompt = (
            "Eres un DJ divertido y carismático. Genera UNA frase corta y divertida "
            "sobre la canción que está sonando. Puede ser un dato curioso, un chiste, "
            "una referencia cultural o un comentario sobre el ambiente. "
            "Máximo 2 líneas. Usa un tono informal y juvenil. "
            "Responde SOLO con la frase, sin comillas ni formato extra."
        )

        user_msg = f"Canción: {titulo} - {artista}. Votos: +{votos_up} -{votos_down}"

        response = self.client.chat.completions.create(
            model=self.model,
            messages=[
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_msg},
            ],
            temperature=0.9,
            max_tokens=150,
        )

        return response.choices[0].message.content.strip()

    def sugerir_musica(self, contexto, solicitudes_usuarios):
        """Analiza contexto + solicitudes de usuarios y devuelve sugerencias."""
        system_prompt = (
            "Eres un DJ y curador musical experto para una sala comunitaria.\n"
            "Recibirás solicitudes de varios usuarios y opcionalmente el contenido de una página web como contexto.\n"
            "Analiza TODAS las solicitudes y sugiere las mejores canciones que satisfagan al grupo.\n"
            "Prioriza las solicitudes más específicas y trata de balancear gustos.\n\n"
            "Responde SOLO con un JSON válido con esta estructura:\n"
            '{"canciones": [\n'
            '  {"titulo": "nombre exacto de la canción", "artista": "nombre del artista", '
            '"razon": "breve explicación", "prioridad": 1},\n'
            "  ...\n"
            "], \"resumen\": \"breve resumen de lo que interpretaste de las solicitudes\"}\n\n"
            "Ordena por prioridad (1 = primera en reproducir). Sugiere entre 3 y 7 canciones."
        )

        partes = []
        if contexto:
            partes.append(f"## Contexto de página web:\n{contexto}")

        if solicitudes_usuarios:
            partes.append("## Solicitudes de los usuarios:")
            for s in solicitudes_usuarios:
                email = s.get("email", "anónimo")
                texto = s.get("texto", "")
                partes.append(f"- {email}: {texto}")

        user_msg = "\n\n".join(partes) if partes else "Sugiere música variada y popular."

        response = self.client.chat.completions.create(
            model=self.model,
            messages=[
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_msg},
            ],
            response_format={"type": "json_object"},
            temperature=0.8,
            max_tokens=1024,
        )

        return json.loads(response.choices[0].message.content)


class WebScraper:
    """Extrae texto legible de páginas web."""

    HEADERS = {
        "User-Agent": (
            "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
            "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
        )
    }

    def extraer(self, url):
        """Descarga y extrae el texto principal de una URL."""
        resp = requests.get(url, headers=self.HEADERS, timeout=15)
        resp.raise_for_status()
        soup = BeautifulSoup(resp.text, "html.parser")

        for tag in soup(["script", "style", "nav", "footer", "header", "aside"]):
            tag.decompose()

        texto = soup.get_text(separator="\n", strip=True)
        return texto[:3000]

    @staticmethod
    def abrir_en_brave(url):
        """Abre la URL en Brave browser (Linux y Windows)."""
        if IS_WINDOWS:
            # Rutas comunes de Brave en Windows
            brave_paths = []
            for env in ["ProgramFiles", "ProgramFiles(x86)", "LOCALAPPDATA"]:
                base = os.environ.get(env, "")
                if base:
                    brave_paths.append(os.path.join(
                        base, "BraveSoftware", "Brave-Browser", "Application", "brave.exe"
                    ))
            for path in brave_paths:
                if os.path.isfile(path):
                    subprocess.Popen(
                        [path, url],
                        stdout=subprocess.DEVNULL,
                        stderr=subprocess.DEVNULL,
                    )
                    return True
            # Fallback: abrir con el navegador por defecto de Windows
            os.startfile(url)
            return True
        else:
            for cmd in ["brave", "brave-browser", "brave-browser-stable"]:
                if shutil.which(cmd):
                    subprocess.Popen(
                        [cmd, url],
                        stdout=subprocess.DEVNULL,
                        stderr=subprocess.DEVNULL,
                    )
                    return True
        return False


class MusicPlayer:
    """Reproductor de música vía yt-dlp + mpv. Multiplataforma con control de volumen IPC."""

    def __init__(self):
        self.proceso = None
        self.cancion_actual = None
        self.artista_actual = None
        self.url_actual = None
        self._volume = 80
        self._muted = False
        self._player_cmd = None
        self.video = False  # True = mostrar video, False = solo audio

        # IPC path: named pipe en Windows, Unix socket en Linux/Mac
        if IS_WINDOWS:
            self._ipc_path = r"\\.\pipe\musicbot-mpv"
        else:
            self._ipc_path = os.path.join(tempfile.gettempdir(), "musicbot-mpv-socket")

    def buscar_youtube(self, query, max_results=1):
        """Busca en YouTube y devuelve URLs de los resultados."""
        import yt_dlp

        ydl_opts = {
            "quiet": True,
            "no_warnings": True,
            "extract_flat": True,
            "default_search": f"ytsearch{max_results}",
        }

        with yt_dlp.YoutubeDL(ydl_opts) as ydl:
            info = ydl.extract_info(f"ytsearch{max_results}:{query}", download=False)
            entries = info.get("entries", [])
            return [
                {
                    "titulo": e.get("title", "Sin título"),
                    "url": e.get("url") or f"https://www.youtube.com/watch?v={e.get('id', '')}",
                    "duracion": e.get("duration"),
                    "thumbnail": e.get("thumbnail") or f"https://i.ytimg.com/vi/{e.get('id', '')}/hqdefault.jpg",
                }
                for e in entries
                if e
            ]

    def _detectar_player(self):
        """Detecta el reproductor disponible (mpv o vlc)."""
        candidatos = ["mpv", "vlc"]
        if not IS_WINDOWS:
            candidatos.append("cvlc")  # cvlc solo existe en Linux
        for cmd in candidatos:
            if shutil.which(cmd):
                return cmd
        return None

    def reproducir(self, url, titulo="", artista=""):
        """Reproduce audio de una URL de YouTube."""
        self.detener()
        self.cancion_actual = titulo
        self.artista_actual = artista
        self.url_actual = url

        self._player_cmd = self._detectar_player()
        if not self._player_cmd:
            raise RuntimeError(
                "No se encontró mpv ni vlc. "
                + ("Descarga mpv de mpv.io" if IS_WINDOWS else "Instala: sudo pacman -S mpv")
            )

        vol = 0 if self._muted else self._volume

        if self._player_cmd == "mpv":
            # Limpiar socket viejo (solo en Linux, Windows maneja pipes automáticamente)
            if not IS_WINDOWS:
                try:
                    os.unlink(self._ipc_path)
                except OSError:
                    pass
            args = [
                self._player_cmd,
                "--really-quiet",
                f"--volume={vol}",
                f"--input-ipc-server={self._ipc_path}",
                url,
            ]
            if not self.video:
                args.insert(1, "--no-video")
        else:
            # VLC
            args = [self._player_cmd, "--play-and-exit",
                    f"--gain={vol / 100.0}", url]
            if not self.video:
                args.insert(1, "--no-video")
            if not IS_WINDOWS:
                args.insert(1, "--intf")
                args.insert(2, "dummy")

        self.proceso = subprocess.Popen(
            args, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
        )

    def _send_player_command(self, prop, value):
        """Envía un comando al reproductor activo (mpv IPC o VLC DBUS/rc)."""
        if not self.esta_reproduciendo():
            return
        if self._player_cmd == "mpv":
            self._mpv_ipc_set(prop, value)
        elif self._player_cmd in ("vlc", "cvlc"):
            self._vlc_volume(value if prop == "volume" else None)

    def _mpv_ipc_set(self, prop, value):
        """Envía set_property a mpv via IPC."""
        cmd = json.dumps({"command": ["set_property", prop, value]}) + "\n"
        try:
            if IS_WINDOWS:
                with open(self._ipc_path, "wb") as pipe:
                    pipe.write(cmd.encode())
                    pipe.flush()
            elif HAS_AF_UNIX:
                sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
                sock.settimeout(1)
                sock.connect(self._ipc_path)
                sock.sendall(cmd.encode())
                sock.close()
        except Exception:
            pass

    def _vlc_volume(self, volume):
        """Cambia el volumen de VLC via rc interface o dbus."""
        if volume is None or not self.esta_reproduciendo():
            return
        # Intentar via dbus (Linux)
        if not IS_WINDOWS and shutil.which("dbus-send"):
            try:
                # VLC dbus volume es 0.0-1.0
                dbus_vol = max(0.0, min(1.0, volume / 100.0))
                subprocess.run(
                    ["dbus-send", "--type=method_call",
                     "--dest=org.mpris.MediaPlayer2.vlc",
                     "/org/mpris/MediaPlayer2",
                     "org.freedesktop.DBus.Properties.Set",
                     "string:org.mpris.MediaPlayer2.Player",
                     "string:Volume",
                     f"variant:double:{dbus_vol}"],
                    timeout=2, capture_output=True,
                )
            except Exception:
                pass

    def _mpv_command(self, *args):
        """Compatibilidad: redirige a _send_player_command para set_property."""
        if len(args) >= 3 and args[0] == "set_property":
            self._send_player_command(args[1], args[2])

    def set_volume(self, volume):
        """Cambia el volumen (0-100)."""
        self._volume = max(0, min(100, volume))
        vol = 0 if self._muted else self._volume
        self._send_player_command("volume", vol)

    def set_mute(self, muted):
        """Activa/desactiva mute."""
        self._muted = muted
        self._send_player_command("volume", 0 if muted else self._volume)

    def detener(self):
        """Detiene la reproducción actual."""
        if self.proceso:
            self.proceso.terminate()
            try:
                self.proceso.wait(timeout=3)
            except subprocess.TimeoutExpired:
                self.proceso.kill()
            self.proceso = None
            self.cancion_actual = None
            self.artista_actual = None
            self.url_actual = None

    def esta_reproduciendo(self):
        """Verifica si hay algo reproduciéndose."""
        return self.proceso is not None and self.proceso.poll() is None


class PHPApiClient:
    """Cliente para la API PHP de votación (tmeduca.org/musica_vote)."""

    def __init__(self, base_url, api_key):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key
        self.headers = {"X-API-Key": api_key}

    def _get(self, action):
        resp = requests.get(
            f"{self.base_url}/api.php",
            params={"action": action},
            headers=self.headers,
            timeout=8,
        )
        resp.raise_for_status()
        return resp.json()

    def _post(self, action, data):
        resp = requests.post(
            f"{self.base_url}/api.php?action={action}",
            json=data,
            headers=self.headers,
            timeout=8,
        )
        resp.raise_for_status()
        return resp.json()

    def obtener_solicitudes_pendientes(self):
        """Obtiene las solicitudes de texto no procesadas."""
        try:
            return self._get("pending_requests")
        except requests.RequestException:
            return []

    def marcar_procesadas(self, ids):
        """Marca solicitudes como procesadas."""
        try:
            return self._post("mark_processed", {"ids": ids})
        except requests.RequestException:
            return None

    def set_now_playing(self, titulo, artista, youtube_url="", requested_by="", thumbnail_url=""):
        """Informa al servidor qué canción se está reproduciendo."""
        try:
            return self._post("now_playing", {
                "title": titulo,
                "artist": artista,
                "url": youtube_url,
                "requested_by": requested_by,
                "thumbnail_url": thumbnail_url,
            })
        except requests.RequestException:
            return None

    def obtener_actual(self):
        """Obtiene la canción que se está reproduciendo con votos."""
        try:
            return self._get("current")
        except requests.RequestException:
            return None

    def obtener_stats(self):
        """Obtiene estadísticas generales."""
        try:
            return self._get("stats")
        except requests.RequestException:
            return None

    def notificar_skip(self, song_id, reason=""):
        """Notifica al servidor que se saltó una canción."""
        try:
            return self._post("skip", {"song_id": song_id, "reason": reason})
        except requests.RequestException:
            return None

    def verificar_blacklist(self, titulo, artista):
        """Verifica si una canción está en lista negra."""
        try:
            resp = requests.get(
                f"{self.base_url}/api.php",
                params={"action": "check_blacklist", "title": titulo, "artist": artista},
                headers=self.headers,
                timeout=8,
            )
            resp.raise_for_status()
            return resp.json()
        except requests.RequestException:
            return {"blacklisted": False, "list_color": "white"}

    def guardar_comentario_ia(self, song_id, comentario):
        """Guarda un comentario de IA sobre la canción actual."""
        try:
            return self._post("ai_comment", {"song_id": song_id, "comment": comentario})
        except requests.RequestException:
            return None

    def obtener_canciones_doradas(self):
        """Obtiene canciones de la lista dorada para auto-relleno."""
        try:
            return self._get("gold_songs")
        except requests.RequestException:
            return []

    def obtener_volumen(self):
        """Obtiene el volumen y estado de mute desde el servidor."""
        try:
            return self._get("volume")
        except requests.RequestException:
            return {"volume": 80, "muted": False}

    def sincronizar_cola(self, cola, buffer):
        """Sincroniza el estado de la cola y buffer al servidor."""
        try:
            return self._post("sync_queue", {"queue": cola, "buffer": buffer})
        except requests.RequestException:
            return None

    def obtener_acciones_cola(self):
        """Obtiene acciones de cola pendientes desde la web."""
        try:
            return self._get("pending_queue_actions")
        except requests.RequestException:
            return []

    def marcar_acciones_procesadas(self, ids):
        """Marca acciones de cola como procesadas."""
        try:
            return self._post("mark_queue_actions", {"ids": ids})
        except requests.RequestException:
            return None

    def guardar_playlist(self, nombre, descripcion, fecha, canciones):
        """Guarda una playlist generada."""
        try:
            return self._post("save_playlist", {
                "name": nombre,
                "description": descripcion,
                "play_date": fecha,
                "songs": canciones,
            })
        except requests.RequestException:
            return None

    def obtener_historial_dia(self, fecha=None):
        """Obtiene las canciones reproducidas en una fecha."""
        try:
            resp = requests.get(
                f"{self.base_url}/api.php",
                params={"action": "history_day", "date": fecha or ""},
                headers=self.headers,
                timeout=8,
            )
            resp.raise_for_status()
            return resp.json()
        except requests.RequestException:
            return []

    def obtener_reacciones_agregadas(self):
        """Obtiene el conteo total de reacciones de la canción actual."""
        try:
            return self._get("reactions_summary")
        except requests.RequestException:
            return {}

    def obtener_schedule(self):
        """Obtiene el schedule de presets programados."""
        try:
            return self._get("schedule")
        except requests.RequestException:
            return []

    def guardar_schedule(self, schedule):
        """Guarda el schedule de presets."""
        try:
            return self._post("schedule", {"schedule": schedule})
        except requests.RequestException:
            return None


class WeatherService:
    """Obtiene el clima actual usando wttr.in (sin API key)."""

    def __init__(self, ciudad="Valdivia"):
        self.ciudad = ciudad
        self._cache = None
        self._cache_time = 0

    def obtener(self):
        """Retorna dict con condición y temperatura. Cache de 30 min."""
        import time
        now = time.time()
        if self._cache and (now - self._cache_time) < 1800:
            return self._cache

        try:
            resp = requests.get(
                f"https://wttr.in/{self.ciudad}?format=j1",
                timeout=8,
            )
            resp.raise_for_status()
            data = resp.json()
            current = data["current_condition"][0]

            self._cache = {
                "temp_c": int(current.get("temp_C", 15)),
                "desc": current.get("lang_es", [{}])[0].get("value", current.get("weatherDesc", [{}])[0].get("value", "")),
                "code": int(current.get("weatherCode", 113)),
                "humidity": int(current.get("humidity", 50)),
            }
            self._cache_time = now
            return self._cache
        except Exception:
            return {"temp_c": 15, "desc": "despejado", "code": 113, "humidity": 50}

    def sugerir_ambiente(self):
        """Sugiere un ambiente musical basado en el clima."""
        clima = self.obtener()
        code = clima["code"]
        temp = clima["temp_c"]

        if code >= 200 and code < 230:
            return "tormenta"
        elif code >= 260 or code in (176, 293, 296, 299, 302, 305, 308):
            return "lluvia"
        elif code in (227, 230):
            return "nieve"
        elif code in (119, 122):
            return "nublado"
        elif temp >= 28:
            return "calor"
        elif temp <= 5:
            return "frio"
        else:
            return "despejado"
