interface StatusBarProps {
  message: string;
  connected: boolean;
  searching?: boolean;
  bufferCount?: number;
}

export default function StatusBar({ message, connected, searching, bufferCount }: StatusBarProps) {
  return (
    <div className="status-bar">
      <div className={`status-bar__indicator ${connected ? 'status-bar__indicator--on' : 'status-bar__indicator--off'}`} />
      <span className="status-bar__text">
        {message || (connected ? 'Conectado al servidor' : 'Desconectado')}
      </span>
      <div className="status-bar__info">
        {searching && <span className="status-bar__badge status-bar__badge--searching">Buscando...</span>}
        {typeof bufferCount === 'number' && bufferCount > 0 && (
          <span className="status-bar__badge status-bar__badge--buffer">Buffer: {bufferCount}</span>
        )}
      </div>
    </div>
  );
}
