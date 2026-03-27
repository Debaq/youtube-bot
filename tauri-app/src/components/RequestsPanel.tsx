import { useCallback } from 'react';
import type { Song, SolicitudProcesada } from '../types';

interface RequestsPanelProps {
  historial: SolicitudProcesada[];
  onAddToQueue: (songs: Song[]) => void;
  onStatusMessage: (msg: string) => void;
}

export default function RequestsPanel({
  historial,
  onAddToQueue,
  onStatusMessage,
}: RequestsPanelProps) {
  const handleReplay = useCallback(
    (item: SolicitudProcesada) => {
      if (item.canciones.length > 0) {
        onAddToQueue(item.canciones);
        onStatusMessage(`Re-agregadas ${item.canciones.length} canciones de "${item.solicitud.texto}"`);
      }
    },
    [onAddToQueue, onStatusMessage]
  );

  // Mostrar más recientes primero
  const sorted = [...historial].reverse();

  return (
    <div className="requests-panel">
      <div className="requests-panel__header">
        <h2 className="requests-panel__title">Solicitudes procesadas</h2>
        {historial.length > 0 && (
          <span className="requests-panel__count">{historial.length}</span>
        )}
      </div>

      {sorted.length === 0 ? (
        <div className="requests-panel__empty">
          <div className="requests-panel__empty-icon">✉</div>
          <p>Las solicitudes se procesan automaticamente</p>
          <p style={{ fontSize: '0.8em', color: 'var(--text-subdued)', marginTop: '4px' }}>
            Aqui veras el historial con las canciones sugeridas
          </p>
        </div>
      ) : (
        <div className="requests-panel__list">
          {sorted.map((item, i) => (
            <div key={`${item.solicitud.id}-${i}`} className="requests-panel__item">
              <div className="requests-panel__item-header">
                <span className="requests-panel__item-email">{item.solicitud.email}</span>
                <span className={`requests-panel__item-badge requests-panel__item-badge--${item.tipo}`}>
                  {item.tipo === 'directa' ? 'Directo' : item.tipo === 'groq' ? 'IA' : 'Comando'}
                </span>
                <span className="requests-panel__item-time">
                  {new Date(item.timestamp).toLocaleTimeString()}
                </span>
              </div>
              <div className="requests-panel__item-text">{item.solicitud.texto}</div>
              {item.canciones.length > 0 && (
                <div className="requests-panel__item-songs">
                  {item.canciones.map((c, j) => (
                    <span key={j} className="requests-panel__song-tag">
                      {c.titulo} - {c.artista}
                    </span>
                  ))}
                  <button
                    className="requests-panel__btn-replay"
                    onClick={() => handleReplay(item)}
                    title="Volver a agregar estas canciones a la cola"
                  >
                    ↻ Re-agregar
                  </button>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
