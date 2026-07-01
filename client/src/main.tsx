import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import App from './App';
import './index.css';

const queryClient = new QueryClient({
  defaultOptions: { queries: { refetchOnWindowFocus: false, retry: 1 } },
});

// If the page is restored from the browser's back/forward cache (bfcache), the
// frozen app (incl. its logged-in view) is shown without re-running the session
// check. Force a fresh load so AuthGate re-validates — e.g. after logout + Back.
window.addEventListener('pageshow', (e) => {
  if (e.persisted) window.location.reload();
});

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={queryClient}>
      {/* basename keeps routing correct under a sub-path deployment */}
      <BrowserRouter basename={import.meta.env.BASE_URL}>
        <App />
      </BrowserRouter>
    </QueryClientProvider>
  </React.StrictMode>,
);
