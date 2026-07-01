import { createContext, useContext, useEffect, useState, ReactNode } from 'react';
import { api, SessionInfo } from './api/client';

const AuthCtx = createContext<SessionInfo | null>(null);

/** Logged-in agent info (null if not authenticated). */
export const useAuth = () => useContext(AuthCtx);

/**
 * Gates the app behind the Pipeline (px_login) session. Calls /api/session.php;
 * if not logged in, shows a login screen that sends the user to login.php.
 */
export function AuthGate({ children }: { children: ReactNode }) {
  const [loading, setLoading] = useState(true);
  const [session, setSession] = useState<SessionInfo | null>(null);

  useEffect(() => {
    api.getSession()
      .then((s) => setSession(s))
      .catch(() => setSession({ logged_in: false, login_url: 'login.php' }))
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return <div className="min-h-screen flex items-center justify-center text-muted">Checking session…</div>;
  }

  if (!session || !session.logged_in) {
    const base = import.meta.env.BASE_URL || '/';
    const loginUrl = base + (session?.login_url || 'login.php');
    return (
      <div className="min-h-screen flex items-center justify-center px-4">
        <div className="bg-surface border border-line rounded-xl shadow-sm p-8 text-center max-w-sm w-full">
          <h1 className="text-xl font-semibold text-ink mb-2">🤖 Project Assistant Tools</h1>
          <p className="text-muted text-sm mb-5">Please sign in with your Pipeline account to continue.</p>
          <a href={loginUrl} className="inline-block rounded-lg bg-brand text-white px-5 py-2.5 text-sm">
            Log in
          </a>
        </div>
      </div>
    );
  }

  return <AuthCtx.Provider value={session}>{children}</AuthCtx.Provider>;
}
