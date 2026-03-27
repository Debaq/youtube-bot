import { useState, useEffect, useCallback } from 'react';
import type { Song } from '../types';
import { playerSetVolume } from '../api';

interface PlayerBarProps {
  currentSong: Song | null;
  isPlaying: boolean;
  volume: number;
  onVolumeChange: (vol: number) => void;
  onStatusMessage: (msg: string) => void;
  onSkip: () => void;
  onStop: () => void;
}

export default function PlayerBar({
  currentSong,
  isPlaying,
  volume,
  onVolumeChange,
  onStatusMessage,
  onSkip,
  onStop,
}: PlayerBarProps) {
  const [localVolume, setLocalVolume] = useState(volume);
  const [muted, setMuted] = useState(false);
  const [prevVolume, setPrevVolume] = useState(volume);

  useEffect(() => {
    setLocalVolume(volume);
  }, [volume]);

  const handleSkip = useCallback(() => {
    onSkip();
    onStatusMessage('Cancion saltada');
  }, [onSkip, onStatusMessage]);

  const handleStop = useCallback(() => {
    onStop();
    onStatusMessage('Reproduccion detenida');
  }, [onStop, onStatusMessage]);

  const handleVolumeChange = useCallback(
    async (val: number) => {
      setLocalVolume(val);
      try {
        await playerSetVolume(val);
        onVolumeChange(val);
      } catch (err) {
        onStatusMessage(`Error al cambiar volumen: ${err}`);
      }
    },
    [onVolumeChange, onStatusMessage]
  );

  const toggleMute = useCallback(() => {
    if (muted) {
      setMuted(false);
      handleVolumeChange(prevVolume);
    } else {
      setPrevVolume(localVolume);
      setMuted(true);
      handleVolumeChange(0);
    }
  }, [muted, localVolume, prevVolume, handleVolumeChange]);

  return (
    <div className="player-bar">
      {/* Izquierda: Info de la cancion */}
      <div className="player-bar__song">
        {currentSong?.thumbnail ? (
          <img
            className="player-bar__thumb"
            src={currentSong.thumbnail}
            alt=""
            loading="lazy"
          />
        ) : (
          <div className="player-bar__thumb player-bar__thumb--empty">♪</div>
        )}
        <div className="player-bar__song-info">
          <div className="player-bar__title">
            {currentSong?.titulo || 'Sin reproduccion'}
          </div>
          <div className="player-bar__artist">
            {currentSong?.artista || '---'}
          </div>
        </div>
      </div>

      {/* Centro: Controles */}
      <div className="player-bar__controls">
        <button
          className="player-bar__btn player-bar__btn--skip"
          onClick={handleSkip}
          disabled={!isPlaying}
          title="Saltar cancion"
        >
          ⏭
        </button>
        <button
          className="player-bar__btn player-bar__btn--stop"
          onClick={handleStop}
          disabled={!isPlaying}
          title="Detener"
        >
          ⏹
        </button>
      </div>

      {/* Derecha: Volumen */}
      <div className="player-bar__volume">
        <button
          className="player-bar__btn player-bar__btn--mute"
          onClick={toggleMute}
          title={muted ? 'Activar sonido' : 'Silenciar'}
        >
          {muted || localVolume === 0 ? '🔇' : localVolume < 50 ? '🔉' : '🔊'}
        </button>
        <input
          type="range"
          className="player-bar__volume-slider"
          min={0}
          max={100}
          value={localVolume}
          onChange={(e) => handleVolumeChange(Number(e.target.value))}
        />
        <span className="player-bar__volume-value">{localVolume}</span>
      </div>
    </div>
  );
}
