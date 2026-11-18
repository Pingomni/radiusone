import { useCallback, useEffect, useRef, useState } from 'react';
import type { Terminal } from '@xterm/xterm';
import type { FitAddon } from '@xterm/addon-fit';
import Icon from '@/components/Icon';
import OltTerminal from '@/components/OltTerminal';

type Io = { ok: true; state: 'login' | 'ready' | 'closed' | 'failed'; error: string; offset: number; data: string; more: boolean }
    | { ok: false; error: string; unavailable?: boolean; ended?: boolean };
type Status = 'starting' | 'login' | 'ready' | 'closed' | 'failed' | 'unavailable';

const QUICK = ['display board 0', 'display ont autofind all', 'display ont info summary 0', 'display version', 'display vlan all'];
const THEME = { background: '#0d1117', foreground: '#d6dde6', cursor: '#4ade80', cursorAccent: '#0d1117', selectionBackground: '#2f6f4a99', black: '#0d1117', green: '#4ade80', brightGreen: '#86efac', red: '#ff8a80', yellow: '#f6c56b', blue: '#79b8ff', cyan: '#56d4dd', white: '#d6dde6', brightBlack: '#8b96a5' };

function xsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}
async function post<T>(url: string, body: Record<string, unknown> = {}, keepalive = false): Promise<T> {
    const response = await fetch(url, {
        method: 'POST', credentials: 'same-origin', keepalive,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body),
    });
    if (response.status === 419) return { ok: false, error: 'Your portal session ended. Reload the page.' } as T;
    if (!response.ok) return { ok: false, error: `The portal answered ${response.status}.` } as T;
    return (await response.json()) as T;
}
const toBase64 = (text: string) => {
    const bytes = new TextEncoder().encode(text);
    let binary = '';
    bytes.forEach((b) => { binary += String.fromCharCode(b); });
    return btoa(binary);
};
const fromBase64 = (data: string) => Uint8Array.from(atob(data), (c) => c.charCodeAt(0));

/**
 * The OLT's live console, as with PuTTY: one telnet session held by the server's console service, the keys going to
 * the OLT as they are typed and its answers coming back as they arrive, in a real terminal (xterm.js). So "?", Tab,
 * the arrow keys, Ctrl+C and the OLT's "More" pages work as on the OLT itself. Each line is kept in the audit log.
 */
