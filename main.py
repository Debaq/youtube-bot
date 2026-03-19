#!/usr/bin/env python3
"""Music Bot - Cola continua con votos, listas de color, blacklist y diversión."""

import json
import os
import random
import threading
import tkinter as tk
from datetime import datetime, date
from tkinter import ttk, messagebox

from dotenv import load_dotenv

from services import GroqService, WebScraper, MusicPlayer, PHPApiClient, WeatherService

load_dotenv()

POLL_MS = 15_000        # Cada 15s revisa solicitudes nuevas
PLAYCHECK_MS = 3_000    # Cada 3s revisa si terminó la canción
VOTECHECK_MS = 8_000    # Cada 8s consulta votos y reordena cola
SKIP_DOWNVOTES = 2      # Downvotes absolutos para saltar canción
COMMENT_DELAY_MS = 5_000  # Delay antes de mostrar comentario IA
BUFFER_MIN = 5          # Rellenar buffer cuando tiene menos de esto
BUFFER_TARGET = 15      # Cantidad objetivo de canciones en buffer
SYNC_MS = 10_000        # Cada 10s sincroniza cola al servidor
ENDOFDAY_HOUR = 20      # Hora de generar playlist del día (20 = 8 PM)

# Comandos reconocidos desde solicitudes de usuarios
COMANDOS_SIGUIENTE = {"u", "siguiente", "next", "skip", "s", "otra", "cambia", "cambiale"}
COMANDOS_PARAR = {"para", "stop", "parar", "detener", "pause", "pausa", "callate", "silencio"}

# Presets de ambiente para el DJ
DJ_PRESETS = {
    "Estudio tranquilo": "Musica tranquila e instrumental para estudiar o trabajar concentrado. Lo-fi, ambient, piano suave, jazz relajado. Nada con letras fuertes que distraiga.",
    "Fiesta total": "Musica para fiesta, energia alta, reggaeton, pop latino, EDM, trap. Que la gente quiera bailar. Exitos actuales y clasicos de perreo.",
    "Rock clasico": "Rock clasico de los 70s, 80s y 90s. Led Zeppelin, Queen, Pink Floyd, Guns N Roses, AC/DC, Nirvana. Guitarras electricas y actitud.",
    "Chill & Lo-fi": "Lo-fi hip hop, chillhop, beats relajados para estudiar. Nujabes, Jinsang, idealism. Ambiente cafe japones a las 2am.",
    "Pop actual": "Pop actual y hits del momento. Bad Bunny, Taylor Swift, Dua Lipa, The Weeknd, Billie Eilish. Lo que suena en las radios ahora.",
    "Indie & Alternativo": "Indie rock, dream pop, alternative. Arctic Monkeys, Tame Impala, Mac DeMarco, Radiohead, The Strokes. Para gente con gustos refinados.",
    "Reggaeton & Urbano": "Reggaeton, trap latino, dembow. Daddy Yankee, Bad Bunny, Karol G, Feid, Rauw Alejandro. Pa que fluya el perreo.",
    "Jazz & Soul": "Jazz clasico y contemporaneo, soul, funk suave. Miles Davis, Coltrane, Erykah Badu, D'Angelo. Elegante y con groove.",
    "Electronica & EDM": "Musica electronica, house, techno, trance, dubstep. Daft Punk, Deadmau5, Avicii, Calvin Harris. Para mantener la energia.",
    "Hip-Hop & Rap": "Hip-hop clasico y moderno. Kendrick Lamar, Eminem, Kanye, Tyler the Creator, MF DOOM. Liricas y beats pesados.",
    "Cumbia & Latina": "Cumbia, salsa, merengue, bachata, vallenato. Los Angeles Azules, Celia Cruz, Grupo Niche. Pa mover la cadera.",
    "Metal & Heavy": "Metal, heavy metal, thrash, prog metal. Metallica, Iron Maiden, Slayer, Tool, System of a Down. Volumen al maximo.",
    "K-Pop & J-Pop": "K-Pop, J-Pop, anime openings. BTS, BLACKPINK, NewJeans, YOASOBI. Colorido y energetico.",
    "Clasica & Orquestal": "Musica clasica, orquestal, soundtracks de peliculas. Beethoven, Mozart, Hans Zimmer, Joe Hisaishi. Epica y elegante.",
    "Acustico & Folk": "Acustico, folk, singer-songwriter. Bob Dylan, Ed Sheeran, Iron & Wine, Jose Gonzalez. Guitarra y voz, intimista.",
    "80s & Synthwave": "Sintetizadores, new wave, synthwave, retrowave. Depeche Mode, A-ha, The Midnight, Kavinsky. Neon y nostalgia.",
    "R&B Contemporaneo": "R&B moderno, neo-soul. Frank Ocean, SZA, Daniel Caesar, Steve Lacy. Smooth y emotivo.",
    "Mexicana & Regional": "Regional mexicano, banda, norteno, corridos, mariachi. Peso Pluma, Grupo Firme, Natanael Cano, Vicente Fernandez.",
    "Motivacional & Epica": "Musica motivacional, epica, soundtracks inspiradores. Imagine Dragons, Hans Zimmer, Two Steps From Hell. Para sentirse invencible.",
    "Noche de karaoke": "Canciones faciles de cantar, clasicos de karaoke. Bohemian Rhapsody, Don't Stop Believin, Livin on a Prayer. Que todos se sepan la letra.",
    "Todo vale": "Mezcla de todo: pop, rock, electronica, latina, lo que sea. Variedad total, sorprendeme. Algo para cada gusto.",
}

# Mapeo clima -> preset sugerido
CLIMA_PRESETS = {
    "lluvia": "Jazz & Soul",
    "tormenta": "Clasica & Orquestal",
    "nieve": "Acustico & Folk",
    "nublado": "Chill & Lo-fi",
    "frio": "Acustico & Folk",
    "calor": "Reggaeton & Urbano",
    "despejado": "Pop actual",
}

# Mapeo reacciones dominantes -> preset sugerido
REACCION_PRESETS = {
    "fire": "Fiesta total",
    "dance": "Reggaeton & Urbano",
    "heart": None,  # No cambiar, les gusta lo que suena
    "sleep": "Fiesta total",  # Están aburridos, subir energía
    "skull": None,  # No cambiar por reacción negativa (para eso están los votos)
}

