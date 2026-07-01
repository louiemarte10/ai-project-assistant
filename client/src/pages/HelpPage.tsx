import { ReactNode } from 'react';

function Card({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="bg-surface rounded-xl border border-line p-5">
      <h2 className="font-semibold text-ink mb-2">{title}</h2>
      <div className="text-sm text-muted space-y-2 leading-relaxed">{children}</div>
    </section>
  );
}

function Faq({ q, children }: { q: string; children: ReactNode }) {
  return (
    <details className="border-b border-line last:border-0 py-2 group">
      <summary className="cursor-pointer text-ink text-sm font-medium list-none flex items-center gap-2">
        <span className="text-muted group-open:rotate-90 transition-transform">▶</span>
        {q}
      </summary>
      <div className="text-sm text-muted mt-2 ml-5 space-y-2 leading-relaxed">{children}</div>
    </details>
  );
}

export default function HelpPage() {
  return (
    <div className="space-y-6 max-w-4xl">
      <div>
        <h1 className="text-2xl font-semibold text-ink">Help &amp; FAQ</h1>
        <p className="text-sm text-muted mt-1">
          What the Project Assistant Tool does and how to use it.
        </p>
      </div>

      <Card title="🤖 What is the Project Assistant Tool?">
        <p>
          An internal AI assistant that helps you understand and explore your projects. For each project, you can
          upload its documentation and source files, link its GitHub repositories, and get an AI-generated technical
          summary. You can then chat with an assistant that answers questions about the project — using only that
          project's documents and repositories — so you can learn how it works step by step.
        </p>
      </Card>

      <Card title="🚀 Getting started">
        <ol className="list-decimal ml-5 space-y-1">
          <li><b className="text-ink">Add your Gemini API key</b> — open any project's <b>Chat</b> tab (or the Dashboard's “Your API keys”) and paste your key. The chat won't work until a key is added.</li>
          <li><b className="text-ink">Create a project</b> — go to <b>Projects</b>, enter a name and optionally one or more GitHub repo URLs (its <code>.md</code>/<code>.env</code> files are auto-imported).</li>
          <li><b className="text-ink">Upload documents</b> — add <code>.md</code>, <code>.txt</code>, code, <code>.pdf</code>, <code>.docx</code>, <code>.xlsx</code>, <code>.csv</code>, or images that describe the project.</li>
          <li><b className="text-ink">Generate the summary</b> — on the <b>Summary</b> tab, click <b>Regenerate</b> for an AI overview (purpose, server/env, tech stack, architecture, how to run).</li>
          <li><b className="text-ink">Chat</b> — ask questions about the project on the <b>Chat</b> tab.</li>
        </ol>
      </Card>

      <Card title="✨ Key features">
        <ul className="list-disc ml-5 space-y-1">
          <li><b className="text-ink">Project-isolated chat</b> — answers use only this project's uploaded docs, linked repos, and (for shareday/EOD questions) the database.</li>
          <li><b className="text-ink">Reads your GitHub repos</b> — the chat can open and read the most relevant files from the repositories mapped to the project.</li>
          <li><b className="text-ink">Multiple conversations</b> per project, with attachments (images, PDFs, spreadsheets) and a stop button.</li>
          <li><b className="text-ink">Per-user API keys</b> — each person uses their own Gemini key; add several as automatic fallback, and pick the model (2.5 Flash / Flash-Lite).</li>
          <li><b className="text-ink">Usage dashboard</b> — see your token usage and estimated cost; switch charts by model/user/project; admins see everyone.</li>
        </ul>
      </Card>

      <Card title="❓ Frequently asked questions">
        <Faq q="Where do I get a Gemini API key?">
          Create one free at <a className="text-brand hover:underline" href="https://aistudio.google.com/apikey" target="_blank" rel="noreferrer">aistudio.google.com/apikey</a>,
          then paste it in the Chat key prompt or the Dashboard's “Your API keys”. It's validated before saving.
        </Faq>
        <Faq q="The chat says “your API key has reached its limit”. What now?">
          The Gemini free tier is small (about 20 requests/day for 2.5 Flash). The tool automatically falls back to
          Flash-Lite (a much larger free quota) and other models. You can also add a second key (from a different
          Google account/project) for more capacity, or enable billing on your key. Quotas reset daily.
        </Faq>
        <Faq q="What's the difference between 2.5 Flash and Flash-Lite?">
          <b>2.5 Flash</b> is a bit smarter but has a very small free daily quota. <b>Flash-Lite</b> is slightly
          lighter but has a much larger free quota — it's the recommended default. Switch via the model dropdown in
          the chat header or the 🔑 panel.
        </Faq>
        <Faq q="Why was my PDF skipped / how are PDFs handled?">
          PDFs up to 8 MB are read directly by the AI (including flowcharts and scanned pages). Click a PDF in
          “Project documents” to view it. Very large PDFs may need to be split.
        </Faq>
        <Faq q="Can the assistant answer general questions (not about the project)?">
          No — it stays on topic. Off-topic requests (e.g. “write me random code”) get a polite reminder to ask
          something related to the project.
        </Faq>
        <Faq q="How do I get a shareday / EOD report?">
          In a project's chat, ask e.g. “shareday report for the software development team this week” or
          “Louie Doromal's EOD for June 23”. It pulls live data from the database (by name + date), Software
          Development only.
        </Faq>
        <Faq q="Who can see the usage dashboard?">
          Everyone sees their own usage. The admin account sees all users' usage and a list of all API keys, and can
          filter the dashboard by user.
        </Faq>
        <Faq q="I changed something but the UI looks the same.">
          Hard-refresh the page with <b>Ctrl+Shift+R</b> to load the latest version.
        </Faq>
      </Card>

      <p className="text-xs text-muted">
        Need a change or found a bug? Contact the developer (softdev) with the project name and what you were doing.
      </p>
    </div>
  );
}
