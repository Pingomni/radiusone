import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { useConfirm } from '@/components/confirm';

type Answer = { ok: true; output: string; prompt: string; context: string[]; asked: string | null; careful: boolean } | { ok: false; error: string };
type Entry = { prompt: string; command: string; output?: string; error?: string; asked?: string | null; note?: string };

const QUICK = ['display board 0', 'display ont autofind all', 'display version', 'display vlan all', 'display ont info summary 0'];

function xsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function load(key: string): string[] {
    try {
        const saved = JSON.parse(window.localStorage.getItem(key) ?? '[]');
        return Array.isArray(saved) ? saved.filter((c): c is string => typeof c === 'string').slice(-100) : [];
    } catch {
        return [];
    }
}

/**
 * The OLT's terminal. The portal logs in for each command, so the mode (config, interface gpon 0/1, ...) is kept here and
 * entered again by the server before the command. "return", "quit" from a mode and "clear" are handled here at once.
 */
export default function OltTerminal({ path, name, start }: { path: string; name: string; start?: string }) {
    const ask = useConfirm();
    const top = `${name}#`;
    const at = start && /^\d{1,2}\/\d{1,2}$/.test(start) ? start : null;
    const [context, setContext] = useState<string[]>(at ? ['config', `interface gpon ${at}`] : []);
    const [prompts, setPrompts] = useState<string[]>(at ? [top, `${name}(config)#`, `${name}(config-if-gpon-${at})#`] : [top]);
    const [entries, setEntries] = useState<Entry[]>([]);
    const [command, setCommand] = useState('');
    const [busy, setBusy] = useState(false);
    const historyKey = `radiusone:olt-terminal:${path}`;
    const [history, setHistory] = useState<string[]>(() => load(historyKey));
    const [cursor, setCursor] = useState<number | null>(null);
    const screen = useRef<HTMLDivElement>(null);
    const input = useRef<HTMLInputElement>(null);
    const prompt = prompts[prompts.length - 1] ?? top;

    useEffect(() => { screen.current?.scrollTo({ top: screen.current.scrollHeight }); }, [entries, busy]);

    const remember = (line: string) => {
        const next = [...history.filter((c) => c !== line), line].slice(-100);
        setHistory(next);
        setCursor(null);
        try { window.localStorage.setItem(historyKey, JSON.stringify(next)); } catch { /* history is a convenience only */ }
    };

    const send = async (line: string, flags: { confirmed?: boolean; yes?: boolean } = {}) => {
        setBusy(true);
        const shown = prompt;
        try {
            const response = await fetch(`${path}/terminal`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ command: line, context, confirmed: flags.confirmed ?? false, yes: flags.yes ?? false }),
            });
            const answer: Answer = response.ok ? await response.json() : { ok: false, error: response.status === 403 ? 'Only operators who may delete can use the terminal.' : response.status === 419 ? 'Your session ended. Reload the page.' : `The portal answered ${response.status}.` };
            if (!answer.ok) {
                setEntries((e) => [...e, { prompt: shown, command: line, error: answer.error }]);
                return;
            }
            if (answer.careful) {
                const sure = await ask({ title: 'Send this command to the OLT?', message: `"${line}" saves, restarts, removes or cuts something off on the OLT. It runs exactly as typed.`, confirmLabel: 'Send it' });
                if (sure) {
                    await send(line, { confirmed: true });
                } else {
                    setEntries((e) => [...e, { prompt: shown, command: line, note: 'Not sent.' }]);
                }
                return;
            }
            setEntries((e) => [...e, { prompt: shown, command: line, output: answer.output, asked: answer.asked }]);
            // The OLT's own prompt says where the command left us.
            const depth = answer.context.length;
            setContext(answer.context);
            // The OLT's own name, from its prompt, replaces the portal's name for it in every level.
            const host = answer.prompt.match(/^([^(#>%\s]+)/)?.[1];
            setPrompts((p) => [...p.slice(0, depth), answer.prompt || p[Math.min(depth, p.length - 1)] || top].slice(-(depth + 1)).map((x) => (host ? x.replace(/^[^(#>%\s]+/, host) : x)));
        } catch {
            setEntries((e) => [...e, { prompt: shown, command: line, error: 'The portal could not be reached.' }]);
        } finally {
            setBusy(false);
            window.setTimeout(() => input.current?.focus(), 0);
        }
    };

    const run = (raw: string) => {
        const line = raw.trim().replace(/\s+/g, ' ');
        if (!line || busy) return;
        setCommand('');
        if (line === 'clear' || line === 'cls') {
            setEntries([]);
            return;
        }
        remember(line);
        // Leaving a mode needs no login: the next command simply is not sent inside it.
        if (context.length && (line === 'return' || line === 'quit' || line === 'exit')) {
            const depth = line === 'return' ? 0 : context.length - 1;
            setEntries((e) => [...e, { prompt, command: line }]);
            setContext(context.slice(0, depth));
            setPrompts(prompts.slice(0, depth + 1));
            return;
        }
        void send(line);
    };

    const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            if (!history.length) return;
            e.preventDefault();
            const next = e.key === 'ArrowUp' ? (cursor === null ? history.length - 1 : Math.max(0, cursor - 1)) : cursor === null ? null : cursor + 1 >= history.length ? null : cursor + 1;
            setCursor(next);
            setCommand(next === null ? '' : history[next]);
        } else if (e.key === 'l' && e.ctrlKey) {
            e.preventDefault();
            setEntries([]);
        }
    };

    return (
        <section className="olt-terminal" aria-label={`Terminal on ${name}`}>
            <div className="olt-terminal-bar">
                <span className="olt-terminal-dots" aria-hidden="true"><i /><i /><i /></span>
                <strong>{name}</strong>
                <span className="olt-terminal-mode">{context.length ? context[context.length - 1] : 'privileged mode'}</span>
                <span className="olt-terminal-quick">
                    {QUICK.map((q) => <button key={q} type="button" disabled={busy} onClick={() => { remember(q); void send(q); }}>{q}</button>)}
                </span>
            </div>
            <div className="olt-terminal-screen" ref={screen} onClick={() => { if (!window.getSelection()?.toString()) input.current?.focus(); }} role="log" aria-live="polite">
                {!entries.length && (
                    <p className="olt-terminal-welcome">
                        Every command runs on the OLT as typed and is written to the audit log. Commands that save, restart or remove ask first;
                        a yes/no question from the OLT is answered no until you say yes. ↑ and ↓ go through what you typed, <kbd>Ctrl</kbd>+<kbd>L</kbd> or
                        {' '}<kbd>clear</kbd> clears the screen, and <kbd>return</kbd> goes back to the top. A <kbd>?</kbd> cannot be sent.
                    </p>
                )}
                {entries.map((entry, i) => (
                    <div key={i} className="olt-terminal-entry">
                        <div className="olt-terminal-line"><span className="olt-terminal-prompt">{entry.prompt}</span> {entry.command}</div>
                        {entry.output && <pre>{entry.output}</pre>}
                        {entry.error && <pre className="olt-terminal-error">{entry.error}</pre>}
                        {entry.note && <pre className="olt-terminal-note">{entry.note}</pre>}
                        {entry.asked && i === entries.length - 1 && (
                            <div className="olt-terminal-ask">
                                <span>The OLT asked, and was answered no.</span>
                                <button type="button" disabled={busy} onClick={() => void send(entry.command, { confirmed: true, yes: true })}>Answer yes and run again</button>
                            </div>
                        )}
                    </div>
                ))}
                {busy && <div className="olt-terminal-line olt-terminal-busy" role="status">Asking the OLT…</div>}
                <form className="olt-terminal-input" onSubmit={(e) => { e.preventDefault(); run(command); }}>
                    <label htmlFor="olt-terminal-command" className="olt-terminal-prompt">{prompt}</label>
                    <input
                        id="olt-terminal-command"
                        ref={input}
                        value={command}
                        onChange={(e) => setCommand(e.target.value.replace(/\?/g, ''))}
                        onKeyDown={onKey}
                        disabled={busy}
                        autoComplete="off"
                        autoCapitalize="off"
                        spellCheck={false}
                        aria-label="Command"
                    />
                </form>
            </div>
        </section>
    );
}