export default function OltConsole({ path, name, start }: { path: string; name: string; start?: string }) {
    const box = useRef<HTMLDivElement>(null);
    const frame = useRef<HTMLElement>(null);
    const term = useRef<Terminal | null>(null);
    const fit = useRef<FitAddon | null>(null);
    const session = useRef<string | null>(null);
    const offset = useRef(0);
    const queue = useRef('');
    const busy = useRef(false);
    const ready = useRef(false);
    const lastActive = useRef(Date.now());
    const timer = useRef<number | null>(null);
    const [status, setStatus] = useState<Status>('starting');
    const [message, setMessage] = useState('');
    const [fontSize, setFontSize] = useState(13);
    const [round, setRound] = useState(0);

    const schedule = useCallback((ms: number) => {
        if (timer.current !== null) window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => { void tickRef.current(); }, ms);
    }, []);

    // One exchange: what was typed goes in, what the OLT said comes out. Fast while working, slower when idle.
    const tick = useCallback(async () => {
        const id = session.current;
        if (!id || busy.current) return;
        busy.current = true;
        // Keys wait until the OLT has given its first prompt: the service drops what comes during the login.
        const input = ready.current ? queue.current : '';
        if (ready.current) queue.current = '';
        const t = term.current;
        try {
            const answer = await post<Io>(`${path}/console/${id}`, { input: input ? toBase64(input) : '', offset: offset.current, cols: t?.cols, rows: t?.rows });
            if (!answer.ok) {
                setStatus(answer.ended ? 'closed' : 'failed');
                setMessage(answer.error);
                session.current = null;
                return;
            }
            if (answer.data) {
                t?.write(fromBase64(answer.data));
                lastActive.current = Date.now();
            }
            offset.current = answer.offset;
            ready.current = answer.state === 'ready';
            setStatus(answer.state);
            if (answer.state === 'closed' || answer.state === 'failed') {
                setMessage(answer.error);
                if (!answer.more) {
                    session.current = null;
                    return;
                }
            }
            if (input) lastActive.current = Date.now();
            const idle = Date.now() - lastActive.current;
            schedule(answer.more || (queue.current && ready.current) ? 0 : answer.state === 'login' ? 150 : document.hidden ? 3000 : idle < 3000 ? 90 : idle < 20000 ? 400 : 1200);
        } catch {
            setMessage('The portal could not be reached. Trying again…');
            schedule(2000);
        } finally {
            busy.current = false;
        }
    }, [path, schedule]);
    const tickRef = useRef(tick);
    tickRef.current = tick;

    useEffect(() => {
        let alive = true;
        let resize: ResizeObserver | null = null;
        void (async () => {
            const [{ Terminal: Term }, { FitAddon: Fit }] = await Promise.all([import('@xterm/xterm'), import('@xterm/addon-fit'), import('@xterm/xterm/css/xterm.css')]);
            if (!alive || !box.current) return;
            const t = new Term({ cursorBlink: true, fontFamily: 'ui-monospace, "Cascadia Mono", "SF Mono", Menlo, Consolas, "Liberation Mono", monospace', fontSize, scrollback: 5000, theme: THEME, allowProposedApi: false });
            const f = new Fit();
            t.loadAddon(f);
            t.open(box.current);
            f.fit();
            term.current = t;
            fit.current = f;
            t.onData((d) => {
                if (!session.current) return;
                queue.current += d;
                lastActive.current = Date.now();
                if (!busy.current) schedule(12);
            });
            // Copy with Ctrl+Shift+C, as in a Linux terminal; Ctrl+C itself goes to the OLT.
            t.attachCustomKeyEventHandler((e) => {
                if (e.type === 'keydown' && e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 'c') {
                    void navigator.clipboard?.writeText(t.getSelection());
                    return false;
                }
                return true;
            });
            resize = new ResizeObserver(() => { try { f.fit(); } catch { /* hidden */ } });
            resize.observe(box.current);
            t.writeln(`\x1b[2mConnecting to ${name}…\x1b[0m`);
            const opened = await post<{ ok: true; session: string } | { ok: false; error: string; unavailable?: boolean }>(`${path}/console`, { cols: t.cols, rows: t.rows });
            if (!alive) {
                if (opened.ok) void post(`${path}/console/${opened.session}/close`, {}, true);
                return;
            }
            if (!opened.ok) {
                setStatus(opened.unavailable ? 'unavailable' : 'failed');
                setMessage(opened.error);
                t.writeln(`\x1b[31m${opened.error}\x1b[0m`);
                return;
            }
            session.current = opened.session;
            offset.current = 0;
            ready.current = false;
            setStatus('login');
            // Start where the ONU's page asked for: in its PON card.
            if (start && /^\d{1,2}\/\d{1,2}$/.test(start)) queue.current = `config\rinterface gpon ${start}\r`;
            t.focus();
            schedule(150);
        })();
        return () => {
            alive = false;
            resize?.disconnect();
            if (timer.current !== null) window.clearTimeout(timer.current);
            if (session.current) void post(`${path}/console/${session.current}/close`, {}, true);
            session.current = null;
            term.current?.dispose();
            term.current = null;
        };
        // A new session for each round (Reconnect); the font size is applied below without one.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [path, round]);

    useEffect(() => {
        if (!term.current) return;
        term.current.options.fontSize = fontSize;
        try { fit.current?.fit(); } catch { /* hidden */ }
    }, [fontSize]);

    const send = (text: string) => {
        if (!session.current || status !== 'ready') return;
        queue.current += text;
        schedule(0);
        term.current?.focus();
    };
    const close = () => {
        if (session.current) void post(`${path}/console/${session.current}/close`);
        session.current = null;
        setStatus('closed');
        setMessage('You closed the session.');
        term.current?.writeln('\r\n\x1b[2m-- Session closed. --\x1b[0m');
    };
    const full = () => {
        if (document.fullscreenElement) void document.exitFullscreen();
        else void frame.current?.requestFullscreen?.();
    };

    if (status === 'unavailable') {
        return (
            <>
                <p className="flash warning" role="status">The live console service is not running on this server ({message}), so this terminal sends one line at a time. An update installs the service; until then, ask your administrator to run <span className="mono">sudo systemctl start radiusone-olt-console</span>.</p>
                <OltTerminal path={path} name={name} start={start} />
            </>
        );
    }

    const words: Record<Status, string> = { starting: 'Starting', login: 'Logging in', ready: 'Connected', closed: 'Closed', failed: 'Failed', unavailable: 'Unavailable' };
    return (
        <section className="olt-terminal olt-console" ref={frame} aria-label={`Live console on ${name}`}>
            <div className="olt-terminal-bar">
                <span className={`olt-console-state ${status}`}><i aria-hidden="true" />{words[status]}</span>
                <strong>{name}</strong>
                <span className="olt-terminal-mode">telnet · live</span>
                <span className="olt-terminal-quick">
                    {QUICK.map((q) => <button key={q} type="button" disabled={status !== 'ready'} onClick={() => send(`${q}\r`)}>{q}</button>)}
                </span>
                <span className="olt-console-tools">
                    <button type="button" onClick={() => send(' ')} disabled={status !== 'ready'} title="Next page of a long answer (Space)">More</button>
                    <button type="button" onClick={() => send('\x03')} disabled={status !== 'ready'} title="Stop the running command (Ctrl+C)">Ctrl+C</button>
                    <button type="button" onClick={() => setFontSize((s) => Math.max(10, s - 1))} aria-label="Smaller text">A−</button>
                    <button type="button" onClick={() => setFontSize((s) => Math.min(20, s + 1))} aria-label="Larger text">A+</button>
                    <button type="button" onClick={full} aria-label="Full screen" title="Full screen"><Icon name="dashboard" /></button>
                    {status === 'ready' || status === 'login'
                        ? <button type="button" className="danger" onClick={close}>Disconnect</button>
                        : <button type="button" className="go" onClick={() => setRound((r) => r + 1)}>Reconnect</button>}
                </span>
            </div>
            <div className="olt-console-screen" ref={box} />
            <div className="olt-console-foot">
                {message
                    ? <span className={status === 'failed' ? 'bad' : undefined}>{message}</span>
                    : <span>Type as on the OLT: <kbd>?</kbd> for help, <kbd>Tab</kbd> to complete, <kbd>↑</kbd> for the last commands, <kbd>Space</kbd> for the next page. <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>C</kbd> copies. Every line goes to the audit log.</span>}
            </div>
        </section>
    );
}
