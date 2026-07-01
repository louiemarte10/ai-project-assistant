import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, Route, Routes } from 'react-router-dom';
import DashboardPage from './pages/DashboardPage';
import ProjectsPage from './pages/ProjectsPage';
import ProjectWorkspace from './pages/ProjectWorkspace';
import HelpPage from './pages/HelpPage';
import { AuthGate, useAuth } from './auth';

const NAV = [
  { to: '/', label: 'Dashboard', icon: '📊', end: true },
  { to: '/projects', label: 'Projects', icon: '🗂️', end: false },
  { to: '/help', label: 'Help / FAQ', icon: 'ℹ️', end: false },
];

function Sidebar() {
  return (
    <nav className="flex flex-row md:flex-col gap-1 md:w-52 shrink-0">
      {NAV.map((item) => (
        <NavLink
          key={item.to}
          to={item.to}
          end={item.end}
          className={({ isActive }) =>
            `flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-colors ${
              isActive ? 'bg-brand text-white' : 'text-muted hover:text-ink hover:bg-surface2'
            }`
          }
        >
          <span>{item.icon}</span>
          <span>{item.label}</span>
        </NavLink>
      ))}
    </nav>
  );
}

const THEMES = [
  { key: 'light', label: 'Light', icon: '☀️' },
  { key: 'dark', label: 'Dark', icon: '🌙' },
  { key: 'blue', label: 'Blue', icon: '💎' },
];

function ThemeDropdown({ theme, setTheme }: { theme: string; setTheme: (t: string) => void }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const current = THEMES.find((t) => t.key === theme) || THEMES[0];

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  return (
    <div className="theme-dropdown" ref={ref}>
      <button className="theme-toggle-btn" onClick={() => setOpen((o) => !o)}>
        <span className="theme-btn-icon">{current.icon}</span>
        <span>{current.label}</span>
        <span className="theme-caret">{open ? '▲' : '▼'}</span>
      </button>
      {open && (
        <div className="theme-menu">
          {THEMES.map((t) => (
            <button
              key={t.key}
              className={`theme-menu-item ${theme === t.key ? 'active' : ''}`}
              onClick={() => { setTheme(t.key); setOpen(false); }}
            >
              <span className="theme-btn-icon">{t.icon}</span>
              <span>{t.label}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

export default function App() {
  const [theme, setTheme] = useState(() => localStorage.getItem('ptools-theme') || 'dark');

  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('ptools-theme', theme);
  }, [theme]);

  return (
    <AuthGate>
      <div className="min-h-screen flex flex-col">
        <header className="bg-headerbg text-white">
          <div className="w-full max-w-[1600px] mx-auto px-8 py-3 flex items-center gap-3">
            <Link to="/" className="font-semibold text-lg">
              🤖 Project Assistant Tools
            </Link>
            <div className="ml-auto flex items-center gap-3">
              <AgentMenu />
              <ThemeDropdown theme={theme} setTheme={setTheme} />
            </div>
          </div>
        </header>

        <div className="flex-1 w-full max-w-[1600px] mx-auto px-8 py-6 flex flex-col md:flex-row gap-6">
          <Sidebar />
          <main className="flex-1 min-w-0">
            <Routes>
              <Route path="/" element={<DashboardPage />} />
              <Route path="/projects" element={<ProjectsPage />} />
              <Route path="/projects/:id" element={<ProjectWorkspace />} />
              <Route path="/help" element={<HelpPage />} />
            </Routes>
          </main>
        </div>
      </div>
    </AuthGate>
  );
}

function AgentMenu() {
  const auth = useAuth();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  if (!auth || !auth.logged_in) return null;
  const logoutUrl = (import.meta.env.BASE_URL || '/') + 'logout.php';

  return (
    <div className="theme-dropdown" ref={ref}>
      <button className="theme-toggle-btn" onClick={() => setOpen((o) => !o)} title={`user_id ${auth.user_id}`}>
        <span className="theme-btn-icon">👤</span>
        <span className="hidden sm:inline">{auth.name}</span>
        <span className="theme-caret">{open ? '▲' : '▼'}</span>
      </button>
      {open && (
        <div className="theme-menu">
          <a className="theme-menu-item" href={logoutUrl}>
            <span className="theme-btn-icon">⎋</span>
            <span>Sign out</span>
          </a>
        </div>
      )}
    </div>
  );
}
