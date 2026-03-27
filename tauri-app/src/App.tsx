import { useState, useCallback, useMemo, useEffect } from 'react';
import './App.css';
import type { AppState } from './types';
import { DJ_PRESETS } from './types';
import { usePolling } from './hooks/usePolling';
import { useDJ } from './hooks/useDJ';
import {
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
  const [selectedPreset, setSelectedPreset] = useState(DJ_PRESETS[0].name);

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

  // ── Handlers ─────────────────────────────────────────────────
  const handleStatusMessage = useCallback((msg: string) => {
    setStatusMessage(msg);
    setLocalLogs((prev) => [
      ...prev,
      `[${new Date().toLocaleTimeString()}] ${msg}`,
    ]);
  }, []);

  // ── DJ Hook: el cerebro de la app ────────────────────────────
  const dj = useDJ({
    autoMode: appState.autoMode,
    autoFill: appState.autoFill,
    preset: selectedPreset,
    volume,
    videoEnabled: appState.videoEnabled,
    onStatusMessage: handleStatusMessage,
  });

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

    // Sincronizar preset si cambio en la web
    if (serverVolume.preset && serverVolume.preset !== selectedPreset) {
      const presetExists = DJ_PRESETS.some((p) => p.name === serverVolume.preset);
      if (presetExists) {
        setSelectedPreset(serverVolume.preset);
        handleStatusMessage(`Preset cambiado desde web: ${serverVolume.preset}`);
      }
    }
  }, [serverVolume, selectedPreset, handleStatusMessage]);

  // ── Logs combinados ─────────────────────────────────────────
  const allLogs = useMemo(() => {
    return [...localLogs];
  }, [localLogs]);

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

  const handlePresetChange = useCallback((presetName: string) => {
    setSelectedPreset(presetName);
  }, []);

  // ── Refresh de cola (ahora usa dj.setQueue para forzar re-render)
  const refreshQueue = useCallback(() => {
    // El DJ maneja la cola, no necesitamos refetch
    dj.setQueue((prev) => [...prev]);
  }, [dj]);

  // ── Conectado (heurístico: si el DJ tiene algo o no hay error)
  const connected = true;

  // ── Render ───────────────────────────────────────────────────
  const renderMainContent = () => {
    switch (activeView) {
      case 'queue':
        return (
          <>
            <PresetSelector
              onStatusMessage={handleStatusMessage}
              selectedPreset={selectedPreset}
              onPresetChange={handlePresetChange}
            />
            <ControlsBar
              state={appState}
              onStateChange={handleAppStateChange}
              onStatusMessage={handleStatusMessage}
            />
            <QueueView
              queue={dj.queue}
              buffer={dj.buffer}
              onStatusMessage={handleStatusMessage}
              onRefresh={refreshQueue}
              onRemove={dj.removeFromQueue}
              onMoveUp={dj.moveUp}
              onVote={dj.voteInQueue}
            />
          </>
        );
      case 'requests':
        return (
          <RequestsPanel
            historial={dj.historial}
            onAddToQueue={dj.addToQueue}
            onStatusMessage={handleStatusMessage}
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
        currentSong={dj.currentSong}
        activeView={activeView}
        onViewChange={handleViewChange}
        logsCount={allLogs.length}
      />

      <main className="app__main">
        <StatusBar
          message={statusMessage}
          connected={connected}
          searching={dj.searching}
          bufferCount={dj.buffer.length}
        />
        <div className="app__content">{renderMainContent()}</div>
      </main>

      <PlayerBar
        currentSong={dj.currentSong}
        isPlaying={dj.isPlaying}
        volume={volume}
        onVolumeChange={setVolume}
        onStatusMessage={handleStatusMessage}
        onSkip={dj.skip}
        onStop={dj.stop}
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
