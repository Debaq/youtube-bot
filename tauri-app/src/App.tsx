import { useState, useCallback, useMemo, useEffect } from 'react';
import './App.css';
import type { Song, Solicitud, AppState } from './types';
import { usePolling } from './hooks/usePolling';
import {
  getQueue,
  getCurrentSong,
  playerIsPlaying,
  getSolicitudes,
  getLogs,
  getServerVolume,
  playerSetVolume,
  playerSetVideo,
} from './api';
import type { ServerVolume } from './api';

import Sidebar from './components/Sidebar';
import PlayerBar from './components/PlayerBar';
import QueueView from './components/QueueView';
import ControlsBar from './components/ControlsBar';
import PresetSelector from './components/PresetSelector';
import RequestsPanel from './components/RequestsPanel';
import SettingsDialog from './components/SettingsDialog';
import StatusBar from './components/StatusBar';
import LogViewer from './components/LogViewer';

function App() {
  // ── Vista activa ─────────────────────────────────────────────
  const [activeView, setActiveView] = useState('queue');
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [statusMessage, setStatusMessage] = useState('');
  const [volume, setVolume] = useState(80);
  const [localLogs, setLocalLogs] = useState<string[]>([]);

  // ── Estado de controles ──────────────────────────────────────
  const [appState, setAppState] = useState<AppState>({
    autoMode: false,
    autoFill: false,
    videoEnabled: false,
    scheduleEnabled: false,
    moodEnabled: false,
    autostartEnabled: false,
    trayEnabled: false,
  });

  // ── Polling: cola ────────────────────────────────────────────
  const fetchQueue = useCallback(() => getQueue(), []);
  const { data: queue, refresh: refreshQueue } = usePolling<Song[]>(fetchQueue, 3000);

  // ── Polling: canción actual ──────────────────────────────────
  const fetchCurrentSong = useCallback(() => getCurrentSong(), []);
  const { data: currentSong } = usePolling<Song | null>(fetchCurrentSong, 2000);

  // ── Polling: estado de reproducción ──────────────────────────
  const fetchIsPlaying = useCallback(() => playerIsPlaying(), []);
  const { data: isPlaying } = usePolling<boolean>(fetchIsPlaying, 2000);

  // ── Polling: solicitudes ─────────────────────────────────────
  const fetchSolicitudes = useCallback(() => getSolicitudes(), []);
  const { data: solicitudes, refresh: refreshSolicitudes } = usePolling<Solicitud[]>(
    fetchSolicitudes,
    5000
  );

  // ── Polling: volumen y estado desde servidor ────────────────
  const fetchServerVolume = useCallback(() => getServerVolume(), []);
  const { data: serverVolume } = usePolling<ServerVolume | null>(fetchServerVolume, 15000);

  // Aplicar cambios del servidor al player local
  useEffect(() => {
    if (!serverVolume) return;
    const vol = serverVolume.muted ? 0 : serverVolume.volume;
    setVolume(vol);
    playerSetVolume(vol).catch(() => {});
    playerSetVideo(serverVolume.video).catch(() => {});
    setAppState((prev) => ({
      ...prev,
      autoMode: serverVolume.auto_mode ?? prev.autoMode,
      autoFill: serverVolume.auto_fill ?? prev.autoFill,
      videoEnabled: serverVolume.video ?? prev.videoEnabled,
    }));
  }, [serverVolume]);

  // ── Polling: logs ────────────────────────────────────────────
  const fetchLogs = useCallback(() => getLogs(), []);
  const { data: remoteLogs } = usePolling<string[]>(fetchLogs, 5000);

  // ── Logs combinados ─────────────────────────────────────────
  const allLogs = useMemo(() => {
    const remote = remoteLogs || [];
    return [...remote, ...localLogs];
  }, [remoteLogs, localLogs]);

  // ── Handlers ─────────────────────────────────────────────────
  const handleStatusMessage = useCallback((msg: string) => {
    setStatusMessage(msg);
    setLocalLogs((prev) => [
      ...prev,
      `[${new Date().toLocaleTimeString()}] ${msg}`,
    ]);
  }, []);

  const handleAppStateChange = useCallback((patch: Partial<AppState>) => {
    setAppState((prev) => ({ ...prev, ...patch }));
  }, []);

  const handleViewChange = useCallback(
    (view: string) => {
      if (view === 'settings') {
        setSettingsOpen(true);
      } else {
        setActiveView(view);
      }
    },
    []
  );

  const handleClearLogs = useCallback(() => {
    setLocalLogs([]);
  }, []);

  // ── Conectado (heurístico: si queue no es null, hay conexión)
  const connected = queue !== null;

  // ── Render ───────────────────────────────────────────────────
  const renderMainContent = () => {
    switch (activeView) {
      case 'queue':
        return (
          <>
            <PresetSelector onStatusMessage={handleStatusMessage} />
            <ControlsBar
              state={appState}
              onStateChange={handleAppStateChange}
              onStatusMessage={handleStatusMessage}
            />
            <QueueView
              queue={queue || []}
              onStatusMessage={handleStatusMessage}
              onRefresh={refreshQueue}
            />
          </>
        );
      case 'requests':
        return (
          <RequestsPanel
            solicitudes={solicitudes || []}
            onStatusMessage={handleStatusMessage}
            onRefresh={refreshSolicitudes}
          />
        );
      case 'logs':
        return <LogViewer logs={allLogs} onClear={handleClearLogs} />;
      default:
        return null;
    }
  };

  return (
    <div className="app">
      <Sidebar
        currentSong={currentSong ?? null}
        activeView={activeView}
        onViewChange={handleViewChange}
        logsCount={allLogs.length}
      />

      <main className="app__main">
        <StatusBar message={statusMessage} connected={connected} />
        <div className="app__content">{renderMainContent()}</div>
      </main>

      <PlayerBar
        currentSong={currentSong ?? null}
        isPlaying={isPlaying ?? false}
        volume={volume}
        onVolumeChange={setVolume}
        onStatusMessage={handleStatusMessage}
      />

      <SettingsDialog
        open={settingsOpen}
        onClose={() => setSettingsOpen(false)}
        onStatusMessage={handleStatusMessage}
      />
    </div>
  );
}

export default App;
