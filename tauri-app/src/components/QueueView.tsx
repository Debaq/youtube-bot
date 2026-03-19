import { useCallback } from 'react';
import type { Song } from '../types';

interface QueueViewProps {
  queue: Song[];
  onStatusMessage: (msg: string) => void;
  onRefresh: () => void;
  onRemove: (index: number) => void;
  onMoveUp: (index: number) => void;
  onVote: (index: number, delta: number) => void;
}

export default function QueueView({
  queue,
  onStatusMessage,
  onRemove,
  onMoveUp,
  onVote,
}: QueueViewProps) {
  const handleRemove = useCallback(
    (index: number) => {
      onRemove(index);
      onStatusMessage(`Cancion #${index + 1} eliminada de la cola`);
    },
    [onRemove, onStatusMessage]
  );

  const handleMoveUp = useCallback(
    (index: number) => {
      if (index === 0) return;
      onMoveUp(index);
      onStatusMessage('Cancion movida hacia arriba');
    },
    [onMoveUp, onStatusMessage]
  );

  const handleVote = useCallback(
    (index: number, delta: number) => {
      onVote(index, delta);
    },
    [onVote]
  );

  if (queue.length === 0) {
    return (
      <div className="queue-view">
        <h2 className="queue-view__title">Cola de reproduccion</h2>
        <div className="queue-view__empty">
          <div className="queue-view__empty-icon">♫</div>
          <p>La cola esta vacia</p>
          <p className="queue-view__empty-hint">
            Activa el modo automatico o agrega canciones manualmente
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="queue-view">
      <div className="queue-view__header">
        <h2 className="queue-view__title">Cola de reproduccion</h2>
        <span className="queue-view__count">{queue.length} canciones</span>
      </div>

      <div className="queue-view__list">
        <div className="queue-view__list-header">
          <span className="queue-view__col queue-view__col--num">#</span>
          <span className="queue-view__col queue-view__col--title">Titulo</span>
          <span className="queue-view__col queue-view__col--votes">Votos</span>
          <span className="queue-view__col queue-view__col--actions">Acciones</span>
        </div>

        {queue.map((song, index) => (
          <div
            key={`${song.titulo}-${index}`}
            className={`queue-view__track ${song.priority === 'now' ? 'queue-view__track--priority' : ''}`}
          >
            <span className="queue-view__col queue-view__col--num">
              {index + 1}
            </span>

            <div className="queue-view__col queue-view__col--title">
              <div className="queue-view__track-info">
                {song.thumbnail ? (
                  <img
                    className="queue-view__thumb"
                    src={song.thumbnail}
                    alt=""
                    loading="lazy"
                  />
                ) : (
                  <div className="queue-view__thumb queue-view__thumb--empty">♪</div>
                )}
                <div className="queue-view__track-text">
                  <div className="queue-view__track-title">
                    {song.titulo}
                    {song.priority === 'now' && (
                      <span className="queue-view__badge-now">PRIORIDAD</span>
                    )}
                  </div>
                  <div className="queue-view__track-artist">{song.artista}</div>
                  {song.solicitado_por && (
                    <div className="queue-view__track-requested">
                      Pedido por: {song.solicitado_por}
                    </div>
                  )}
                </div>
              </div>
            </div>

            <span className="queue-view__col queue-view__col--votes">
              <span
                className={`queue-view__votes ${
                  song.votos_net > 0
                    ? 'queue-view__votes--positive'
                    : song.votos_net < 0
                    ? 'queue-view__votes--negative'
                    : ''
                }`}
              >
                {song.votos_net > 0 ? '+' : ''}
                {song.votos_net}
              </span>
            </span>

            <div className="queue-view__col queue-view__col--actions">
              <button
                className="queue-view__action-btn queue-view__action-btn--up"
                onClick={() => handleVote(index, 1)}
                title="Votar a favor"
              >
                ▲
              </button>
              <button
                className="queue-view__action-btn queue-view__action-btn--down"
                onClick={() => handleVote(index, -1)}
                title="Votar en contra"
              >
                ▼
              </button>
              <button
                className="queue-view__action-btn queue-view__action-btn--move"
                onClick={() => handleMoveUp(index)}
                disabled={index === 0}
                title="Mover arriba"
              >
                ⬆
              </button>
              <button
                className="queue-view__action-btn queue-view__action-btn--remove"
                onClick={() => handleRemove(index)}
                title="Quitar de la cola"
              >
                ✕
              </button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
