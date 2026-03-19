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
            weather_city: "Santiago".into(),
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
// Estado global: proceso mpv
// ---------------------------------------------------------------------------

struct MpvProcess(Mutex<Option<Child>>);

// ---------------------------------------------------------------------------
// Error serializable para Tauri commands
// ---------------------------------------------------------------------------

#[derive(Debug, thiserror::Error)]
enum AppError {
    #[error("{0}")]
    Generic(String),
}

// thiserror no esta en las dependencias, asi que implementamos a mano
// Tauri v2 necesita que el error implemente Serialize
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
    let mut path = std::env::current_exe().unwrap_or_default();
    path.pop();
    path.push("config.json");
    path
}

const MPV_SOCKET: &str = "/tmp/musicbot-mpv.sock";

fn mpv_send_command(args: &[&str]) -> Result<(), AppError> {
    use std::io::Write;
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

// ---------------------------------------------------------------------------
// Commands: Configuracion
// ---------------------------------------------------------------------------

#[tauri::command]
fn load_config() -> CmdResult<Config> {
    let path = config_path();
    if path.exists() {
        let data = std::fs::read_to_string(&path)?;
        let config: Config = serde_json::from_str(&data)?;
        Ok(config)
    } else {
        Ok(Config::default())
    }
}

#[tauri::command]
fn save_config(config: Config) -> CmdResult<()> {
    let path = config_path();
    let data = serde_json::to_string_pretty(&config)?;
    std::fs::write(&path, data)?;
    Ok(())
}

// ---------------------------------------------------------------------------
// Commands: API PHP Client
// ---------------------------------------------------------------------------

#[tauri::command]
async fn api_get(base_url: String, api_key: String, action: String) -> CmdResult<Value> {
    let client = reqwest::Client::new();
    let url = format!("{}/api.php", base_url.trim_end_matches('/'));
    let resp = client
        .get(&url)
        .query(&[("action", &action), ("api_key", &api_key)])
        .send()
        .await?;
    let body: Value = resp.json().await?;
    Ok(body)
}

#[tauri::command]
async fn api_post(
    base_url: String,
    api_key: String,
    action: String,
    data: Value,
) -> CmdResult<Value> {
    let client = reqwest::Client::new();
    let url = format!("{}/api.php", base_url.trim_end_matches('/'));

    let mut payload = match data {
        Value::Object(map) => map,
        _ => serde_json::Map::new(),
    };
    payload.insert("action".into(), Value::String(action));
    payload.insert("api_key".into(), Value::String(api_key));

    let resp = client
        .post(&url)
        .json(&Value::Object(payload))
        .send()
        .await?;
    let body: Value = resp.json().await?;
    Ok(body)
}

// ---------------------------------------------------------------------------
// Commands: Groq
// ---------------------------------------------------------------------------

#[tauri::command]
async fn groq_chat(
    api_key: String,
    model: String,
    messages: Vec<GroqMessage>,
    temperature: f64,
    max_tokens: u32,
    json_mode: bool,
) -> CmdResult<String> {
    let client = reqwest::Client::new();

    let mut body = serde_json::json!({
        "model": model,
        "messages": messages,
        "temperature": temperature,
        "max_tokens": max_tokens,
    });

    if json_mode {
        body["response_format"] = serde_json::json!({"type": "json_object"});
    }

    let resp = client
        .post("https://api.groq.com/openai/v1/chat/completions")
        .header("Authorization", format!("Bearer {api_key}"))
        .header("Content-Type", "application/json")
        .json(&body)
        .send()
        .await?;

    let data: Value = resp.json().await?;

    let content = data["choices"][0]["message"]["content"]
        .as_str()
        .unwrap_or("")
        .to_string();
    Ok(content)
}

#[tauri::command]
async fn groq_list_models(api_key: String) -> CmdResult<Vec<String>> {
    let client = reqwest::Client::new();
    let resp = client
        .get("https://api.groq.com/openai/v1/models")
        .header("Authorization", format!("Bearer {api_key}"))
        .send()
        .await?;

    let data: Value = resp.json().await?;

    let models = data["data"]
        .as_array()
        .map(|arr| {
            arr.iter()
                .filter_map(|m| m["id"].as_str().map(String::from))
                .collect()
        })
        .unwrap_or_default();

    Ok(models)
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
            "ytsearch10",
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
            let titulo = entry["title"]
                .as_str()
                .unwrap_or("Sin titulo")
                .to_string();
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
    volume: u32,
    video: bool,
    mpv_state: State<MpvProcess>,
) -> CmdResult<bool> {
    // Matar proceso previo si existe
    if let Ok(mut guard) = mpv_state.0.lock() {
        if let Some(ref mut child) = *guard {
            let _ = child.kill();
            let _ = child.wait();
        }
        *guard = None;
    }

    // Eliminar socket previo
    let _ = std::fs::remove_file(MPV_SOCKET);

    let mut args = vec![
        url.clone(),
        format!("--volume={volume}"),
        format!("--input-ipc-server={MPV_SOCKET}"),
        "--no-terminal".to_string(),
    ];

    if !video {
        args.push("--no-video".to_string());
    }

    let child = Command::new("mpv")
        .args(&args)
        .spawn()?;

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
                    // Proceso termino
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

    let timestamp = chrono_free_timestamp();

    let mut file = std::fs::OpenOptions::new()
        .create(true)
        .append(true)
        .open(&path)?;
    writeln!(file, "[{timestamp}] {message}")?;

    // Tambien imprimir en stdout para debug
    println!("[{timestamp}] {message}");
    Ok(())
}

/// Genera un timestamp sin depender de chrono, usando el comando `date`.
fn chrono_free_timestamp() -> String {
    use std::time::{SystemTime, UNIX_EPOCH};
    let duration = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default();
    let secs = duration.as_secs();
    // Formato simple: epoch seconds (para no agregar dependencia chrono)
    // Se podria mejorar con la crate chrono si se desea
    format!("{secs}")
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

fn main() {
    tauri::Builder::default()
        .plugin(tauri_plugin_shell::init())
        .manage(MpvProcess(Mutex::new(None)))
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
