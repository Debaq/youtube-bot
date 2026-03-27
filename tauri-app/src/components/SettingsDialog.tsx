import { useState, useEffect, useCallback } from 'react';
import type { Config } from '../types';
import { loadConfig, saveConfig, groqListModels, testApiConnection, testGroqConnection, testYoutube, testMpv } from '../api';

interface SettingsDialogProps {
  open: boolean;
  onClose: () => void;
  onStatusMessage: (msg: string) => void;
}

export default function SettingsDialog({ open, onClose, onStatusMessage }: SettingsDialogProps) {
  const [config, setConfig] = useState<Config>({
    server_url: '',
    api_key: '',
    groq_api_key: '',
    groq_model: '',
    weather_city: '',
    autostart: false,
    minimize_to_tray: false,
  });
  const [groqModels, setGroqModels] = useState<string[]>([]);
  const [loading, setLoading] = useState(false);
  const [loadingModels, setLoadingModels] = useState(false);
  const [testResults, setTestResults] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setLoading(true);
    loadConfig()
      .then((cfg) => {
        setConfig(cfg);
        setLoading(false);
      })
      .catch((err) => {
        onStatusMessage(`Error al cargar configuracion: ${err}`);
        setLoading(false);
      });
  }, [open, onStatusMessage]);

  const handleLoadModels = useCallback(async () => {
    setLoadingModels(true);
    try {
      const models = await groqListModels();
      setGroqModels(models);
    } catch (err) {
      onStatusMessage(`Error al cargar modelos Groq: ${err}`);
    }
    setLoadingModels(false);
  }, [onStatusMessage]);

  const handleSave = useCallback(async () => {
    try {
      await saveConfig(config);
      onStatusMessage('Configuracion guardada correctamente');
      onClose();
    } catch (err) {
      onStatusMessage(`Error al guardar: ${err}`);
    }
  }, [config, onClose, onStatusMessage]);

  const updateField = useCallback(
    <K extends keyof Config>(field: K, value: Config[K]) => {
      setConfig((prev) => ({ ...prev, [field]: value }));
    },
    []
  );

  if (!open) return null;

  return (
    <div className="settings-overlay" onClick={onClose}>
      <div className="settings-dialog" onClick={(e) => e.stopPropagation()}>
        <div className="settings-dialog__header">
          <h2 className="settings-dialog__title">Configuracion</h2>
          <button className="settings-dialog__close" onClick={onClose}>
            ✕
          </button>
        </div>

        {loading ? (
          <div className="settings-dialog__loading">Cargando configuracion...</div>
        ) : (
          <div className="settings-dialog__body">
            <div className="settings-dialog__section">
              <h3 className="settings-dialog__section-title">Servidor</h3>

              <label className="settings-dialog__field">
                <span className="settings-dialog__label">URL del servidor</span>
                <input
                  type="text"
                  className="settings-dialog__input"
                  value={config.server_url}
                  onChange={(e) => updateField('server_url', e.target.value)}
                  placeholder="http://localhost:5000"
                />
              </label>

              <label className="settings-dialog__field">
                <span className="settings-dialog__label">API Key</span>
                <input
                  type="password"
                  className="settings-dialog__input"
                  value={config.api_key}
                  onChange={(e) => updateField('api_key', e.target.value)}
                  placeholder="Tu clave de API"
                />
              </label>
            </div>

            <div className="settings-dialog__section">
              <h3 className="settings-dialog__section-title">Groq IA</h3>

              <label className="settings-dialog__field">
                <span className="settings-dialog__label">Groq API Key</span>
                <input
                  type="password"
                  className="settings-dialog__input"
                  value={config.groq_api_key}
                  onChange={(e) => updateField('groq_api_key', e.target.value)}
                  placeholder="gsk_..."
                />
              </label>

              <label className="settings-dialog__field">
                <span className="settings-dialog__label">Modelo Groq</span>
                <div className="settings-dialog__model-row">
                  {groqModels.length > 0 ? (
                    <select
                      className="settings-dialog__select"
                      value={config.groq_model}
                      onChange={(e) => updateField('groq_model', e.target.value)}
                    >
                      {groqModels.map((m) => (
                        <option key={m} value={m}>
                          {m}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <input
                      type="text"
                      className="settings-dialog__input"
                      value={config.groq_model}
                      onChange={(e) => updateField('groq_model', e.target.value)}
                      placeholder="llama-3.3-70b-versatile"
                    />
                  )}
                  <button
                    className="settings-dialog__btn-models"
                    onClick={handleLoadModels}
                    disabled={loadingModels}
                  >
                    {loadingModels ? 'Cargando...' : 'Cargar modelos'}
                  </button>
                </div>
              </label>
            </div>

            <div className="settings-dialog__section">
              <h3 className="settings-dialog__section-title">Otros</h3>

              <label className="settings-dialog__field">
                <span className="settings-dialog__label">Ciudad (clima)</span>
                <input
                  type="text"
                  className="settings-dialog__input"
                  value={config.weather_city}
                  onChange={(e) => updateField('weather_city', e.target.value)}
                  placeholder="Madrid"
                />
              </label>

              <label className="settings-dialog__field settings-dialog__field--checkbox">
                <input
                  type="checkbox"
                  checked={config.autostart}
                  onChange={(e) => updateField('autostart', e.target.checked)}
                />
                <span className="settings-dialog__label">Iniciar con el sistema</span>
              </label>

              <label className="settings-dialog__field settings-dialog__field--checkbox">
                <input
                  type="checkbox"
                  checked={config.minimize_to_tray}
                  onChange={(e) => updateField('minimize_to_tray', e.target.checked)}
                />
                <span className="settings-dialog__label">Minimizar a bandeja</span>
              </label>
            </div>
          </div>
        )}

        <div className="settings-dialog__section">
          <h3 className="settings-dialog__section-title">Pruebas de conexion</h3>
          <div className="settings-dialog__tests">
            {[
              { label: 'API Servidor', key: 'api', fn: testApiConnection },
              { label: 'Groq IA', key: 'groq', fn: testGroqConnection },
              { label: 'yt-dlp', key: 'ytdlp', fn: testYoutube },
              { label: 'mpv', key: 'mpv', fn: testMpv },
            ].map((t) => {
              const result = testResults[t.key] || '';
              const status = !result ? '' : result === 'Probando...' ? 'loading' : result.startsWith('Error') ? 'error' : 'ok';
              return (
                <div key={t.key} className={`settings-dialog__test-card ${status}`}>
                  <div className="settings-dialog__test-header">
                    <span className="settings-dialog__test-name">{t.label}</span>
                    <span className={`settings-dialog__test-dot ${status}`} />
                  </div>
                  <button
                    className="settings-dialog__btn-test"
                    disabled={status === 'loading'}
                    onClick={async () => {
                      setTestResults((prev) => ({ ...prev, [t.key]: 'Probando...' }));
                      try {
                        const res = await t.fn();
                        setTestResults((prev) => ({ ...prev, [t.key]: res }));
                      } catch (err) {
                        setTestResults((prev) => ({ ...prev, [t.key]: `Error: ${err}` }));
                      }
                    }}
                  >
                    {status === 'loading' ? 'Probando...' : 'Probar'}
                  </button>
                  {result && status !== 'loading' && (
                    <span className={`settings-dialog__test-result ${status}`}>
                      {result}
                    </span>
                  )}
                </div>
              );
            })}
          </div>
        </div>

        <div className="settings-dialog__footer">
          <button className="settings-dialog__btn settings-dialog__btn--cancel" onClick={onClose}>
            Cancelar
          </button>
          <button className="settings-dialog__btn settings-dialog__btn--save" onClick={handleSave}>
            Guardar
          </button>
        </div>
      </div>
    </div>
  );
}
