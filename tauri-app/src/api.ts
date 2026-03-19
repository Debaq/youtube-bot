import { invoke } from '@tauri-apps/api/core';
import type { Config, Song, YoutubeResult, Solicitud } from './types';

// ── Configuración ──────────────────────────────────────────────

export async function loadConfig(): Promise<Config> {
  return await invoke<Config>('load_config');
}

export async function saveConfig(config: Config): Promise<void> {
  await invoke('save_config', { config });
}

// ── API genérica (proxy al server Python) ──────────────────────

export async function apiGet<T>(endpoint: string): Promise<T> {
  return await invoke<T>('api_get', { endpoint });
}

export async function apiPost<T>(endpoint: string, body: Record<string, unknown>): Promise<T> {
  return await invoke<T>('api_post', { endpoint, body });
}

// ── Groq IA ────────────────────────────────────────────────────

export async function groqChat(
  prompt: string,
  systemPrompt: string
): Promise<string> {
  return await invoke<string>('groq_chat', { prompt, systemPrompt });
}

export async function groqListModels(): Promise<string[]> {
  return await invoke<string[]>('groq_list_models');
}

// ── YouTube ────────────────────────────────────────────────────

export async function youtubeSearch(query: string): Promise<YoutubeResult[]> {
  return await invoke<YoutubeResult[]>('youtube_search', { query });
}

export async function youtubePlay(url: string): Promise<void> {
  await invoke('youtube_play', { url });
}

// ── Player ─────────────────────────────────────────────────────

export async function playerStop(): Promise<void> {
  await invoke('player_stop');
}

export async function playerIsPlaying(): Promise<boolean> {
  return await invoke<boolean>('player_is_playing');
}

export async function playerSetVolume(volume: number): Promise<void> {
  await invoke('player_set_volume', { volume });
}

export async function playerSetVideo(enabled: boolean): Promise<void> {
  await invoke('player_set_video', { enabled });
}

// ── Cola ───────────────────────────────────────────────────────

export async function getQueue(): Promise<Song[]> {
  return await invoke<Song[]>('get_queue');
}

export async function getCurrentSong(): Promise<Song | null> {
  return await invoke<Song | null>('get_current_song');
}

export async function skipSong(): Promise<void> {
  await invoke('skip_song');
}

export async function removeSong(index: number): Promise<void> {
  await invoke('remove_song', { index });
}

export async function moveSongUp(index: number): Promise<void> {
  await invoke('move_song_up', { index });
}

export async function voteSong(index: number, delta: number): Promise<void> {
  await invoke('vote_song', { index, delta });
}

// ── Solicitudes ────────────────────────────────────────────────

export async function getSolicitudes(): Promise<Solicitud[]> {
  return await invoke<Solicitud[]>('get_solicitudes');
}

export async function approveSolicitud(id: number): Promise<void> {
  await invoke('approve_solicitud', { id });
}

export async function rejectSolicitud(id: number): Promise<void> {
  await invoke('reject_solicitud', { id });
}

// ── Controles de estado ────────────────────────────────────────

export async function setAutoMode(enabled: boolean): Promise<void> {
  await invoke('set_auto_mode', { enabled });
}

export async function setAutoFill(enabled: boolean): Promise<void> {
  await invoke('set_auto_fill', { enabled });
}

export async function setScheduleEnabled(enabled: boolean): Promise<void> {
  await invoke('set_schedule_enabled', { enabled });
}

export async function setMoodEnabled(enabled: boolean): Promise<void> {
  await invoke('set_mood_enabled', { enabled });
}

export async function setPreset(presetName: string): Promise<void> {
  await invoke('set_preset', { presetName });
}

export async function getStatus(): Promise<Record<string, unknown>> {
  return await invoke<Record<string, unknown>>('get_status');
}

// ── Sistema ────────────────────────────────────────────────────

export async function setAutostart(enabled: boolean): Promise<void> {
  await invoke('set_autostart', { enabled });
}

export async function openUrl(url: string): Promise<void> {
  await invoke('open_url', { url });
}

export async function logMessage(message: string): Promise<void> {
  await invoke('log_message', { message });
}

export async function getLogs(): Promise<string[]> {
  return await invoke<string[]>('get_logs');
}
