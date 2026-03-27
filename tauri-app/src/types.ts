export interface Config {
  server_url: string;
  api_key: string;
  groq_api_key: string;
  groq_model: string;
  weather_city: string;
  autostart: boolean;
  minimize_to_tray: boolean;
}

export interface Song {
  titulo: string;
  artista: string;
  razon: string;
  votos_net: number;
  solicitado_por: string;
  priority: 'normal' | 'now';
  thumbnail: string;
  texto_original: string;
}

export interface YoutubeResult {
  titulo: string;
  url: string;
  duracion: number | null;
  thumbnail: string;
}

export interface Solicitud {
  id: number;
  email: string;
  texto: string;
  priority: string;
}

export interface SolicitudProcesada {
  solicitud: Solicitud;
  canciones: Song[];
  tipo: 'directa' | 'groq' | 'comando';
  timestamp: number;
}

export type DJPreset = {
  name: string;
  description: string;
};

export const DJ_PRESETS: DJPreset[] = [
  { name: "Estudio tranquilo", description: "Musica instrumental, piano, ambient para concentrarse" },
  { name: "Fiesta total", description: "Reggaeton, EDM, alta energia para bailar" },
  { name: "Rock clasico", description: "Led Zeppelin, Queen, Pink Floyd, AC/DC" },
  { name: "Chill lofi", description: "Lo-fi hip hop, beats relajantes para estudiar" },
  { name: "Jazz nocturno", description: "Jazz suave, bossa nova, musica de bar" },
  { name: "Pop actual", description: "Exitos pop del momento, top charts" },
  { name: "Metal sinfonico", description: "Nightwish, Epica, Within Temptation" },
  { name: "Electronica 90s", description: "Trance, techno, eurodance de los 90" },
  { name: "Reggae vibes", description: "Bob Marley, reggae, ska, dub" },
  { name: "Hip hop clasico", description: "Tupac, Biggie, Nas, Wu-Tang Clan" },
  { name: "Baladas romanticas", description: "Baladas en espanol e ingles para el corazon" },
  { name: "Indie alternativo", description: "Arctic Monkeys, Tame Impala, The Strokes" },
  { name: "Musica latina", description: "Salsa, cumbia, bachata, merengue" },
  { name: "Clasica relajante", description: "Mozart, Beethoven, Chopin, musica de camara" },
  { name: "Country & folk", description: "Johnny Cash, folk americano, country moderno" },
  { name: "Funk & soul", description: "James Brown, Stevie Wonder, Earth Wind & Fire" },
  { name: "Anime & J-Pop", description: "Openings de anime, J-Pop, J-Rock" },
  { name: "Blues profundo", description: "B.B. King, Muddy Waters, blues electrico" },
  { name: "Soundtrack epico", description: "Bandas sonoras de peliculas y videojuegos" },
  { name: "Musica del mundo", description: "World music, flamenco, celtic, african beats" },
  { name: "DJ libre", description: "Sin restricciones, la IA elige lo mejor del momento" },
];

export interface PlayerState {
  isPlaying: boolean;
  currentSong: Song | null;
  volume: number;
  videoEnabled: boolean;
}

export interface AppState {
  autoMode: boolean;
  autoFill: boolean;
  videoEnabled: boolean;
  scheduleEnabled: boolean;
  moodEnabled: boolean;
  autostartEnabled: boolean;
  trayEnabled: boolean;
}
