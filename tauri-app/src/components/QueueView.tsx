import { useCallback } from 'react';
import type { Song } from '../types';

interface QueueViewProps {
  queue: Song[];
  buffer: Song[];
  onStatusMessage: (msg: string) => void;
  onRefresh: () => void;
  onRemove: (index: number) => void;
  onMoveUp: (index: number) => void;
  onVote: (index: number, delta: number) => void;
}

function TrackRow({
  song,
  index,
  label,
  showActions,
  onRemove,
  onMoveUp,
  onVote,
  onStatusMessage,
}: {
  song: Song;
  index: number;
  label?: string;
  showActions: boolean;
  onRemove?: (i: number) => void;
  onMoveUp?: (i: number) => void;
  onVote?: (i: number, d: number) => void;
  onStatusMessage?: (m: string) => void;
}) {
  const source = song.solicitado_por || (song.razon === 'Exito dorado' ? 'Dorada' : 'IA');

  return (
    <div className={`queue-view__track ${song.priority === 'now' ? 'queue-view__track--priority' : ''}`}>
      <span className="queue-view__col queue-view__col--num">{index + 1}</span>

      <div className="queue-view__col queue-view__col--title">
        <div className="queue-view__track-info">
          {song.thumbnail ? (
            <img className="queue-view__thumb" src={song.thumbnail} alt="" loading="lazy" />
          ) : (
            <div className="queue-view__thumb queue-view__thumb--empty">♪</div>
          )}
          <div className="queue-view__track-text">
            <div className="queue-view__track-title">
              {song.titulo}
              {song.priority === 'now' && (
                <span className="queue-view__badge-now">PRIORIDAD</span>
              )}
              {label && <span className="queue-view__badge-source">{label}</span>}
            </div>
            <div className="queue-view__track-artist">{song.artista}</div>
            <div className="queue-view__track-requested">{source}</div>
          </div>
        </div>
      </div>

      <span className="queue-view__col queue-view__col--votes">
        {showActions && (
          <span
            className={`queue-view__votes ${
              song.votos_net > 0 ? 'queue-view__votes--positive' : song.votos_net < 0 ? 'queue-view__votes--negative' : ''
            }`}
          >
            {song.votos_net > 0 ? '+' : ''}{song.votos_net}
          </span>
        )}
      </span>

      <div className="queue-view__col queue-view__col--actions">
        {showActions && onVote && onMoveUp && onRemove && onStatusMessage && (
          <>
            <button className="queue-view__action-btn queue-view__action-btn--up" onClick={() => onVote(index, 1)} title="Votar a favor">▲</button>
            <button className="queue-view__action-btn queue-view__action-btn--down" onClick={() => onVote(index, -1)} title="Votar en contra">▼</button>
            <button className="queue-view__action-btn queue-view__action-btn--move" onClick={() => { onMoveUp(index); onStatusMessage('Cancion movida'); }} disabled={index === 0} title="Mover arriba">⬆</button>
            <button className="queue-view__action-btn queue-view__action-btn--remove" onClick={() => { onRemove(index); onStatusMessage(`Cancion #${index + 1} eliminada`); }} title="Quitar">✕</button>
          </>
        )}
      </div>
    </div>
  );
}

export default function QueueView({
  queue,
  buffer,
  onStatusMessage,
  onRemove,
  onMoveUp,
  onVote,
}: QueueViewProps) {
  const handleRemove = useCallback((i: number) => onRemove(i), [onRemove]);
  const handleMoveUp = useCallback((i: number) => { if (i > 0) onMoveUp(i); }, [onMoveUp]);
  const handleVote = useCallback((i: number, d: number) => onVote(i, d), [onVote]);

  const totalCount = queue.length + buffer.length;

  if (totalCount === 0) {
    return (
      <div className="queue-view">
        <h2 className="queue-view__title">Cola de reproduccion</h2>
        <div className="queue-view__empty">
          <div className="queue-view__empty-icon">♫</div>
          <p>La cola esta vacia</p>
          <p className="queue-view__empty-hint">Activa el modo automatico o agrega canciones manualmente</p>
        </div>
      </div>
    );
  }

  return (
    <div className="queue-view">
      <div className="queue-view__header">
        <h2 className="queue-view__title">Cola de reproduccion</h2>
        <span className="queue-view__count">
          {queue.length} en cola{buffer.length > 0 ? ` · ${buffer.length} en buffer` : ''}
        </span>
      </div>

      {queue.length > 0 && (
        <div className="queue-view__list">
          <div className="queue-view__list-header">
            <span className="queue-view__col queue-view__col--num">#</span>
            <span className="queue-view__col queue-view__col--title">Siguiente</span>
            <span className="queue-view__col queue-view__col--votes">Votos</span>
            <span className="queue-view__col queue-view__col--actions">Acciones</span>
          </div>
          {queue.map((song, i) => (
            <TrackRow
              key={`q-${song.titulo}-${i}`}
              song={song}
              index={i}
              showActions={true}
              onRemove={handleRemove}
              onMoveUp={handleMoveUp}
              onVote={handleVote}
              onStatusMessage={onStatusMessage}
            />
          ))}
        </div>
      )}

      {buffer.length > 0 && (
        <div className="queue-view__list queue-view__list--buffer">
          <div className="queue-view__list-header">
            <span className="queue-view__col queue-view__col--num">#</span>
            <span className="queue-view__col queue-view__col--title">Buffer (proximas)</span>
            <span className="queue-view__col queue-view__col--votes"></span>
            <span className="queue-view__col queue-view__col--actions"></span>
          </div>
          {buffer.map((song, i) => (
            <TrackRow
              key={`b-${song.titulo}-${i}`}
              song={song}
              index={i}
              label={song.razon === 'Exito dorado' ? 'Dorada' : 'IA'}
              showActions={false}
            />
          ))}
        </div>
      )}
    </div>
  );
}
