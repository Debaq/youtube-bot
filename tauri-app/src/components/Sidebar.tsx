import { useState } from 'react';
import type { Song } from '../types';

interface SidebarProps {
  currentSong: Song | null;
  activeView: string;
  onViewChange: (view: string) => void;
  logsCount: number;
}

export default function Sidebar({ currentSong, activeView, onViewChange, logsCount }: SidebarProps) {
  const [collapsed] = useState(false);

  const navItems = [
    { id: 'queue', label: 'Cola de reproduccion', icon: '♫' },
    { id: 'requests', label: 'Solicitudes', icon: '✉' },
    { id: 'settings', label: 'Configuracion', icon: '⚙' },
    { id: 'logs', label: 'Registro', icon: '📋', badge: logsCount > 0 ? logsCount : undefined },
  ];

  return (
    <aside className={`sidebar ${collapsed ? 'sidebar--collapsed' : ''}`}>
      <div className="sidebar__logo">
        <div className="sidebar__logo-icon">♪</div>
        <span className="sidebar__logo-text">Music Bot DJ</span>
      </div>

      <nav className="sidebar__nav">
        {navItems.map((item) => (
          <button
            key={item.id}
            className={`sidebar__nav-item ${activeView === item.id ? 'sidebar__nav-item--active' : ''}`}
            onClick={() => onViewChange(item.id)}
          >
            <span className="sidebar__nav-icon">{item.icon}</span>
            <span className="sidebar__nav-label">{item.label}</span>
            {item.badge && <span className="sidebar__badge">{item.badge}</span>}
          </button>
        ))}
      </nav>

      <div className="sidebar__spacer" />

      {currentSong && (
        <div className="sidebar__now-playing">
          <div className="sidebar__np-label">Reproduciendo ahora</div>
          <div className="sidebar__np-card">
            {currentSong.thumbnail && (
              <img
                className="sidebar__np-thumb"
                src={currentSong.thumbnail}
                alt=""
                loading="lazy"
              />
            )}
            <div className="sidebar__np-info">
              <div className="sidebar__np-title">{currentSong.titulo}</div>
              <div className="sidebar__np-artist">{currentSong.artista}</div>
            </div>
          </div>
        </div>
      )}
    </aside>
  );
}
