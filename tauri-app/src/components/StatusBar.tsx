interface StatusBarProps {
  message: string;
  connected: boolean;
}

export default function StatusBar({ message, connected }: StatusBarProps) {
  return (
    <div className="status-bar">
      <div className={`status-bar__indicator ${connected ? 'status-bar__indicator--on' : 'status-bar__indicator--off'}`} />
      <span className="status-bar__text">
        {message || (connected ? 'Conectado al servidor' : 'Desconectado')}
      </span>
    </div>
  );
}
