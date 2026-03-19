import { useEffect, useRef } from 'react';

interface LogViewerProps {
  logs: string[];
  onClear: () => void;
}

export default function LogViewer({ logs, onClear }: LogViewerProps) {
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [logs]);

  return (
    <div className="log-viewer">
      <div className="log-viewer__header">
        <h2 className="log-viewer__title">Registro de actividad</h2>
        <button className="log-viewer__clear" onClick={onClear}>
          Limpiar
        </button>
      </div>

      <div className="log-viewer__content">
        {logs.length === 0 ? (
          <div className="log-viewer__empty">No hay mensajes en el registro</div>
        ) : (
          logs.map((line, i) => {
            let className = 'log-viewer__line';
            if (line.includes('[ERROR]') || line.includes('Error')) {
              className += ' log-viewer__line--error';
            } else if (line.includes('[WARN]') || line.includes('Warning')) {
              className += ' log-viewer__line--warn';
            } else if (line.includes('[OK]') || line.includes('exitoso') || line.includes('correctamente')) {
              className += ' log-viewer__line--success';
            }

            return (
              <div key={i} className={className}>
                {line}
              </div>
            );
          })
        )}
        <div ref={bottomRef} />
      </div>
    </div>
  );
}
