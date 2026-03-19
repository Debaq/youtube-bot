// Prevents additional console window on Windows in release
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use serde::{Deserialize, Serialize};
use serde_json::Value;
use std::io::Write;
use std::process::{Child, Command};
use std::sync::Mutex;
use tauri::State;

// ---------------------------------------------------------------------------
// Tipos
// ---------------------------------------------------------------------------

#[derive(Debug, Clone, Serialize, Deserialize)]
struct Config {
    server_url: String,
    api_key: String,
    groq_api_key: String,
    groq_model: String,
    weather_city: String,
    autostart: bool,
    minimize_to_tray: bool,
}

impl Default for Config {
    fn default() -> Self {
        Self {
            server_url: String::new(),
            api_key: String::new(),
            groq_api_key: String::new(),
            groq_model: "llama-3.3-70b-versatile".into(),
            weather_city: "Valdivia".into(),
            autostart: false,
            minimize_to_tray: false,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
struct YoutubeResult {
    titulo: String,
    url: String,
    duracion: Option<u64>,
    thumbnail: String,
}

#[derive(Debug, Serialize, Deserialize)]
struct GroqMessage {
    role: String,
    content: String,
}

// ---------------------------------------------------------------------------
// Estado global
// ---------------------------------------------------------------------------

struct MpvProcess(Mutex<Option<Child>>);
struct AppConfig(Mutex<Config>);

// ---------------------------------------------------------------------------
// Error serializable para Tauri commands
// ---------------------------------------------------------------------------

#[derive(Debug, thiserror::Error)]
enum AppError {
    #[error("{0}")]
    Generic(String),
}

impl Serialize for AppError {
    fn serialize<S>(&self, serializer: S) -> Result<S::Ok, S::Error>
    where
        S: serde::Serializer,
    {
        serializer.serialize_str(&self.to_string())
    }
}

impl From<std::io::Error> for AppError {
    fn from(e: std::io::Error) -> Self {
        AppError::Generic(e.to_string())
    }
}

impl From<reqwest::Error> for AppError {
    fn from(e: reqwest::Error) -> Self {
        AppError::Generic(e.to_string())
    }
}

impl From<serde_json::Error> for AppError {
    fn from(e: serde_json::Error) -> Self {
        AppError::Generic(e.to_string())
    }
}

type CmdResult<T> = Result<T, AppError>;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

fn config_path() -> std::path::PathBuf {
    // En dev, usar el directorio de trabajo (raíz del proyecto)
    // En producción, junto al ejecutable
    if cfg!(debug_assertions) {
        let mut path = std::env::current_dir().unwrap_or_default();
        path.push("config.json");
        if path.exists() {
            return path;
        }
        // Fallback: buscar en el padre (por si cwd es src-tauri)
        let mut parent = std::env::current_dir().unwrap_or_default();
        parent.pop();
        parent.push("config.json");
        if parent.exists() {
            return parent;
        }
    }
    let mut path = std::env::current_exe().unwrap_or_default();
    path.pop();
    path.push("config.json");
    path
}

fn read_config_from_disk() -> Config {
    let path = config_path();
    if path.exists() {
        let data = std::fs::read_to_string(&path).unwrap_or_default();
        serde_json::from_str(&data).unwrap_or_default()
    } else {
        Config::default()
    }
}

const MPV_SOCKET: &str = "/tmp/musicbot-mpv.sock";


fn mpv_send_command(args: &[&str]) -> Result<(), AppError> {
    use std::os::unix::net::UnixStream;

    let command = serde_json::json!({ "command": args });
    let mut payload = serde_json::to_string(&command)
        .map_err(|e| AppError::Generic(e.to_string()))?;
    payload.push('\n');

    let mut stream = UnixStream::connect(MPV_SOCKET)
        .map_err(|e| AppError::Generic(format!("No se pudo conectar al socket mpv: {e}")))?;
    stream
        .write_all(payload.as_bytes())
        .map_err(|e| AppError::Generic(format!("Error enviando comando a mpv: {e}")))?;
    Ok(())
}

fn timestamp_now() -> String {
    use std::time::{SystemTime, UNIX_EPOCH};
    let secs = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs();
    // Convertir a formato legible sin chrono
    let s = secs % 60;
    let m = (secs / 60) % 60;
    let h = (secs / 3600) % 24;
    format!("{h:02}:{m:02}:{s:02}")
}

// ---------------------------------------------------------------------------
// Commands: Configuracion
// ---------------------------------------------------------------------------

#[tauri::command]
fn load_config(app_config: State<AppConfig>) -> CmdResult<Config> {
    let cfg = read_config_from_disk();
    // Actualizar estado en memoria
    if let Ok(mut guard) = app_config.0.lock() {
        *guard = cfg.clone();
    }
    Ok(cfg)
}

#[tauri::command]
fn save_config(config: Config, app_config: State<AppConfig>) -> CmdResult<()> {
    let path = config_path();
    let data = serde_json::to_string_pretty(&config)?;
    std::fs::write(&path, data)?;
    // Actualizar estado en memoria
    if let Ok(mut guard) = app_config.0.lock() {
        *guard = config;
    }
    Ok(())
}

// ---------------------------------------------------------------------------
// Commands: API PHP Client (lee config del estado)
// ---------------------------------------------------------------------------

#[tauri::command]
async fn api_get(action: String, app_config: State<'_, AppConfig>) -> CmdResult<Value> {
    let (base_url, api_key) = {
        let guard = app_config.0.lock().map_err(|e| AppError::Generic(e.to_string()))?;
        (guard.server_url.clone(), guard.api_key.clone())
    };
    if base_url.is_empty() {
        return Err(AppError::Generic("server_url no configurado".into()));
    }
    let client = reqwest::Client::new();
    let url = format!("{}/api.php", base_url.trim_end_matches('/'));
    let resp = client
        .get(&url)
        .query(&[("action", &action)])
        .header("X-API-Key", &api_key)
        .timeout(std::time::Duration::from_secs(8))
        .send()
        .await?;
    let body: Value = resp.json().await?;
    Ok(body)
}

#[tauri::command]
async fn api_post(action: String, data: Value, app_config: State<'_, AppConfig>) -> CmdResult<Value> {
    let (base_url, api_key) = {
        let guard = app_config.0.lock().map_err(|e| AppError::Generic(e.to_string()))?;
        (guard.server_url.clone(), guard.api_key.clone())
    };
    if base_url.is_empty() {
        return Err(AppError::Generic("server_url no configurado".into()));
    }
    let client = reqwest::Client::new();
    let url = format!("{}/api.php?action={}", base_url.trim_end_matches('/'), action);

    // Debug: ver qué llega de Tauri
    println!("[api_post] action={action}, data={data}");

    let payload = match data.clone() {
        Value::Object(map) => map,
        _ => serde_json::Map::new(),
    };

    println!("[api_post] payload={}", Value::Object(payload.clone()));

    let resp = client
        .post(&url)
        .header("X-API-Key", &api_key)
        .json(&Value::Object(payload))
        .timeout(std::time::Duration::from_secs(8))
        .send()
        .await?;
    let body: Value = resp.json().await?;
    Ok(body)
}

// ---------------------------------------------------------------------------
// Commands: Groq (lee config del estado)
// ---------------------------------------------------------------------------

#[tauri::command]
async fn groq_chat(
    messages: Vec<GroqMessage>,
    temperature: Option<f64>,
    max_tokens: Option<u32>,
    json_mode: Option<bool>,
    app_config: State<'_, AppConfig>,
) -> CmdResult<String> {
    let (api_key, model) = {
        let guard = app_config.0.lock().map_err(|e| AppError::Generic(e.to_string()))?;
        (guard.groq_api_key.clone(), guard.groq_model.clone())
    };
    if api_key.is_empty() {
        return Err(AppError::Generic("groq_api_key no configurado".into()));
    }

    let temp = temperature.unwrap_or(0.8);
    let tokens = max_tokens.unwrap_or(1024);
    let json = json_mode.unwrap_or(false);

    let client = reqwest::Client::new();
    let mut body = serde_json::json!({
        "model": model,
        "messages": messages,
        "temperature": temp,
        "max_tokens": tokens,
    });

    if json {
        body["response_format"] = serde_json::json!({"type": "json_object"});
    }

    let resp = client
        .post("https://api.groq.com/openai/v1/chat/completions")
        .header("Authorization", format!("Bearer {api_key}"))
        .header("Content-Type", "application/json")
        .json(&body)
        .timeout(std::time::Duration::from_secs(30))
        .send()
        .await?;

    let data: Value = resp.json().await?;

    if let Some(err) = data["error"]["message"].as_str() {
        return Err(AppError::Generic(format!("Groq error: {err}")));
    }

    let content = data["choices"][0]["message"]["content"]
        .as_str()
        .unwrap_or("")
        .to_string();
    Ok(content)
}

#[tauri::command]
async fn groq_list_models(app_config: State<'_, AppConfig>) -> CmdResult<Vec<String>> {
    let api_key = {
        let guard = app_config.0.lock().map_err(|e| AppError::Generic(e.to_string()))?;
        guard.groq_api_key.clone()
    };
    if api_key.is_empty() {
        return Err(AppError::Generic("groq_api_key no configurado".into()));
    }

    let client = reqwest::Client::new();
    let resp = client
        .get("https://api.groq.com/openai/v1/models")
        .header("Authorization", format!("Bearer {api_key}"))
        .timeout(std::time::Duration::from_secs(10))
        .send()
        .await?;

    let data: Value = resp.json().await?;

    // Filtrar solo modelos aptos para chat (excluir whisper, etc.)
    let excluir = ["whisper", "prompt-guard", "compound", "orpheus", "safeguard"];
    let models = data["data"]
        .as_array()
        .map(|arr| {
            arr.iter()
                .filter_map(|m| {
                    let id = m["id"].as_str()?;
                    let active = m["active"].as_bool().unwrap_or(true);
                    if active && !excluir.iter().any(|ex| id.contains(ex)) {
                        Some(id.to_string())
                    } else {
                        None
                    }
                })
                .collect()
        })
        .unwrap_or_default();

    Ok(models)
}

// ---------------------------------------------------------------------------
// Commands: Test de conexión
// ---------------------------------------------------------------------------

#[tauri::command]
async fn test_api_connection(app_config: State<'_, AppConfig>) -> CmdResult<String> {
    let (base_url, api_key) = {
        let guard = app_config.0.lock().map_err(|e| AppError::Generic(e.to_string()))?;
        (guard.server_url.clone(), guard.api_key.clone())
    };
    if base_url.is_empty() {
        return Err(AppError::Generic("server_url no configurado. Guarda la config primero.".into()));
    }
    let client = reqwest::Client::new();
    let url = format!("{}/api.php", base_url.trim_end_matches('/'));
    let resp = client
        .get(&url)
        .query(&[("action", "stats")])
        .header("X-API-Key", &api_key)
        .timeout(std::time::Duration::from_secs(5))
        .send()
        .await
        .map_err(|e| AppError::Generic(format!("No se pudo conectar a {url}: {e}")))?;
    let status = resp.status();
    let text = resp.text().await.unwrap_or_default();
    if status.is_success() {
        Ok(format!("OK (HTTP {}) - {}", status.as_u16(), &text[..text.len().min(100)]))
    } else {
        Err(AppError::Generic(format!("HTTP {} en {url} - {}", status.as_u16(), &text[..text.len().min(200)])))
    }
}

#[tauri::command]
async fn test_groq_connection(app_config: State<'_, AppConfig>) -> CmdResult<String> {
    let (api_key, model) = {
        let guard = app_config.0.lock().map_err(|e| AppError::Generic(e.to_string()))?;
        (guard.groq_api_key.clone(), guard.groq_model.clone())
    };
    if api_key.is_empty() {
        return Err(AppError::Generic("groq_api_key no configurado".into()));
    }
    let client = reqwest::Client::new();
    let body = serde_json::json!({
        "model": model,
        "messages": [{"role": "user", "content": "Responde solo 'OK'"}],
        "temperature": 0.1,
        "max_tokens": 10,
    });
    let resp = client
        .post("https://api.groq.com/openai/v1/chat/completions")
        .header("Authorization", format!("Bearer {api_key}"))
        .header("Content-Type", "application/json")
        .json(&body)
        .timeout(std::time::Duration::from_secs(10))
        .send()
        .await?;
    let status = resp.status();
    let data: Value = resp.json().await?;
    let content = data["choices"][0]["message"]["content"]
        .as_str()
        .unwrap_or("(sin respuesta)");
    Ok(format!("HTTP {} - Modelo: {} - Respuesta: {}", status.as_u16(), model, content))
}

#[tauri::command]
async fn test_youtube() -> CmdResult<String> {
    let output = Command::new("yt-dlp")
        .args(["--version"])
        .output();
    match output {
        Ok(o) if o.status.success() => {
            let version = String::from_utf8_lossy(&o.stdout).trim().to_string();
            Ok(format!("yt-dlp v{version} - OK"))
        }
        Ok(o) => Err(AppError::Generic(format!("yt-dlp error: {}", String::from_utf8_lossy(&o.stderr)))),
        Err(_) => Err(AppError::Generic("yt-dlp no instalado. Ejecuta: sudo pacman -S yt-dlp".into())),
    }
}

#[tauri::command]
async fn test_mpv() -> CmdResult<String> {
    let output = Command::new("mpv")
        .args(["--version"])
        .output();
    match output {
        Ok(o) if o.status.success() => {
            let first_line = String::from_utf8_lossy(&o.stdout)
                .lines()
                .next()
                .unwrap_or("OK")
                .to_string();
            Ok(first_line)
        }
        Ok(o) => Err(AppError::Generic(format!("mpv salió con error: {}", String::from_utf8_lossy(&o.stderr)))),
        Err(e) => Err(AppError::Generic(format!("mpv no encontrado: {e}"))),
    }
}

// ---------------------------------------------------------------------------
// Commands: YouTube (yt-dlp via subprocess)
// ---------------------------------------------------------------------------

#[tauri::command]
async fn youtube_search(query: String) -> CmdResult<Vec<YoutubeResult>> {
    let output = Command::new("yt-dlp")
        .args([
            "--dump-json",
            "--flat-playlist",
            "--no-warnings",
            "--default-search",
            "ytsearch5",
            &query,
        ])
        .output()?;

    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr);
        return Err(AppError::Generic(format!("yt-dlp error: {stderr}")));
    }

    let stdout = String::from_utf8_lossy(&output.stdout);
    let mut results: Vec<YoutubeResult> = Vec::new();

    for line in stdout.lines() {
        if line.trim().is_empty() {
            continue;
        }
        if let Ok(entry) = serde_json::from_str::<Value>(line) {
            let titulo = entry["title"].as_str().unwrap_or("Sin titulo").to_string();
            let url = entry["url"]
                .as_str()
                .or_else(|| entry["webpage_url"].as_str())
                .map(|u| {
                    if u.starts_with("http") {
                        u.to_string()
                    } else {
                        format!("https://www.youtube.com/watch?v={u}")
                    }
                })
                .unwrap_or_default();
            let duracion = entry["duration"].as_u64();
            let thumbnail = entry["thumbnail"]
                .as_str()
                .or_else(|| {
                    entry["thumbnails"]
                        .as_array()
                        .and_then(|arr| arr.last())
                        .and_then(|t| t["url"].as_str())
                })
                .unwrap_or("")
                .to_string();

            results.push(YoutubeResult {
                titulo,
                url,
                duracion,
                thumbnail,
            });
        }
    }

    Ok(results)
}

#[tauri::command]
fn youtube_play(
    url: String,
    volume: Option<u32>,
    video: Option<bool>,
    mpv_state: State<MpvProcess>,
) -> CmdResult<bool> {
    // Matar proceso previo
    if let Ok(mut guard) = mpv_state.0.lock() {
        if let Some(ref mut child) = *guard {
            let _ = child.kill();
            let _ = child.wait();
        }
        *guard = None;
    }

    let _ = std::fs::remove_file(MPV_SOCKET);

    let vol = volume.unwrap_or(80);
    let vid = video.unwrap_or(false);

    let mut args = vec![
        url.clone(),
        format!("--volume={vol}"),
        format!("--input-ipc-server={MPV_SOCKET}"),
        "--no-terminal".to_string(),
    ];

    if !vid {
        args.push("--no-video".to_string());
    }

    let child = Command::new("mpv").args(&args).spawn()?;

    if let Ok(mut guard) = mpv_state.0.lock() {
        *guard = Some(child);
    }

    Ok(true)
}

#[tauri::command]
fn player_stop(mpv_state: State<MpvProcess>) -> CmdResult<()> {
    if let Ok(mut guard) = mpv_state.0.lock() {
        if let Some(ref mut child) = *guard {
            let _ = child.kill();
            let _ = child.wait();
        }
        *guard = None;
    }
    let _ = std::fs::remove_file(MPV_SOCKET);
    Ok(())
}

#[tauri::command]
fn player_is_playing(mpv_state: State<MpvProcess>) -> CmdResult<bool> {
    if let Ok(mut guard) = mpv_state.0.lock() {
        if let Some(ref mut child) = *guard {
            match child.try_wait() {
                Ok(Some(_)) => {
                    *guard = None;
                    return Ok(false);
                }
                Ok(None) => return Ok(true),
                Err(_) => {
                    *guard = None;
                    return Ok(false);
                }
            }
        }
    }
    Ok(false)
}

#[tauri::command]
fn player_set_volume(volume: u32) -> CmdResult<()> {
    mpv_send_command(&["set_property", "volume", &volume.to_string()])
}

#[tauri::command]
fn player_set_video(enabled: bool) -> CmdResult<()> {
    let val = if enabled { "auto" } else { "no" };
    mpv_send_command(&["set_property", "vid", val])
}

// ---------------------------------------------------------------------------
// Commands: Sistema
// ---------------------------------------------------------------------------

#[tauri::command]
fn set_autostart(enabled: bool) -> CmdResult<()> {
    let home = std::env::var("HOME")
        .map_err(|_| AppError::Generic("No se encontro $HOME".into()))?;
    let autostart_dir = format!("{home}/.config/autostart");
    let desktop_path = format!("{autostart_dir}/musicbot.desktop");

    if enabled {
        std::fs::create_dir_all(&autostart_dir)?;
        let exe = std::env::current_exe()?;
        let content = format!(
            "[Desktop Entry]\n\
             Type=Application\n\
             Name=MusicBot\n\
             Comment=Music Bot - DJ con IA\n\
             Exec={}\n\
             Terminal=false\n\
             StartupNotify=false\n\
             X-GNOME-Autostart-enabled=true\n",
            exe.display()
        );
        std::fs::write(&desktop_path, content)?;
    } else if std::path::Path::new(&desktop_path).exists() {
        std::fs::remove_file(&desktop_path)?;
    }

    Ok(())
}

#[tauri::command]
fn open_url(url: String) -> CmdResult<()> {
    Command::new("xdg-open")
        .arg(&url)
        .spawn()
        .map_err(|e| AppError::Generic(format!("No se pudo abrir URL: {e}")))?;
    Ok(())
}

#[tauri::command]
fn log_message(message: String) -> CmdResult<()> {
    let mut path = std::env::current_exe().unwrap_or_default();
    path.pop();
    path.push("musicbot.log");

    let ts = timestamp_now();

    let mut file = std::fs::OpenOptions::new()
        .create(true)
        .append(true)
        .open(&path)?;
    writeln!(file, "[{ts}] {message}")?;
    println!("[{ts}] {message}");
    Ok(())
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

fn main() {
    let initial_config = read_config_from_disk();

    tauri::Builder::default()
        .plugin(tauri_plugin_shell::init())
        .manage(MpvProcess(Mutex::new(None)))
        .manage(AppConfig(Mutex::new(initial_config)))
        .invoke_handler(tauri::generate_handler![
            // Configuracion
            load_config,
            save_config,
            // API PHP
            api_get,
            api_post,
            // Groq
            groq_chat,
            groq_list_models,
            // Tests de conexión
            test_api_connection,
            test_groq_connection,
            test_youtube,
            test_mpv,
            // YouTube / Player
            youtube_search,
            youtube_play,
            player_stop,
            player_is_playing,
            player_set_volume,
            player_set_video,
            // Sistema
            set_autostart,
            open_url,
            log_message,
        ])
        .run(tauri::generate_context!())
        .expect("Error al ejecutar la aplicacion Tauri");
}
