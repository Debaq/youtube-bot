import { useCallback } from 'react';
import type { Solicitud } from '../types';
import { approveSolicitud, rejectSolicitud } from '../api';

interface RequestsPanelProps {
  solicitudes: Solicitud[];
  onStatusMessage: (msg: string) => void;
  onRefresh: () => void;
}

export default function RequestsPanel({
  solicitudes,
  onStatusMessage,
  onRefresh,
}: RequestsPanelProps) {
  const handleApprove = useCallback(
    async (id: number) => {
      try {
        await approveSolicitud(id);
        onStatusMessage(`Solicitud #${id} aprobada`);
        onRefresh();
      } catch (err) {
        onStatusMessage(`Error al aprobar solicitud: ${err}`);
      }
    },
    [onStatusMessage, onRefresh]
  );

  const handleReject = useCallback(
    async (id: number) => {
      try {
        await rejectSolicitud(id);
        onStatusMessage(`Solicitud #${id} rechazada`);
        onRefresh();
      } catch (err) {
        onStatusMessage(`Error al rechazar solicitud: ${err}`);
      }
    },
    [onStatusMessage, onRefresh]
  );

  return (
    <div className="requests-panel">
      <div className="requests-panel__header">
        <h2 className="requests-panel__title">Solicitudes pendientes</h2>
        {solicitudes.length > 0 && (
          <span className="requests-panel__count">{solicitudes.length}</span>
        )}
      </div>

      {solicitudes.length === 0 ? (
        <div className="requests-panel__empty">
          <div className="requests-panel__empty-icon">✉</div>
          <p>No hay solicitudes pendientes</p>
        </div>
      ) : (
        <div className="requests-panel__list">
          {solicitudes.map((sol) => (
            <div key={sol.id} className="requests-panel__item">
              <div className="requests-panel__item-header">
                <span className="requests-panel__item-id">#{sol.id}</span>
                <span className="requests-panel__item-email">{sol.email}</span>
                {sol.priority !== 'normal' && (
                  <span className="requests-panel__item-priority">{sol.priority}</span>
                )}
              </div>
              <div className="requests-panel__item-text">{sol.texto}</div>
              <div className="requests-panel__item-actions">
                <button
                  className="requests-panel__btn requests-panel__btn--approve"
                  onClick={() => handleApprove(sol.id)}
                >
                  Aprobar
                </button>
                <button
                  className="requests-panel__btn requests-panel__btn--reject"
                  onClick={() => handleReject(sol.id)}
                >
                  Rechazar
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
