import { useState, useEffect, useRef, useCallback } from 'react';
import type { Song, Solicitud, SolicitudProcesada } from '../types';
import {
  apiGet,
  apiPost,
  groqChat,
  youtubeSearch,
  youtubePlay,
  playerStop,
  playerIsPlaying,
  getSolicitudes,
  logMessage,
} from '../api';
import type { GroqMessage } from '../api';

// ── Constantes (replica de main.py) ──────────────────────────────

const POLL_MS = 15_000;
const PLAYCHECK_MS = 3_000;
const SYNC_MS = 30_000;
const BUFFER_MIN = 5;
const BUFFER_TARGET = 15;
const REFILL_COOLDOWN_MS = 60_000;

const COMANDOS_SIGUIENTE = new Set([
  'u', 'siguiente', 'next', 'skip', 's', 'otra', 'cambia', 'cambiale',
]);
const COMANDOS_PARAR = new Set([
  'para', 'stop', 'parar', 'detener', 'pause', 'pausa', 'callate', 'silencio',
]);

const PATRON_DIRECTO = /^(?:pon(?:me|er|gan)?(?:\s+la\s+de)?|reproduce|echale|dale|quiero\s+(?:escuchar|oir))\s+(.+)/i;

const PALABRAS_VAGAS = new Set([
  'algo', 'música', 'musica', 'tipo', 'estilo', 'ambiente',
  'como', 'parecido', 'similar', 'para', 'genero', 'género',
  'tranquilo', 'movido', 'relajado', 'alegre', 'triste',
  'quiero', 'necesito', 'dame', 'inspiración', 'inspiracion',
  'recomienda', 'recomendame', 'sugiere', 'sugiéreme',
]);

// ── Prompt del DJ ────────────────────────────────────────────────

const SYSTEM_PROMPT_DJ = `Eres un DJ y curador musical experto para una sala comunitaria.
Recibirás solicitudes de varios usuarios.
Analiza TODAS las solicitudes y sugiere las mejores canciones.
Responde SOLO con JSON: {"canciones": [{"titulo": "...", "artista": "...", "razon": "..."}]}`;

const SYSTEM_PROMPT_BUFFER = (preset: string, contextoDoradas: string) =>
  `Eres un DJ y curador musical experto para una sala comunitaria.
Ambiente actual: ${preset}.
Sugiere canciones variadas que encajen con el ambiente.
${contextoDoradas}
Responde SOLO con JSON: {"canciones": [{"titulo": "...", "artista": "...", "razon": "..."}]}
Sugiere entre 8 y 12 canciones.`;

// ── Tipos internos ───────────────────────────────────────────────

interface GoldSong {
  title: string;
  artist: string;
  thumbnail_url?: string;
  score?: number;
}

interface GroqSongResult {
  titulo?: string;
  artista?: string;
  razon?: string;
}

interface GroqResponse {
  canciones?: GroqSongResult[];
}

interface NowPlayingResponse {
  id?: number;
}

export interface UseDJConfig {
  autoMode: boolean;
  autoFill: boolean;
  preset: string;
  volume: number;
  videoEnabled: boolean;
  onStatusMessage: (msg: string) => void;
}

export interface UseDJReturn {
  queue: Song[];
  setQueue: React.Dispatch<React.SetStateAction<Song[]>>;
  buffer: Song[];
  currentSong: Song | null;
  isPlaying: boolean;
  searching: boolean;
  historial: SolicitudProcesada[];
  skip: () => void;
  stop: () => void;
  addToQueue: (songs: Song[]) => void;
  removeFromQueue: (index: number) => void;
  moveUp: (index: number) => void;
  voteInQueue: (index: number, delta: number) => void;
}

// ── Hook principal ───────────────────────────────────────────────

