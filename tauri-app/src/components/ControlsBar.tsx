import { useCallback } from 'react';
import {
  setAutoMode,
  setAutoFill,
  playerSetVideo,
  setScheduleEnabled,
  setMoodEnabled,
  setAutostart,
} from '../api';
import type { AppState } from '../types';

interface ControlsBarProps {
  state: AppState;
  onStateChange: (patch: Partial<AppState>) => void;
  onStatusMessage: (msg: string) => void;
}

interface ToggleConfig {
  key: keyof AppState;
  label: string;
  description: string;
  handler: (enabled: boolean) => Promise<void>;
}

export default function ControlsBar({ state, onStateChange, onStatusMessage }: ControlsBarProps) {
  const toggles: ToggleConfig[] = [
    {
      key: 'autoMode',
      label: 'Auto DJ',
      description: 'La IA elige canciones automaticamente',
      handler: setAutoMode,
    },
    {
      key: 'autoFill',
      label: 'Auto-fill',
      description: 'Rellena la cola cuando esta vacia',
      handler: setAutoFill,
    },
    {
      key: 'videoEnabled',
      label: 'Video',
      description: 'Mostrar video de YouTube',
      handler: playerSetVideo,
    },
    {
      key: 'scheduleEnabled',
      label: 'Horario',
      description: 'Adaptar musica segun la hora',
      handler: setScheduleEnabled,
    },
    {
      key: 'moodEnabled',
      label: 'Animo',
      description: 'Adaptar musica segun el clima',
      handler: setMoodEnabled,
    },
    {
      key: 'autostartEnabled',
      label: 'Autostart',
      description: 'Iniciar con el sistema',
      handler: setAutostart,
    },
  ];

  const handleToggle = useCallback(
    async (toggle: ToggleConfig) => {
      const newVal = !state[toggle.key];
      try {
        await toggle.handler(newVal);
        onStateChange({ [toggle.key]: newVal });
        onStatusMessage(`${toggle.label}: ${newVal ? 'activado' : 'desactivado'}`);
      } catch (err) {
        onStatusMessage(`Error al cambiar ${toggle.label}: ${err}`);
      }
    },
    [state, onStateChange, onStatusMessage]
  );

  return (
    <div className="controls-bar">
      <h3 className="controls-bar__title">Controles</h3>
      <div className="controls-bar__grid">
        {toggles.map((toggle) => (
          <button
            key={toggle.key}
            className={`controls-bar__toggle ${
              state[toggle.key] ? 'controls-bar__toggle--on' : 'controls-bar__toggle--off'
            }`}
            onClick={() => handleToggle(toggle)}
            title={toggle.description}
          >
            <span className="controls-bar__toggle-dot" />
            <span className="controls-bar__toggle-label">{toggle.label}</span>
          </button>
        ))}
      </div>
    </div>
  );
}
