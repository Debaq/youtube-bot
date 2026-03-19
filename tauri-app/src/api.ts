import { invoke } from '@tauri-apps/api/core';
import type { Config, YoutubeResult } from './types';

// ── Configuración ──────────────────────────────────────────────

export async function loadConfig(): Promise<Config> {
  return await invoke<Config>('load_config');
}

export async function saveConfig(config: Config): Promise<void> {
  await invoke('save_config', { config });
}

// ── API PHP (usa config interna de Rust) ───────────────────────

export async function apiGet<T>(action: string): Promise<T> {
  return await invoke<T>('api_get', { action });
}

export async function apiPost<T>(action: string, data: Record<string, unknown>): Promise<T> {
  return await invoke<T>('api_post', { action, data });
}

// ── Groq IA (usa config interna de Rust) ───────────────────────

export interface GroqMessage {
  role: 'system' | 'user' | 'assistant';
  content: string;
}

export async function groqChat(
  messages: GroqMessage[],
  temperature?: number,
  maxTokens?: number,
  jsonMode?: boolean,
): Promise<string> {
  return await invoke<string>('groq_chat', {
    messages,
    temperature: temperature ?? null,
    maxTokens: maxTokens ?? null,
    jsonMode: jsonMode ?? null,
  });
}

export async function groqListModels(): Promise<string[]> {
  return await invoke<string[]>('groq_list_models');
}

// ── Tests de conexión ──────────────────────────────────────────

export async function testApiConnection(): Promise<string> {
  return await invoke<string>('test_api_connection');
}

export async function testGroqConnection(): Promise<string> {
  return await invoke<string>('test_groq_connection');
}

export async function testYoutube(): Promise<string> {
  return await invoke<string>('test_youtube');
}

export async function testMpv(): Promise<string> {
  return await invoke<string>('test_mpv');
}

// ── YouTube ────────────────────────────────────────────────────

export async function youtubeSearch(query: string): Promise<YoutubeResult[]> {
  return await invoke<YoutubeResult[]>('youtube_search', { query });
}

export async function youtubePlay(url: string, volume?: number, video?: boolean): Promise<void> {
  await invoke('youtube_play', {
    url,
    volume: volume ?? null,
    video: video ?? null,
  });
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