export function useDJ(config: UseDJConfig): UseDJReturn {
  const [queue, setQueue] = useState<Song[]>([]);
  const [buffer, setBuffer] = useState<Song[]>([]);
  const [currentSong, setCurrentSong] = useState<Song | null>(null);
  const [isPlaying, setIsPlaying] = useState(false);
  const [searching, setSearching] = useState(false);
  const [historial, setHistorial] = useState<SolicitudProcesada[]>([]);

  // Refs para evitar closures stale en los intervalos
  const queueRef = useRef(queue);
  const bufferRef = useRef(buffer);
  const isPlayingRef = useRef(isPlaying);
  const searchingRef = useRef(searching);
  const currentSongRef = useRef(currentSong);
  const configRef = useRef(config);
  const currentSongIdRef = useRef<number | null>(null);
  const rellenandoRef = useRef(false);
  const ultimoRefillRef = useRef(0);
  const wasPlayingRef = useRef(false);
  const stoppedManuallyRef = useRef(false);
  const reproducingLockRef = useRef(false);
  const processedIdsRef = useRef(new Set<number>());
  const processedActionIdsRef = useRef(new Set<number>());

  // Mantener refs sincronizados
  useEffect(() => { queueRef.current = queue; }, [queue]);
  useEffect(() => { bufferRef.current = buffer; }, [buffer]);
  useEffect(() => { isPlayingRef.current = isPlaying; }, [isPlaying]);
  useEffect(() => { searchingRef.current = searching; }, [searching]);
  useEffect(() => { currentSongRef.current = currentSong; }, [currentSong]);
  useEffect(() => { configRef.current = config; }, [config]);

  // ── Helpers ──────────────────────────────────────────────────

  const log = useCallback((msg: string) => {
    configRef.current.onStatusMessage(msg);
    logMessage(msg).catch(() => {});
  }, []);

  // ── Deteccion de comandos ────────────────────────────────────

  const detectarComandos = useCallback((solicitudes: Solicitud[]): Solicitud[] => {
    const restantes: Solicitud[] = [];
    for (const s of solicitudes) {
      const textoLimpio = s.texto.trim().replace(/^[!¡¿?.,' ]+|[!¡¿?.,' ]+$/g, '').toLowerCase();
      if (COMANDOS_SIGUIENTE.has(textoLimpio)) {
        log(`Comando recibido: siguiente (${s.email})`);
        playerStop().catch(() => {});
        // El playcheck detectara que paro y avanzara
      } else if (COMANDOS_PARAR.has(textoLimpio)) {
        log(`Comando recibido: parar (${s.email})`);
        playerStop().catch(() => {});
        setIsPlaying(false);
        setCurrentSong(null);
      } else {
        restantes.push(s);
      }
    }
    return restantes;
  }, [log]);

  // ── Deteccion de solicitudes directas (bypass Groq) ──────────

  const extraerDirectas = useCallback((solicitudes: Solicitud[]): {
    directas: Song[];
    paraGroq: Solicitud[];
  } => {
    const directas: Song[] = [];
    const paraGroq: Solicitud[] = [];

    for (const s of solicitudes) {
      const texto = s.texto.trim();
      const textoLimpio = texto.replace(/^[!¡¿?.,' ]+|[!¡¿?.,' ]+$/g, '');
      let query: string | null = null;

      // 1. Comando explicito: "pon X", "ponme X", etc.
      const match = PATRON_DIRECTO.exec(textoLimpio);
      if (match) {
        query = match[1].trim();
      }
      // 2. Solicitud corta sin palabras vagas
      else if (textoLimpio.split(/\s+/).length <= 5) {
        const palabras = new Set(textoLimpio.toLowerCase().split(/\s+/));
        const tieneVagas = [...palabras].some((p) => PALABRAS_VAGAS.has(p));
        if (!tieneVagas) {
          query = textoLimpio;
        }
      }

      if (query) {
        let titulo = query;
        let artista = '?';

        // Parsear "X de Y"
        const partsDe = query.split(/\s+de\s+/i);
        if (partsDe.length === 2) {
          titulo = partsDe[0].trim();
          artista = partsDe[1].trim();
        } else {
          // Parsear "Artista - Cancion"
          const partsDash = query.split(/\s*[-–]\s*/);
          if (partsDash.length === 2) {
            artista = partsDash[0].trim();
            titulo = partsDash[1].trim();
          }
        }

        directas.push({
          titulo,
          artista,
          razon: 'Pedido directo',
          votos_net: 0,
          solicitado_por: s.email || '',
          priority: (s.priority as 'normal' | 'now') || 'normal',
          thumbnail: '',
          texto_original: s.texto,
        });
      } else {
        paraGroq.push(s);
      }
    }

    return { directas, paraGroq };
  }, []);

  // ── Agregar a cola (con dedup) ───────────────────────────────

  const addToQueue = useCallback((canciones: Song[]) => {
    setQueue((prev) => {
      const existentes = new Set(
        [...prev, ...bufferRef.current].map(
          (c) => `${c.titulo.toLowerCase()}|${c.artista.toLowerCase()}`
        )
      );

      const nuevas: Song[] = [];
      for (const c of canciones) {
        const key = `${c.titulo.toLowerCase()}|${c.artista.toLowerCase()}`;
        if (!existentes.has(key)) {
          existentes.add(key);
          nuevas.push(c);
        }
      }

      // Las de priority=now van al inicio
      const ahora = nuevas.filter((c) => c.priority === 'now');
      const resto = nuevas.filter((c) => c.priority !== 'now');

      return [...ahora, ...prev, ...resto];
    });
  }, []);

  // ── Consultar Groq para sugerencias ──────────────────────────

  const consultarGroq = useCallback(async (solicitudes: Solicitud[]): Promise<Song[]> => {
    if (solicitudes.length === 0) return [];

    const solicitudesTexto = solicitudes
      .map((s) => `- ${s.email}: ${s.texto}`)
      .join('\n');

    const messages: GroqMessage[] = [
      { role: 'system', content: SYSTEM_PROMPT_DJ },
      {
        role: 'user',
        content: `## Solicitudes de los usuarios:\n${solicitudesTexto}`,
      },
    ];

    try {
      const respuesta = await groqChat(messages, 0.7, 2000, true);
      const parsed: GroqResponse = JSON.parse(respuesta);
      const canciones = parsed.canciones || [];

      const hasNow = solicitudes.some((s) => s.priority === 'now');

      return canciones.map((c) => ({
        titulo: c.titulo || '?',
        artista: c.artista || '?',
        razon: c.razon || '',
        votos_net: 0,
        solicitado_por: solicitudes[0]?.email || '',
        priority: hasNow ? 'now' : 'normal',
        thumbnail: '',
        texto_original: '',
      }));
    } catch (err) {
      log(`Error consultando Groq: ${err}`);
      return [];
    }
  }, [log]);

  // ── Reproducir una cancion ───────────────────────────────────

  const reproducirCancion = useCallback(async (song: Song) => {
    const query = `${song.titulo} ${song.artista}`;
    log(`Buscando: ${query}...`);
    setSearching(true);

    try {
      const resultados = await youtubeSearch(query);

      if (!resultados || resultados.length === 0) {
        log(`No encontrado en YouTube: ${query}`);
        setSearching(false);
        return false;
      }

      const url = resultados[0].url;
      const thumb = song.thumbnail || resultados[0].thumbnail || '';
      const vol = configRef.current.volume;
      const video = configRef.current.videoEnabled;

      await youtubePlay(url, vol, video);

      stoppedManuallyRef.current = false;
      setSearching(false);
      setIsPlaying(true);
      setCurrentSong({ ...song, thumbnail: thumb });

      // Notificar al servidor
      try {
        const resp = await apiPost<NowPlayingResponse>('now_playing', {
          title: song.titulo,
          artist: song.artista,
          url: url,
          requested_by: song.solicitado_por || '',
          thumbnail_url: thumb,
        });
        if (resp && resp.id) {
          currentSongIdRef.current = resp.id;
        }
      } catch {
        // No critico
      }

      log(`Reproduciendo: ${song.titulo} - ${song.artista}`);
      return true;
    } catch (err) {
      log(`Error reproduciendo: ${err}`);
      setSearching(false);
      return false;
    }
  }, [log]);

  // ── Reproducir siguiente de la cola ──────────────────────────

  const reproducirSiguiente = useCallback(async () => {
    // Lock: solo una instancia puede correr a la vez
    if (reproducingLockRef.current) return;
    if (searchingRef.current) return;
    reproducingLockRef.current = true;

    let currentQueue = queueRef.current;
    let currentBuffer = bufferRef.current;

    // Si la cola esta vacia, mover una del buffer
    if (currentQueue.length === 0 && currentBuffer.length > 0) {
      const [primera, ...restoBuffer] = currentBuffer;
      setBuffer(restoBuffer);
      setQueue([primera]);
      currentQueue = [primera];
      currentBuffer = restoBuffer;
      log(`Buffer: ${restoBuffer.length} canciones restantes`);
    }

    if (currentQueue.length === 0) {
      setCurrentSong(null);
      setIsPlaying(false);
      log('Cola vacia - esperando canciones');
      reproducingLockRef.current = false;
      return;
    }

    // Tomar la primera de la cola
    const [siguiente, ...restoCola] = currentQueue;
    setQueue(restoCola);

    const exito = await reproducirCancion(siguiente);
    reproducingLockRef.current = false;
    if (!exito) {
      // Si fallo, intentar la siguiente con delay
      setTimeout(() => {
        reproducirSiguiente();
      }, 2000);
    }
  }, [reproducirCancion, log]);

  // ── Skip ─────────────────────────────────────────────────────

  const skip = useCallback(() => {
    log('Cancion saltada');
    // Notificar skip al servidor
    if (currentSongIdRef.current) {
      apiPost('skip', { song_id: currentSongIdRef.current, reason: 'skip' }).catch(() => {});
    }
    playerStop().then(() => {
      setIsPlaying(false);
      setCurrentSong(null);
      // reproducirSiguiente se llamara desde el playcheck
      setTimeout(() => reproducirSiguiente(), 500);
    }).catch(() => {});
  }, [log, reproducirSiguiente]);

  // ── Stop ─────────────────────────────────────────────────────

  const stop = useCallback(() => {
    log('Reproduccion detenida');
    stoppedManuallyRef.current = true;
    wasPlayingRef.current = false;
    playerStop().catch(() => {});
    setIsPlaying(false);
    setCurrentSong(null);
  }, [log]);

  // ── Manipulacion de cola ─────────────────────────────────────

  const removeFromQueue = useCallback((index: number) => {
    setQueue((prev) => {
      if (index < 0 || index >= prev.length) return prev;
      const next = [...prev];
      next.splice(index, 1);
      return next;
    });
  }, []);

  const moveUp = useCallback((index: number) => {
    if (index <= 0) return;
    setQueue((prev) => {
      if (index >= prev.length) return prev;
      const next = [...prev];
      [next[index - 1], next[index]] = [next[index], next[index - 1]];
      return next;
    });
  }, []);

  const voteInQueue = useCallback((index: number, delta: number) => {
    setQueue((prev) => {
      if (index < 0 || index >= prev.length) return prev;
      const next = [...prev];
      next[index] = { ...next[index], votos_net: next[index].votos_net + delta };
      return next;
    });
  }, []);

  // ── Rellenar buffer ──────────────────────────────────────────

  const rellenarBuffer = useCallback(async () => {
    const cfg = configRef.current;
    if (!cfg.autoFill) return;
    if (rellenandoRef.current) return;
    if (bufferRef.current.length >= BUFFER_MIN) return;

    const ahora = Date.now();
    if (ahora - ultimoRefillRef.current < REFILL_COOLDOWN_MS) return;

    rellenandoRef.current = true;
    ultimoRefillRef.current = ahora;

    try {
      const existentes = new Set(
        [...queueRef.current, ...bufferRef.current].map(
          (c) => `${c.titulo.toLowerCase()}|${c.artista.toLowerCase()}`
        )
      );

      const nuevasBuffer: Song[] = [];

      // 1. Canciones doradas del servidor
      try {
        const doradas = await apiGet<GoldSong[]>('gold_songs');
        if (doradas && doradas.length > 0) {
          // Shuffle
          const shuffled = [...doradas].sort(() => Math.random() - 0.5);
          const nDoradas = Math.floor(BUFFER_TARGET * 0.6);
          for (const s of shuffled.slice(0, nDoradas)) {
            const key = `${(s.title || '').toLowerCase()}|${(s.artist || '').toLowerCase()}`;
            if (!existentes.has(key)) {
              existentes.add(key);
              nuevasBuffer.push({
                titulo: s.title,
                artista: s.artist,
                razon: 'Exito dorado',
                votos_net: 0,
                solicitado_por: '',
                priority: 'normal',
                thumbnail: s.thumbnail_url || '',
                texto_original: '',
              });
            }
          }
        }
      } catch {
        // No critico
      }

      // 2. Complementar con Groq
      if (nuevasBuffer.length < BUFFER_TARGET) {
        let contextoDoradas = '';
        try {
          const doradas = await apiGet<GoldSong[]>('gold_songs');
          if (doradas && doradas.length > 0) {
            const ejemplos = doradas.slice(0, 8).map((s) => `${s.title} - ${s.artist}`);
            contextoDoradas = `Canciones que han gustado mucho: ${ejemplos.join(', ')}. Sugiere cosas similares o del mismo estilo.`;
          }
        } catch {
          // No critico
        }

        try {
          const messages: GroqMessage[] = [
            {
              role: 'system',
              content: SYSTEM_PROMPT_BUFFER(cfg.preset, contextoDoradas),
            },
            {
              role: 'user',
              content: `Sugiere canciones para el ambiente "${cfg.preset}". No repitas estas que ya estan en cola: ${[...existentes].slice(0, 15).join(', ')}`,
            },
          ];

          const respuesta = await groqChat(messages, 0.9, 2000, true);
          const parsed: GroqResponse = JSON.parse(respuesta);
          const canciones = parsed.canciones || [];

          for (const c of canciones) {
            const t = c.titulo || '?';
            const a = c.artista || '?';
            const key = `${t.toLowerCase()}|${a.toLowerCase()}`;
            if (!existentes.has(key)) {
              existentes.add(key);
              nuevasBuffer.push({
                titulo: t,
                artista: a,
                razon: c.razon || '',
                votos_net: 0,
                solicitado_por: '',
                priority: 'normal',
                thumbnail: '',
                texto_original: '',
              });
            }
          }
        } catch (err) {
          log(`Error Groq buffer: ${err}`);
        }
      }

      if (nuevasBuffer.length > 0) {
        setBuffer((prev) => [...prev, ...nuevasBuffer]);
        log(`Buffer rellenado: +${nuevasBuffer.length} canciones`);
      }
    } catch (err) {
      log(`Error rellenando buffer: ${err}`);
    } finally {
      rellenandoRef.current = false;
    }
  }, [log]);

  // ── Auto tick: poll solicitudes cada POLL_MS ─────────────────

  useEffect(() => {
    if (!config.autoMode) return;

    const tick = async () => {
      try {
        // Obtener solicitudes pendientes
        const todasSolicitudes = await getSolicitudes();

        // Filtrar las que ya procesamos (por si mark_processed falla)
        const solicitudes = (todasSolicitudes || []).filter(
          (s) => !processedIdsRef.current.has(s.id)
        );

        if (solicitudes.length > 0) {
          // Marcar como procesadas localmente primero (para no repetir)
          const ids = solicitudes.map((s) => s.id);
          for (const id of ids) {
            processedIdsRef.current.add(id);
          }
          // Limpiar IDs viejos (mantener últimos 500)
          if (processedIdsRef.current.size > 500) {
            const arr = [...processedIdsRef.current];
            processedIdsRef.current = new Set(arr.slice(-200));
          }

          // Marcar en servidor
          try {
            const resp = await apiPost('mark_processed', { ids });
            log(`mark_processed: ${JSON.stringify(resp)}`);
          } catch (err) {
            log(`Error marcando solicitudes: ${err}`);
          }

          // 1. Detectar y ejecutar comandos
          const sinComandos = detectarComandos(solicitudes);

          // Guardar comandos en historial
          const comandos = solicitudes.filter((s) => !sinComandos.includes(s));
          for (const cmd of comandos) {
            setHistorial((prev) => [...prev, {
              solicitud: cmd,
              canciones: [],
              tipo: 'comando',
              timestamp: Date.now(),
            }]);
          }

          // 2. Separar directas de las que necesitan Groq
          const { directas, paraGroq } = extraerDirectas(sinComandos);

          // 3. Agregar directas a la cola + guardar en historial
          if (directas.length > 0) {
            log(`${directas.length} pedido(s) directo(s) detectado(s)`);
            addToQueue(directas);
            // Asociar cada directa con su solicitud original
            for (const d of directas) {
              const solOriginal = sinComandos.find((s) => s.texto === d.texto_original);
              if (solOriginal) {
                setHistorial((prev) => [...prev, {
                  solicitud: solOriginal,
                  canciones: [d],
                  tipo: 'directa',
                  timestamp: Date.now(),
                }]);
              }
            }
          }

          // 4. Consultar Groq con el resto
          if (paraGroq.length > 0) {
            log('Consultando IA con solicitudes...');
            const cancionesGroq = await consultarGroq(paraGroq);
            if (cancionesGroq.length > 0) {
              // Guardar en historial
              for (const s of paraGroq) {
                setHistorial((prev) => [...prev, {
                  solicitud: s,
                  canciones: cancionesGroq,
                  tipo: 'groq',
                  timestamp: Date.now(),
                }]);
              }

              // Las de priority=now van a la cola, el resto al buffer
              const ahora = cancionesGroq.filter((c) => c.priority === 'now');
              const resto = cancionesGroq.filter((c) => c.priority !== 'now');

              if (ahora.length > 0) {
                addToQueue(ahora);
              }
              if (resto.length > 0) {
                setBuffer((prev) => [...prev, ...resto]);
                log(`Buffer: +${resto.length} canciones del IA`);
              }
            }
          }
        }

        // Si hay cancion con priority=now en la cola y algo sonando, saltar
        const q = queueRef.current;
        if (q.length > 0 && q[0].priority === 'now' && isPlayingRef.current) {
          log('Prioridad inmediata detectada, saltando...');
          await playerStop();
          setIsPlaying(false);
          setTimeout(() => reproducirSiguiente(), 500);
          return;
        }

        // Si no esta sonando nada, no esta buscando, y no hay lock → reproducir
        if (!isPlayingRef.current && !searchingRef.current && !reproducingLockRef.current) {
          if (queueRef.current.length > 0 || bufferRef.current.length > 0) {
            reproducirSiguiente();
          }
        }

        // Mantener buffer lleno en background
        if (bufferRef.current.length < BUFFER_MIN) {
          rellenarBuffer();
        }
      } catch (err) {
        log(`Error auto tick: ${err}`);
      }
    };

    // Ejecutar inmediatamente y luego cada POLL_MS
    tick();
    const interval = setInterval(tick, POLL_MS);
    return () => clearInterval(interval);
  }, [config.autoMode, detectarComandos, extraerDirectas, addToQueue, consultarGroq, log, rellenarBuffer, reproducirSiguiente]);

  // ── Playcheck: detectar fin de cancion cada PLAYCHECK_MS ─────

  useEffect(() => {
    const check = async () => {
      if (searchingRef.current) return;

      try {
        const playing = await playerIsPlaying();

        // Detectar transicion: estaba reproduciendo y ahora no
        if (wasPlayingRef.current && !playing) {
          if (stoppedManuallyRef.current) {
            // Fue un stop/pause deliberado, no avanzar
            stoppedManuallyRef.current = false;
          } else {
            log('Cancion terminada, avanzando...');
            setIsPlaying(false);
            setCurrentSong(null);
            if (configRef.current.autoMode) {
              setTimeout(() => reproducirSiguiente(), 500);
            }
          }
        }

        wasPlayingRef.current = playing;

        // Sincronizar estado (por si se detiene externamente)
        if (playing !== isPlayingRef.current && !searchingRef.current) {
          setIsPlaying(playing);
        }
      } catch {
        // playerIsPlaying puede fallar si no hay proceso mpv
      }
    };

    const interval = setInterval(check, PLAYCHECK_MS);
    return () => clearInterval(interval);
  }, [log, reproducirSiguiente]);

  // ── Sync cola al servidor + leer acciones web cada SYNC_MS ──

  useEffect(() => {
    if (!config.autoMode) return;

    const sync = async () => {
      try {
        // 1. Sincronizar cola y buffer al servidor (para que la web los vea)
        await apiPost('sync_queue', {
          queue: queueRef.current.map((s) => ({
            titulo: s.titulo,
            artista: s.artista,
            razon: s.razon,
            priority: s.priority,
            solicitado_por: s.solicitado_por,
            thumbnail_url: s.thumbnail,
          })),
          buffer: bufferRef.current.map((s) => ({
            titulo: s.titulo,
            artista: s.artista,
            razon: s.razon,
            priority: s.priority,
            solicitado_por: s.solicitado_por,
            thumbnail_url: s.thumbnail,
          })),
        });

        // 2. Leer acciones pendientes de la web (skip, pause, remove, etc.)
        const todasAcciones = await apiGet<Array<{ id: number; action: string; data: string }>>('pending_queue_actions');
        // Filtrar las que ya procesamos localmente
        const acciones = (todasAcciones || []).filter(
          (a) => !processedActionIdsRef.current.has(a.id)
        );
        if (acciones.length > 0) {
          const ids: number[] = [];
          for (const a of acciones) {
            ids.push(a.id);
            processedActionIdsRef.current.add(a.id);
            let data: Record<string, unknown> = {};
            try { data = JSON.parse(a.data || '{}'); } catch { /* ignore */ }

            if (a.action === 'skip') {
              log('Web pidió skip');
              skip();
            } else if (a.action === 'pause') {
              log('Web pidió pause');
              stop();
            } else if (a.action === 'remove') {
              const pos = Number(data.position ?? -1);
              const source = (data.source as string) || 'queue';
              if (source === 'queue' && pos >= 0) {
                removeFromQueue(pos);
              } else if (source === 'buffer' && pos >= 0) {
                setBuffer((prev) => prev.filter((_, i) => i !== pos));
              }
            } else if (a.action === 'clear') {
              const source = (data.source as string) || 'queue';
              if (source === 'buffer') {
                setBuffer([]);
                log('Web limpió buffer');
              } else {
                setQueue([]);
                log('Web limpió cola');
              }
            }
          }
          // Marcar acciones como procesadas
          try {
            const resp = await apiPost('mark_queue_actions', { ids });
            log(`mark_queue_actions: ${JSON.stringify(resp)}`);
          } catch (err) {
            log(`Error marcando acciones: ${err}`);
          }
        }
      } catch {
        // No critico
      }
    };

    const interval = setInterval(sync, SYNC_MS);
    return () => clearInterval(interval);
  }, [config.autoMode, skip, stop, removeFromQueue, log]);

  // ── Auto-rellenar: solo rellenar buffer si está bajo (el auto_tick mueve del buffer a cola)

  return {
    queue,
    setQueue,
    buffer,
    currentSong,
    isPlaying,
    searching,
    historial,
    skip,
    stop,
    addToQueue,
    removeFromQueue,
    moveUp,
    voteInQueue,
  };
}