# Schedule por defecto (hora_inicio, hora_fin, preset)
SCHEDULE_DEFAULT = [
    (8, 10, "Chill & Lo-fi"),
    (10, 12, "Estudio tranquilo"),
    (12, 13, "Pop actual"),
    (13, 14, "Cumbia & Latina"),
    (14, 16, "Indie & Alternativo"),
    (16, 17, "Rock clasico"),
    (17, 18, "Reggaeton & Urbano"),
    (18, 20, "Fiesta total"),
]


class MusicBotApp:
    def __init__(self, root):
        self.root = root
        self.root.title("Music Bot - DJ con IA")
        self.root.geometry("950x800")
        self.root.minsize(850, 700)

        self.scraper = WebScraper()
        self.player = MusicPlayer()
        self.weather = WeatherService(os.getenv("WEATHER_CITY", "Valdivia"))
        self.groq = None
        self.api = PHPApiClient(
            os.getenv("VOTING_SERVER_URL", "http://localhost:8080"),
            os.getenv("API_KEY", "cambiar_esta_clave_secreta_2024"),
        )

        # Cola de reproducción visible (máx 1 canción en modo auto)
        self.cola = []
        # Buffer interno: canciones pre-generadas listas para usar
        self.cola_buffer = []
        self.reproduciendo = False
        self.auto_mode = tk.BooleanVar(value=True)
        self.auto_fill = tk.BooleanVar(value=True)
        self.show_video = tk.BooleanVar(value=False)
        self.current_song_id = None  # ID de now_playing actual
        self._rellenando_buffer = False  # Evita llamadas concurrentes al API
        self._playlist_fecha = None  # Fecha de la última playlist generada
        self._streak = 0  # Racha de canciones sin downvotes
        self._schedule = []  # Schedule programado
        self.auto_schedule = tk.BooleanVar(value=True)
        self.auto_mood = tk.BooleanVar(value=True)

        self._init_groq()
        self._crear_gui()

        # Loop que vigila si terminó la canción
        self._loop_playcheck()
        # Loop de sincronización de cola al servidor
        self._loop_sync()
        # Loop de volumen (siempre activo, independiente de modo auto)
        self._loop_volume()

        # Auto-start: iniciar modo auto después de 2s
        self.root.after(2000, self._auto_start)

    def _init_groq(self):
        api_key = os.getenv("GROQ_API_KEY")
        if api_key:
            model = os.getenv("GROQ_MODEL", "llama-3.3-70b-versatile")
            self.groq = GroqService(api_key, model)

    # ── GUI ──────────────────────────────────────────────────────────

    def _crear_gui(self):
        self.root.columnconfigure(0, weight=1)
        # La cola (row 4) y reproductor se expanden
        self.root.rowconfigure(4, weight=1)

        self._crear_seccion_url()
        self._crear_seccion_prompt()
        self._crear_seccion_controles()
        self._crear_seccion_solicitudes()
        self._crear_seccion_cola()
        self._crear_seccion_reproductor()
        self._crear_barra_estado()

    def _crear_seccion_url(self):
        frame = ttk.LabelFrame(self.root, text="Contexto web (opcional)", padding=8)
        frame.grid(row=0, column=0, sticky="ew", padx=10, pady=(10, 5))
        frame.columnconfigure(1, weight=1)

        ttk.Label(frame, text="URL:").grid(row=0, column=0, padx=(0, 5))
        self.url_var = tk.StringVar()
        ttk.Entry(frame, textvariable=self.url_var).grid(row=0, column=1, sticky="ew")
        ttk.Button(frame, text="Abrir en Brave", command=self._abrir_brave).grid(
            row=0, column=2, padx=(5, 0)
        )

    def _crear_seccion_prompt(self):
        frame = ttk.LabelFrame(self.root, text="Ambiente del DJ", padding=8)
        frame.grid(row=1, column=0, sticky="ew", padx=10, pady=5)
        frame.columnconfigure(1, weight=1)

        ttk.Label(frame, text="Modo:").grid(row=0, column=0, padx=(0, 8))

        self.preset_names = list(DJ_PRESETS.keys())
        self.preset_var = tk.StringVar(value=self.preset_names[0])
        combo = ttk.Combobox(
            frame, textvariable=self.preset_var,
            values=self.preset_names, state="readonly",
            font=("", 10, "bold"),
        )
        combo.grid(row=0, column=1, sticky="ew")
        combo.bind("<<ComboboxSelected>>", self._on_preset_change)

        self.lbl_preset_desc = ttk.Label(
            frame, text=DJ_PRESETS[self.preset_names[0]],
            wraplength=800, foreground="gray", font=("", 8),
        )
        self.lbl_preset_desc.grid(row=1, column=0, columnspan=2, sticky="w", pady=(4, 0))

    def _on_preset_change(self, event=None):
        nombre = self.preset_var.get()
        desc = DJ_PRESETS.get(nombre, "")
        self.lbl_preset_desc.config(text=desc)

    def _get_prompt_dj(self):
        """Retorna el prompt combinando preset + clima + contexto histórico."""
        nombre = self.preset_var.get()
        prompt = DJ_PRESETS.get(nombre, "")

        # Agregar contexto del clima
        try:
            clima = self.weather.obtener()
            prompt += f"\n\nClima actual: {clima['desc']}, {clima['temp_c']}°C."
        except Exception:
            pass

        # Agregar contexto histórico (canciones que gustan)
        prompt += self._contexto_historico()

        return prompt

    def _contexto_historico(self):
        """Genera contexto de gustos del lab basado en datos reales."""
        try:
            doradas = self.api.obtener_canciones_doradas()
            if not doradas:
                return ""
            ejemplos = [f"{s['title']} - {s['artist']}" for s in doradas[:8]]
            return f"\n\nCanciones que han gustado mucho en este lab: {', '.join(ejemplos)}. Sugiere cosas similares o del mismo estilo."
        except Exception:
            return ""

    def _auto_preset_por_horario(self):
        """Cambia el preset según el schedule programado."""
        if not self.auto_schedule.get():
            return
        hora = datetime.now().hour
        schedule = self._schedule or SCHEDULE_DEFAULT
        for h_start, h_end, preset in schedule:
            if isinstance(h_start, dict):  # Viene del server
                h_start, h_end, preset = h_start.get("hour_start", 0), h_start.get("hour_end", 23), h_start.get("preset", "Todo vale")
            if h_start <= hora < h_end:
                if preset in DJ_PRESETS and self.preset_var.get() != preset:
                    self.preset_var.set(preset)
                    self._on_preset_change()
                    self._set_status(f"Horario: cambiando a {preset}")
                return

    def _auto_preset_por_animo(self):
        """Cambia el preset según reacciones dominantes del público."""
        if not self.auto_mood.get():
            return

        def tarea():
            try:
                reacciones = self.api.obtener_reacciones_agregadas()
                if not reacciones:
                    # Sin reacciones: usar clima como fallback
                    ambiente = self.weather.sugerir_ambiente()
                    preset = CLIMA_PRESETS.get(ambiente)
                    if preset and preset in DJ_PRESETS and self.preset_var.get() != preset:
                        self.root.after(0, lambda p=preset: self.preset_var.set(p))
                        self.root.after(0, self._on_preset_change)
                        self._set_status_safe(f"Clima ({ambiente}): cambiando a {preset}")
                    return

                # Encontrar reacción dominante
                dominante = max(reacciones, key=lambda k: reacciones[k])
                if reacciones[dominante] < 3:
                    return  # Muy pocas reacciones, no cambiar
                preset = REACCION_PRESETS.get(dominante)
                if preset and preset in DJ_PRESETS and self.preset_var.get() != preset:
                    self.root.after(0, lambda p=preset: self.preset_var.set(p))
                    self.root.after(0, self._on_preset_change)
                    self._set_status_safe(f"Animo ({dominante}): cambiando a {preset}")
            except Exception:
                pass

        threading.Thread(target=tarea, daemon=True).start()

    def _actualizar_racha(self):
        """Consulta y muestra la racha de canciones sin downvotes."""
        def tarea():
            try:
                data = self.api._get("streak")
                self._streak = data.get("streak", 0)
                clima = self.weather.obtener()
                clima_txt = f"{clima['desc']} {clima['temp_c']}°C"

                racha_txt = ""
                if self._streak >= 3:
                    racha_txt = f"  |  Racha x{self._streak}"
                if self._streak >= 5:
                    racha_txt += " COMBO!"
                if self._streak >= 10:
                    racha_txt = f"  |  RACHA x{self._streak} LEGENDARIA!"

                texto = f"Clima: {clima_txt}{racha_txt}"
                self.root.after(0, lambda: self.lbl_clima_racha.config(text=texto))
            except Exception:
                pass

        threading.Thread(target=tarea, daemon=True).start()

    def _crear_seccion_controles(self):
        frame = ttk.Frame(self.root, padding=5)
        frame.grid(row=2, column=0, sticky="ew", padx=10)

        self.btn_procesar = ttk.Button(
            frame, text="Procesar solicitudes", command=self._procesar_solicitudes
        )
        self.btn_procesar.pack(side=tk.LEFT, padx=(0, 5))

        self.btn_analizar = ttk.Button(
            frame, text="Analizar URL + solicitudes", command=self._analizar_con_url
        )
        self.btn_analizar.pack(side=tk.LEFT, padx=(0, 10))

        ttk.Checkbutton(
            frame, text="Modo auto", variable=self.auto_mode,
            command=self._toggle_auto
        ).pack(side=tk.LEFT)

        ttk.Checkbutton(
            frame, text="Auto-rellenar cola", variable=self.auto_fill,
        ).pack(side=tk.LEFT, padx=(10, 0))

        ttk.Checkbutton(
            frame, text="Video", variable=self.show_video,
            command=self._toggle_video
        ).pack(side=tk.LEFT, padx=(10, 0))

        ttk.Checkbutton(
            frame, text="Horario", variable=self.auto_schedule,
        ).pack(side=tk.LEFT, padx=(10, 0))

        ttk.Checkbutton(
            frame, text="Animo", variable=self.auto_mood,
        ).pack(side=tk.LEFT, padx=(10, 0))

        ttk.Button(frame, text="Limpiar cola", command=self._limpiar_cola).pack(
            side=tk.RIGHT
        )

    def _crear_seccion_solicitudes(self):
        frame = ttk.LabelFrame(self.root, text="Solicitudes pendientes", padding=8)
        frame.grid(row=3, column=0, sticky="ew", padx=10, pady=5)
        frame.columnconfigure(0, weight=1)

        self.solicitudes_text = tk.Text(frame, height=3, wrap=tk.WORD, state=tk.DISABLED)
        self.solicitudes_text.grid(row=0, column=0, sticky="ew")

    def _crear_seccion_cola(self):
        frame = ttk.LabelFrame(self.root, text="Cola de reproduccion (0 canciones)", padding=8)
        frame.grid(row=4, column=0, sticky="nsew", padx=10, pady=5)
        frame.columnconfigure(0, weight=1)
        frame.rowconfigure(0, weight=1)
        self.cola_label_frame = frame

        canvas = tk.Canvas(frame, highlightthickness=0)
        scrollbar = ttk.Scrollbar(frame, orient="vertical", command=canvas.yview)
        self.cola_frame = ttk.Frame(canvas)

        self.cola_frame.bind(
            "<Configure>", lambda e: canvas.configure(scrollregion=canvas.bbox("all"))
        )
        canvas.create_window((0, 0), window=self.cola_frame, anchor="nw")
        canvas.configure(yscrollcommand=scrollbar.set)

        canvas.grid(row=0, column=0, sticky="nsew")
        scrollbar.grid(row=0, column=1, sticky="ns")

        canvas.bind_all("<Button-4>", lambda e: canvas.yview_scroll(-3, "units"))
        canvas.bind_all("<Button-5>", lambda e: canvas.yview_scroll(3, "units"))

    def _crear_seccion_reproductor(self):
        frame = ttk.LabelFrame(self.root, text="Reproductor", padding=8)
        frame.grid(row=5, column=0, sticky="ew", padx=10, pady=5)
        frame.columnconfigure(1, weight=1)

        self.lbl_reproduciendo = ttk.Label(
            frame, text="Sin reproduccion", font=("", 11, "italic")
        )
        self.lbl_reproduciendo.grid(row=0, column=0, columnspan=4, sticky="w")

        self.lbl_votos = ttk.Label(frame, text="", font=("", 9))
        self.lbl_votos.grid(row=1, column=0, columnspan=4, sticky="w", pady=(2, 0))

        self.lbl_clima_racha = ttk.Label(frame, text="", font=("", 8), foreground="gray")
        self.lbl_clima_racha.grid(row=2, column=0, columnspan=4, sticky="w", pady=(0, 5))

        ttk.Button(frame, text="Detener", command=self._detener_solo).grid(row=3, column=0)
        ttk.Button(frame, text="Siguiente", command=self._siguiente).grid(row=3, column=1, padx=5)

    def _crear_barra_estado(self):
        self.status_var = tk.StringVar(value="Listo")
        ttk.Label(
            self.root, textvariable=self.status_var, relief=tk.SUNKEN, anchor=tk.W
        ).grid(row=6, column=0, sticky="ew", padx=10, pady=(0, 5))

    # ── Helpers ──────────────────────────────────────────────────────

    def _set_status(self, msg):
        self.status_var.set(msg)

    def _set_status_safe(self, msg):
        self.root.after(0, lambda: self._set_status(msg))

    def _toggle_video(self):
        self.player.video = self.show_video.get()
        state = "con video" if self.player.video else "solo audio"
        self._set_status(f"Modo: {state} (aplica en la siguiente cancion)")

    def _abrir_brave(self):
        url = self.url_var.get().strip()
        if not url:
            return
        self.scraper.abrir_en_brave(url)

    # ── Detección de comandos ────────────────────────────────────────

    def _detectar_y_ejecutar_comandos(self, solicitudes):
        """Detecta comandos en las solicitudes. Retorna las que NO son comandos."""
        restantes = []
        for s in solicitudes:
            texto = s.get("texto", "").strip().lower()
            # Quitar signos y espacios extra
            texto_limpio = texto.strip("!¡¿? .,")
            if texto_limpio in COMANDOS_SIGUIENTE:
                self._set_status_safe(f"Comando recibido: siguiente ({s.get('email', '?')})")
                self.root.after(0, self._siguiente)
            elif texto_limpio in COMANDOS_PARAR:
                self._set_status_safe(f"Comando recibido: parar ({s.get('email', '?')})")
                self.root.after(0, self._detener_solo)
            else:
                restantes.append(s)
        return restantes

    # ── Cola ─────────────────────────────────────────────────────────

    def _agregar_a_cola(self, canciones):
        """Agrega canciones a la cola sin duplicar (cola + buffer + historial 2h)."""
        for c in canciones:
            titulo = c.get("titulo", "?")
            artista = c.get("artista", "?")
            priority = c.get("priority", "normal")
            # Dedup: cola + buffer + historial reciente
            ya_existe = any(
                x["titulo"].lower() == titulo.lower() and x["artista"].lower() == artista.lower()
                for x in self.cola + self.cola_buffer
            )
            if not ya_existe:
                entry = {
                    "titulo": titulo,
                    "artista": artista,
                    "razon": c.get("razon", ""),
                    "votos_net": 0,
                    "solicitado_por": c.get("solicitado_por", ""),
                    "priority": priority,
                    "thumbnail": c.get("thumbnail", ""),
                }
                if priority == "now":
                    self.cola.insert(0, entry)
                else:
                    self.cola.append(entry)
        self._refrescar_cola_gui()

    def _reordenar_cola_por_votos(self):
        """Reordena la cola: prioridad 'now' primero, luego por votos netos."""
        self.cola.sort(
            key=lambda x: (0 if x.get("priority") == "now" else 1, -x.get("votos_net", 0))
        )
        self._refrescar_cola_gui()

    def _refrescar_cola_gui(self):
        for widget in self.cola_frame.winfo_children():
            widget.destroy()

        buffer_info = f" | Buffer: {len(self.cola_buffer)}" if self.cola_buffer else ""
        self.cola_label_frame.config(
            text=f"Cola de reproduccion ({len(self.cola)} canciones{buffer_info})"
        )

        if not self.cola:
            ttk.Label(self.cola_frame, text="Cola vacia", foreground="gray").pack(pady=10)
            return

        for i, c in enumerate(self.cola):
            frame = ttk.Frame(self.cola_frame, padding=3)
            frame.pack(fill=tk.X, padx=5, pady=1)

            titulo = c["titulo"]
            artista = c["artista"]
            votos = c.get("votos_net", 0)
            razon = c.get("razon", "")
            priority = c.get("priority", "normal")

            # Indicador de votos
            if votos > 0:
                votos_txt = f"[+{votos}]"
            elif votos < 0:
                votos_txt = f"[{votos}]"
            else:
                votos_txt = ""

            priority_txt = " [AHORA]" if priority == "now" else ""

            info = ttk.Frame(frame)
            info.pack(side=tk.LEFT, fill=tk.X, expand=True)

            ttk.Label(
                info, text=f"{i+1}. {titulo} - {artista}  {votos_txt}{priority_txt}",
                font=("", 9, "bold" if i == 0 else ""),
            ).pack(anchor=tk.W)

            if razon and i < 5:  # Solo mostrar razón para las primeras 5
                ttk.Label(info, text=razon, foreground="gray", font=("", 8)).pack(anchor=tk.W)

            btns = ttk.Frame(frame)
            btns.pack(side=tk.RIGHT)

            ttk.Button(
                btns, text="Subir", width=5,
                command=lambda idx=i: self._mover_cola(idx, -1)
            ).pack(side=tk.LEFT, padx=1)

            ttk.Button(
                btns, text="Quitar", width=5,
                command=lambda idx=i: self._quitar_de_cola(idx)
            ).pack(side=tk.LEFT, padx=1)

            if i == 0:
                ttk.Button(
                    btns, text="Reproducir", width=8,
                    command=lambda t=titulo, a=artista: self._reproducir_cancion(t, a)
                ).pack(side=tk.LEFT, padx=1)

    def _mover_cola(self, idx, direction):
        new_idx = idx + direction
        if 0 <= new_idx < len(self.cola):
            self.cola[idx], self.cola[new_idx] = self.cola[new_idx], self.cola[idx]
            self._refrescar_cola_gui()

    def _quitar_de_cola(self, idx):
        if 0 <= idx < len(self.cola):
            self.cola.pop(idx)
            self._refrescar_cola_gui()

    def _limpiar_cola(self):
        self.cola.clear()
        self.cola_buffer.clear()
        self._refrescar_cola_gui()

    # ── Buffer interno ───────────────────────────────────────────────

    def _mover_una_del_buffer(self):
        """Mueve UNA canción del buffer a la cola visible."""
        if self.cola_buffer:
            cancion = self.cola_buffer.pop(0)
            self._agregar_a_cola([cancion])
            self._set_status(f"Buffer: {len(self.cola_buffer)} canciones restantes")
            # Si el buffer está bajo, rellenar en background
            if len(self.cola_buffer) < BUFFER_MIN:
                self._rellenar_buffer()

    def _rellenar_buffer(self):
        """Rellena el buffer interno con canciones (doradas + horario + Groq)."""
        if not self.auto_fill.get() or not self.groq:
            return
        if self._rellenando_buffer:
            return
        if len(self.cola_buffer) >= BUFFER_MIN:
            return

        self._rellenando_buffer = True

        def tarea():
            try:
                existentes = {(c["titulo"].lower(), c["artista"].lower()) for c in self.cola + self.cola_buffer}

                # Consultar canciones bien votadas a esta hora en días anteriores
                hora = datetime.now().hour
                historial_hora = []
                try:
                    historial_hora = self.api._get(f"history_hour&hour={hora}")
                    if isinstance(historial_hora, list):
                        for s in historial_hora:
                            key = (s.get("title", "").lower(), s.get("artist", "").lower())
                            if key not in existentes:
                                self.cola_buffer.append({
                                    "titulo": s["title"], "artista": s["artist"],
                                    "razon": f"Popular a las {hora}:00", "thumbnail": "",
                                })
                                existentes.add(key)
                except Exception:
                    pass

                # Mezcla: 60% doradas + 40% Groq
                doradas = self.api.obtener_canciones_doradas()
                if doradas:
                    random.shuffle(doradas)
                    n_doradas = int(BUFFER_TARGET * 0.6)
                    canciones = [
                        {"titulo": s["title"], "artista": s["artist"],
                         "razon": "Exito dorado", "thumbnail": s.get("thumbnail_url", "")}
                        for s in doradas[:n_doradas]
                    ]
                    nuevas = [c for c in canciones if (c["titulo"].lower(), c["artista"].lower()) not in existentes]
                    self.cola_buffer.extend(nuevas)
                    for c in nuevas:
                        existentes.add((c["titulo"].lower(), c["artista"].lower()))

                # Complementar con Groq
                if len(self.cola_buffer) < BUFFER_TARGET:
                    resultado = self.groq.sugerir_musica(self._get_prompt_dj(), [])
                    canciones = resultado.get("canciones", [])
                    if canciones:
                        nuevas = [c for c in canciones if (c.get("titulo", "?").lower(), c.get("artista", "?").lower()) not in existentes]
                        self.cola_buffer.extend(nuevas)

                self._set_status_safe(f"Buffer rellenado: {len(self.cola_buffer)} canciones listas")
            except Exception as e:
                self._set_status_safe(f"Error rellenando buffer: {e}")
            finally:
                self._rellenando_buffer = False

        threading.Thread(target=tarea, daemon=True).start()

    def _auto_rellenar_cola(self):
        """Si la cola está vacía, toma del buffer o rellena el buffer."""
        if not self.auto_fill.get():
            return
        if self.cola:
            return

        if self.cola_buffer:
            # Tomar una del buffer
            self._mover_una_del_buffer()
        else:
            # Buffer vacío, rellenar
            self._rellenar_buffer()

    # ── Modo auto ────────────────────────────────────────────────────

    def _auto_start(self):
        """Inicia modo auto automáticamente al arrancar."""
        if not self.auto_mode.get():
            return
        self._set_status("Modo auto iniciando...")
        def cargar_schedule():
            try:
                s = self.api.obtener_schedule()
                if s:
                    self._schedule = [(r.get("hour_start", 0), r.get("hour_end", 23), r.get("preset", "Todo vale")) for r in s]
            except Exception:
                pass
        threading.Thread(target=cargar_schedule, daemon=True).start()
        self._auto_tick()
        self._auto_votos_tick()

    def _toggle_auto(self):
        if self.auto_mode.get():
            self._set_status("Modo auto activado")
            # Cargar schedule del server
            def cargar_schedule():
                try:
                    s = self.api.obtener_schedule()
                    if s:
                        self._schedule = [(r.get("hour_start", 0), r.get("hour_end", 23), r.get("preset", "Todo vale")) for r in s]
                except Exception:
                    pass
            threading.Thread(target=cargar_schedule, daemon=True).start()
            self._auto_tick()
            self._auto_votos_tick()
        else:
            self._set_status("Modo auto desactivado")

    def _auto_tick(self):
        """Poll periódico: busca solicitudes, detecta comandos, procesa, y reproduce."""
        if not self.auto_mode.get():
            return

        def tarea():
            try:
                solicitudes = self.api.obtener_solicitudes_pendientes()
                self.root.after(0, self._mostrar_solicitudes, solicitudes)

                if solicitudes:
                    ids = [s["id"] for s in solicitudes]
                    self.api.marcar_procesadas(ids)

                    # Detectar y ejecutar comandos, quedarnos solo con solicitudes reales
                    solicitudes_reales = self._detectar_y_ejecutar_comandos(solicitudes)

                    # Procesar solicitudes reales con Groq
                    if solicitudes_reales and self.groq:
                        resultado = self.groq.sugerir_musica("", solicitudes_reales)
                        canciones = resultado.get("canciones", [])

                        if canciones:
                            has_now = any(s.get("priority") == "now" for s in solicitudes_reales)
                            for c in canciones:
                                if has_now:
                                    c["priority"] = "now"
                                c["solicitado_por"] = solicitudes_reales[0].get("email", "")

                            # Las canciones con priority=now van directo a la cola
                            ahora = [c for c in canciones if c.get("priority") == "now"]
                            resto = [c for c in canciones if c.get("priority") != "now"]

                            if ahora:
                                self.root.after(0, self._agregar_a_cola, ahora)
                            # El resto va al buffer
                            if resto:
                                self.cola_buffer.extend(resto)
                                self._set_status_safe(f"Buffer: +{len(resto)} canciones ({len(self.cola_buffer)} total)")

                # Si hay una canción con priority=now en la cola y algo sonando, saltar
                if self.cola and self.cola[0].get("priority") == "now" and self.player.esta_reproduciendo():
                    self._set_status_safe("Prioridad inmediata detectada, saltando...")
                    self.root.after(0, self._siguiente)
                    return

                # Si no hay nada sonando, buscar qué reproducir
                if not self.player.esta_reproduciendo():
                    if self.cola:
                        self.root.after(0, self._reproducir_siguiente)
                    elif self.cola_buffer:
                        # Mover una del buffer a la cola y reproducir
                        self.root.after(0, self._mover_una_del_buffer)
                        self.root.after(100, self._reproducir_siguiente)
                    else:
                        # Intentar rellenar buffer
                        self.root.after(0, self._auto_rellenar_cola)

                # Mantener el buffer lleno en background
                if len(self.cola_buffer) < BUFFER_MIN:
                    self._rellenar_buffer()

            except Exception as e:
                self._set_status_safe(f"Error auto: {e}")

        threading.Thread(target=tarea, daemon=True).start()
        self.root.after(POLL_MS, self._auto_tick)

    def _auto_votos_tick(self):
        """Consulta votos de la web y reordena cola. Salta con 2 downvotes absolutos."""
        if not self.auto_mode.get():
            return

        def tarea():
            try:
                actual = self.api.obtener_actual()
                if not actual:
                    return

                self.current_song_id = actual.get("id")
                up = actual.get("total_up", 0)
                down = actual.get("total_down", 0)

                self.root.after(
                    0, lambda: self.lbl_votos.config(
                        text=f"Votos en la web:  +{up}  -{down}"
                    )
                )

                # Skip si total_down >= SKIP_DOWNVOTES (de personas distintas)
                if self.player.esta_reproduciendo() and down >= SKIP_DOWNVOTES:
                    song_id = actual.get("id")
                    titulo = actual.get("title", "")
                    self._set_status_safe(
                        f"Saltando ({down} downvotes): {titulo}"
                    )
                    # Notificar skip al servidor
                    self.api.notificar_skip(song_id, reason="downvotes")
                    self.root.after(0, self._siguiente)
                    return

                # Actualizar votos en la cola desde stats
                stats = self.api.obtener_stats()
                if stats:
                    top_songs = stats.get("top_songs", [])
                    votos_map = {}
                    for s in top_songs:
                        key = s.get("title", "").lower()
                        votos_map[key] = s.get("total_up", 0) - s.get("total_down", 0)

                    for c in self.cola:
                        key = c["titulo"].lower()
                        if key in votos_map:
                            c["votos_net"] = votos_map[key]

                self.root.after(0, self._reordenar_cola_por_votos)

                # Auto-preset por horario y ánimo
                self.root.after(0, self._auto_preset_por_horario)
                self._auto_preset_por_animo()
                self._actualizar_racha()

            except Exception:
                pass

        threading.Thread(target=tarea, daemon=True).start()
        self.root.after(VOTECHECK_MS, self._auto_votos_tick)

    # ── Procesamiento manual ─────────────────────────────────────────

    def _procesar_solicitudes(self):
        if not self.groq:
            messagebox.showerror("Error", "Configura GROQ_API_KEY en .env")
            return

        self.btn_procesar.config(state=tk.DISABLED)
        self._set_status("Procesando solicitudes...")

        def tarea():
            try:
                solicitudes = self.api.obtener_solicitudes_pendientes()
                self.root.after(0, self._mostrar_solicitudes, solicitudes)

                if not solicitudes:
                    self._set_status_safe("No hay solicitudes pendientes")
                    return

                ids = [s["id"] for s in solicitudes]
                self.api.marcar_procesadas(ids)

                # Detectar comandos primero
                solicitudes_reales = self._detectar_y_ejecutar_comandos(solicitudes)

                if not solicitudes_reales:
                    self._set_status_safe("Solo se recibieron comandos")
                    return

                self._set_status_safe("Consultando IA...")
                resultado = self.groq.sugerir_musica("", solicitudes_reales)
                canciones = resultado.get("canciones", [])

                if canciones:
                    for c in canciones:
                        c["solicitado_por"] = solicitudes_reales[0].get("email", "")
                    self.root.after(0, self._agregar_a_cola, canciones)
                    self._set_status_safe(f"{len(canciones)} canciones agregadas a la cola")
                else:
                    self._set_status_safe("La IA no generó sugerencias")

            except Exception as e:
                self.root.after(0, lambda: messagebox.showerror("Error", str(e)))
            finally:
                self.root.after(0, lambda: self.btn_procesar.config(state=tk.NORMAL))

        threading.Thread(target=tarea, daemon=True).start()

    def _analizar_con_url(self):
        url = self.url_var.get().strip()
        prompt_dj = self._get_prompt_dj()

        if not self.groq:
            messagebox.showerror("Error", "Configura GROQ_API_KEY en .env")
            return

        self.btn_analizar.config(state=tk.DISABLED)
        self._set_status("Analizando...")

        def tarea():
            try:
                contexto = ""
                if url:
                    self._set_status_safe("Extrayendo web...")
                    contexto = self.scraper.extraer(url)

                if prompt_dj:
                    contexto = f"{contexto}\n\n## Indicaciones del DJ:\n{prompt_dj}" if contexto else prompt_dj

                solicitudes = self.api.obtener_solicitudes_pendientes()
                self.root.after(0, self._mostrar_solicitudes, solicitudes)

                self._set_status_safe("Consultando IA...")
                resultado = self.groq.sugerir_musica(contexto, solicitudes)
                canciones = resultado.get("canciones", [])

                if solicitudes:
                    ids = [s["id"] for s in solicitudes]
                    self.api.marcar_procesadas(ids)

                if canciones:
                    self.root.after(0, self._agregar_a_cola, canciones)
                    self._set_status_safe(f"{len(canciones)} canciones agregadas a la cola")

            except Exception as e:
                self.root.after(0, lambda: messagebox.showerror("Error", str(e)))
            finally:
                self.root.after(0, lambda: self.btn_analizar.config(state=tk.NORMAL))

        threading.Thread(target=tarea, daemon=True).start()

    def _mostrar_solicitudes(self, solicitudes):
        self.solicitudes_text.config(state=tk.NORMAL)
        self.solicitudes_text.delete("1.0", tk.END)

        if not solicitudes:
            self.solicitudes_text.insert("1.0", "No hay solicitudes pendientes.")
        else:
            for s in solicitudes:
                priority = s.get("priority", "normal")
                prefix = "[AHORA] " if priority == "now" else ""
                self.solicitudes_text.insert(
                    tk.END, f"{prefix}[{s.get('email', '?')}] {s.get('texto', '')}\n"
                )

        self.solicitudes_text.config(state=tk.DISABLED)

    # ── Reproducción ─────────────────────────────────────────────────

    def _reproducir_cancion(self, titulo, artista, solicitado_por="", thumbnail=""):
        query = f"{titulo} {artista}"
        self._set_status(f"Buscando: {query}...")
        self.reproduciendo = True

        def tarea():
            try:
                # Verificar blacklist antes de buscar
                bl = self.api.verificar_blacklist(titulo, artista)
                if bl.get("blacklisted"):
                    self._set_status_safe(f"BLACKLIST - saltando: {titulo} - {artista}")
                    self.reproduciendo = False
                    self.root.after(0, self._reproducir_siguiente)
                    return

                resultados = self.player.buscar_youtube(query, max_results=1)
                if not resultados:
                    self._set_status_safe(f"No encontrado en YouTube: {query}")
                    self.reproduciendo = False
                    self.root.after(0, self._siguiente)
                    return

                url = resultados[0]["url"]
                thumb = thumbnail or resultados[0].get("thumbnail", "")
                self.player.reproducir(url, titulo, artista)
                resp = self.api.set_now_playing(titulo, artista, url, solicitado_por, thumbnail_url=thumb)

                if resp:
                    self.current_song_id = resp.get("id")

                display = f"{titulo} - {artista}"
                self.root.after(
                    0, lambda: self.lbl_reproduciendo.config(
                        text=f"Reproduciendo: {display}", font=("", 11, "bold")
                    )
                )
                self._set_status_safe(f"Reproduciendo: {display}")

                # Generar comentario IA con delay
                if self.groq and self.current_song_id:
                    self.root.after(COMMENT_DELAY_MS, self._generar_comentario_ia, titulo, artista)

            except Exception as e:
                self._set_status_safe(f"Error: {e}")
                self.reproduciendo = False

        threading.Thread(target=tarea, daemon=True).start()

    def _generar_comentario_ia(self, titulo, artista):
        """Genera y guarda un comentario de IA sobre la canción actual."""
        if not self.groq or not self.current_song_id:
            return

        def tarea():
            try:
                comentario = self.groq.comentar_cancion(titulo, artista)
                if comentario and self.current_song_id:
                    self.api.guardar_comentario_ia(self.current_song_id, comentario)
            except Exception:
                pass

        threading.Thread(target=tarea, daemon=True).start()

    def _reproducir_siguiente(self):
        """Toma la primera de la cola (o del buffer si está vacía) y la reproduce."""
        if not self.cola and self.cola_buffer:
            # Mover una del buffer a la cola
            self._mover_una_del_buffer()

        if not self.cola:
            self.lbl_reproduciendo.config(text="Cola vacia", font=("", 11, "italic"))
            self.lbl_votos.config(text="")
            self.reproduciendo = False
            # Intentar rellenar buffer
            self._auto_rellenar_cola()
            return

        siguiente = self.cola.pop(0)
        self._refrescar_cola_gui()
        self._reproducir_cancion(
            siguiente["titulo"],
            siguiente["artista"],
            siguiente.get("solicitado_por", ""),
            siguiente.get("thumbnail", ""),
        )

    def _siguiente(self):
        """Salta a la siguiente canción."""
        self.player.detener()
        self._reproducir_siguiente()

    def _detener_solo(self):
        """Detiene sin vaciar la cola."""
        self.player.detener()
        self.reproduciendo = False
        self.lbl_reproduciendo.config(text="Pausado (cola intacta)", font=("", 11, "italic"))
        self.lbl_votos.config(text="")
        self._set_status(f"Detenido - {len(self.cola)} en cola")

    # ── Loop de monitoreo ────────────────────────────────────────────

    def _loop_playcheck(self):
        """Verifica cada 3s si terminó la canción para pasar a la siguiente."""
        if self.reproduciendo and not self.player.esta_reproduciendo():
            self._reproducir_siguiente()
        self.root.after(PLAYCHECK_MS, self._loop_playcheck)

    # ── Volumen desde la web (siempre activo) ────────────────────────

    def _loop_volume(self):
        """Sincroniza volumen, auto_mode, auto_fill desde la web cada 5s."""
        def tarea():
            try:
                vol_data = self.api.obtener_volumen()
                if vol_data:
                    self.player.set_volume(vol_data.get("volume", 80))
                    self.player.set_mute(vol_data.get("muted", False))
                    web_video = vol_data.get("video", False)
                    if web_video != self.player.video:
                        self.player.video = web_video
                        self.root.after(0, lambda: self.show_video.set(web_video))
                    # Sincronizar preset desde la web
                    web_preset = vol_data.get("preset", "")
                    if web_preset and web_preset in DJ_PRESETS and web_preset != self.preset_var.get():
                        self.root.after(0, lambda p=web_preset: (self.preset_var.set(p), self._on_preset_change()))
                        self._set_status_safe(f"Web cambio ambiente: {web_preset}")
                    # Sincronizar ciudad
                    web_city = vol_data.get("city", "")
                    if web_city and web_city != self.weather.ciudad:
                        self.weather.ciudad = web_city
                        self.weather._cache = None
                    # Sincronizar auto_mode y auto_fill
                    web_auto = vol_data.get("auto_mode")
                    if web_auto is not None and web_auto != self.auto_mode.get():
                        self.root.after(0, lambda v=web_auto: self.auto_mode.set(v))
                        if web_auto:
                            self.root.after(100, self._auto_start)
                    web_fill = vol_data.get("auto_fill")
                    if web_fill is not None and web_fill != self.auto_fill.get():
                        self.root.after(0, lambda v=web_fill: self.auto_fill.set(v))
            except Exception:
                pass

        threading.Thread(target=tarea, daemon=True).start()
        self.root.after(5000, self._loop_volume)

    # ── Sincronización cola <-> servidor ─────────────────────────────

    def _loop_sync(self):
        """Sincroniza cola y buffer al servidor, y procesa acciones de la web."""
        def tarea():
            try:
                # Sincronizar estado de cola al servidor
                self.api.sincronizar_cola(self.cola, self.cola_buffer)

                # Leer y aplicar acciones de la web
                acciones = self.api.obtener_acciones_cola()
                if acciones:
                    ids = []
                    for a in acciones:
                        ids.append(a["id"])
                        self._aplicar_accion_web(a)
                    self.api.marcar_acciones_procesadas(ids)
                    self.root.after(0, self._refrescar_cola_gui)

                # Verificar fin de día
                self._check_endofday()

            except Exception:
                pass

        threading.Thread(target=tarea, daemon=True).start()
        self.root.after(SYNC_MS, self._loop_sync)

    def _aplicar_accion_web(self, accion):
        """Aplica una acción de la web sobre la cola o buffer."""
        tipo = accion.get("action", "")
        data = accion.get("data", "{}")
        try:
            data = json.loads(data) if isinstance(data, str) else data
        except json.JSONDecodeError:
            data = {}

        source = data.get("source", "queue")
        position = int(data.get("position", 0))
        target = self.cola if source == "queue" else self.cola_buffer

        if tipo == "skip":
            self._set_status_safe("Web pidió skip")
            self.root.after(0, self._siguiente)
        elif tipo == "pause":
            self._set_status_safe("Web pidió pause")
            self.root.after(0, self._detener_solo)
        elif tipo == "remove" and 0 <= position < len(target):
            removed = target.pop(position)
            self._set_status_safe(f"Web removió: {removed.get('titulo', '?')}")
        elif tipo == "move_up" and 0 < position < len(target):
            target[position], target[position - 1] = target[position - 1], target[position]
            self._set_status_safe("Web reordenó cola")
        elif tipo == "move_down" and 0 <= position < len(target) - 1:
            target[position], target[position + 1] = target[position + 1], target[position]
        elif tipo == "clear":
            if source == "buffer":
                self.cola_buffer.clear()
                self._set_status_safe("Web limpió buffer")
            else:
                self.cola.clear()
                self.cola_buffer.clear()
                self._set_status_safe("Web limpió toda la cola")
        elif tipo == "refresh":
            self.cola_buffer.clear()
            self._set_status_safe("Web pidió refrescar parrilla")
            self._rellenar_buffer()

    # ── Playlist de fin de día ───────────────────────────────────────

    def _check_endofday(self):
        """A las 8 PM genera playlists automáticas."""
        ahora = datetime.now()
        hoy = date.today()

        if ahora.hour < ENDOFDAY_HOUR:
            return
        if self._playlist_fecha == hoy:
            return

        self._playlist_fecha = hoy
        self._set_status_safe("Generando playlists automáticas...")

        def tarea():
            try:
                fecha_str = hoy.isoformat()

                # 1. Playlist diaria
                historial = self.api.obtener_historial_dia(fecha_str)
                if historial and len(historial) >= 2:
                    canciones_playlist = []
                    for h in historial:
                        canciones_playlist.append({
                            "title": h.get("title", "?"),
                            "artist": h.get("artist", "?"),
                            "youtube_url": h.get("youtube_url", ""),
                            "thumbnail_url": h.get("thumbnail_url", ""),
                            "source": "request" if h.get("requested_by") else "auto",
                            "votes_net": int(h.get("votes_net", 0)),
                        })
                    canciones_playlist.sort(key=lambda x: -x["votes_net"])

                    descripcion = f"Canciones del {fecha_str}"
                    if self.groq:
                        try:
                            titulos = ", ".join(f"{c['title']} - {c['artist']}" for c in canciones_playlist[:10])
                            descripcion = self.groq.comentar_cancion(
                                f"Playlist del día {fecha_str}",
                                f"con {len(canciones_playlist)} canciones: {titulos}"
                            )
                        except Exception:
                            pass

                    nombre = f"Sesión {fecha_str}"
                    self.api.guardar_playlist(nombre, descripcion, fecha_str, canciones_playlist)
                    self._set_status_safe(f"Playlist '{nombre}' guardada")

                # 2. Lo mejor de la semana (domingos)
                if ahora.weekday() == 6:  # Domingo
                    self._generar_playlist_semanal()

                # 3. Más escuchadas (día 1 del mes)
                if ahora.day == 1:
                    self._generar_playlist_mensual()

                # 4. Favoritas del lab (cuando hay suficientes gold)
                self._generar_playlist_favoritas()

            except Exception as e:
                self._set_status_safe(f"Error playlists: {e}")

        threading.Thread(target=tarea, daemon=True).start()

    def _generar_playlist_semanal(self):
        """Genera 'Lo mejor de la semana' con top 20 por votos."""
        try:
            semana = self.api._get("history_week")
            if not semana or len(semana) < 10:
                return
            canciones = [{
                "title": s.get("title", "?"), "artist": s.get("artist", "?"),
                "youtube_url": s.get("youtube_url", ""), "thumbnail_url": s.get("thumbnail_url", ""),
                "votes_net": int(s.get("votes_net", 0)),
            } for s in semana[:20]]
            fecha_str = date.today().isoformat()
            self.api._post("save_playlist", {
                "name": f"Lo mejor de la semana ({fecha_str})",
                "description": f"Top 20 canciones de la semana",
                "play_date": fecha_str,
                "songs": canciones,
                "playlist_type": "weekly",
            })
        except Exception:
            pass

    def _generar_playlist_mensual(self):
        """Genera 'Más escuchadas' con top 25 del catálogo."""
        try:
            stats = self.api.obtener_stats()
            if not stats:
                return
            songs = self.api._get("list&color=all")
            if not songs or len(songs) < 15:
                return
            # Ordenar por times_played
            songs.sort(key=lambda x: -int(x.get("times_played", 0)))
            canciones = [{
                "title": s.get("title", "?"), "artist": s.get("artist", "?"),
                "youtube_url": "", "thumbnail_url": s.get("thumbnail_url", ""),
                "votes_net": int(s.get("score", 0)),
            } for s in songs[:25]]
            fecha_str = date.today().isoformat()
            self.api._post("save_playlist", {
                "name": f"Más escuchadas ({fecha_str})",
                "description": "Top 25 canciones más reproducidas",
                "play_date": fecha_str,
                "songs": canciones,
                "playlist_type": "monthly_top",
            })
        except Exception:
            pass

    def _generar_playlist_favoritas(self):
        """Genera 'Favoritas del lab' cuando hay 20+ canciones gold."""
        try:
            doradas = self.api.obtener_canciones_doradas()
            if not doradas or len(doradas) < 20:
                return
            canciones = [{
                "title": s.get("title", "?"), "artist": s.get("artist", "?"),
                "youtube_url": s.get("youtube_url", ""), "thumbnail_url": s.get("thumbnail_url", ""),
                "votes_net": int(s.get("score", 0)),
            } for s in doradas]
            self.api._post("save_playlist", {
                "name": "Favoritas del lab",
                "description": f"{len(canciones)} canciones doradas del lab",
                "play_date": date.today().isoformat(),
                "songs": canciones,
                "playlist_type": "favorites",
            })
        except Exception:
            pass

    # ── Cierre ───────────────────────────────────────────────────────

    def on_close(self):
        self.auto_mode.set(False)
        self.player.detener()
        self.root.destroy()


def main():
    root = tk.Tk()
    app = MusicBotApp(root)
    root.protocol("WM_DELETE_WINDOW", app.on_close)
    root.mainloop()


if __name__ == "__main__":
    main()
