import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { DeleteButton, PostButton } from '@/components/confirm';
import { Sheet, SettingsRows } from '@/components/settings';
import { DataTable } from '@/components/table';
import { Badge, Check, Empty, Field, Select } from '@/components/ui';
import { bind } from '@/lib/form';
import { bytes } from '@/lib/format';
import { SecretField } from '@/settings/fields';
import type { BackupDestination, Backups } from '@/settings/types';

function BackupStatus({ b }: { b: Backups }) {
    const run = b.status;
    // While a backup runs, check every 5 seconds and show its result when it ends.
    useEffect(() => {
        if (!b.running) return;
        const timer = window.setInterval(() => router.reload({ only: ['backups'] }), 5000);
        return () => window.clearInterval(timer);
    }, [b.running]);
    return (
        <Sheet
            title="Last backup"
            description="Every night at 02:15 (server time) the database and customer documents are backed up, encrypted and uploaded to each enabled destination."
            icon="server"
            meta={b.running ? <Badge tone="warning">running…</Badge> : run ? <Badge tone={run.ok ? 'success' : 'danger'}>{run.ok ? 'completed' : 'needs attention'}</Badge> : <Badge>not run yet</Badge>}
        >
            <div className="form-actions">
                <PostButton
                    action="/settings/backups/run"
                    label={b.running ? 'Backing up…' : 'Back up now'}
                    className="primary"
                    disabled={b.running || !b.manualReady}
                    confirm={{ title: 'Back up now?', message: 'Backs up the database and customer documents now, encrypts them and uploads them to each enabled destination, like the nightly backup. It takes about a minute.', confirmLabel: 'Back up now' }}
                />
                {!b.manualReady && <span className="hint">To start backups from here, run <code>sudo bash ops/radiusone-postinstall</code> on the server once.</span>}
            </div>
            {run ? (
                <>
                    <SettingsRows rows={[
                        ['Finished', run.at],
                        ['Database', run.database ? `${run.database.file} · ${bytes(run.database.size)}` : '—'],
                        ['Documents', run.documents ? `${run.documents.file} · ${bytes(run.documents.size)}` : '—'],
                        ['Local copies', `${b.localCount} files${run.local_removed ? ` · ${run.local_removed} old copies removed` : ''}`],
                    ]} />
                    {run.error && <p className="field-error">{run.error}</p>}
                    {Object.entries(run.destinations).map(([id, d]) => (
                        <p key={id} className={d.ok ? 'hint' : 'field-error'}>{d.name}: {d.message}{d.removed ? ` ${d.removed} old copies removed.` : ''}</p>
                    ))}
                    {Object.keys(run.destinations).length === 0 && !run.error && <p className="hint">No remote destination was enabled, so this backup exists only on this server.</p>}
                </>
            ) : (
                <p className="hint">No backup has run yet. Use <strong>Back up now</strong>, or wait for the nightly run.</p>
            )}
        </Sheet>
    );
}

function BackupPassphrase({ set }: { set: boolean }) {
    const form = useForm({ passphrase: '', passphrase_confirmation: '' });
    return (
        <Sheet
            title="Encryption"
            description="Backups are encrypted on this server before they are uploaded. Only someone with the passphrase can open them."
            icon="lock"
            meta={<Badge tone={set ? 'success' : 'warning'}>{set ? 'passphrase set' : 'no passphrase'}</Badge>}
        >
            <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post('/settings/backups/passphrase', { preserveScroll: true, onFinish: () => form.reset() }); }}>
                <p className="span2 hint">
                    Write the passphrase down and keep it somewhere safe outside this server. If this server is lost, you need it to restore a remote backup
                    (artisan radiusone:backup-decrypt). Changing it only affects new backups; older copies still need the old passphrase.
                </p>
                <Field label={set ? 'New passphrase' : 'Passphrase'} type="password" autoComplete="new-password" minLength={12} required error={form.errors.passphrase} hint="At least 12 characters; a few random words work well." value={form.data.passphrase} onChange={(e) => form.setData('passphrase', e.target.value)} />
                <Field label="Repeat passphrase" type="password" autoComplete="new-password" required value={form.data.passphrase_confirmation} onChange={(e) => form.setData('passphrase_confirmation', e.target.value)} />
                <div className="span2 form-actions"><button className="primary" disabled={form.processing}>{set ? 'Change passphrase' : 'Save passphrase'}</button></div>
            </form>
        </Sheet>
    );
}

/** Where each provider shows the bucket address, an example, and what it calls the two key parts (see Documentation → Backups). */
const S3_HELP: Record<string, { url: string; where: string; key: string; secret: string }> = {
    b2: { url: 'https://s3.eu-central-003.backblazeb2.com/my-bucket', where: 'Optional: leave empty and it is found from the key.', key: 'keyID', secret: 'applicationKey' },
    r2: { url: 'https://<account id>.r2.cloudflarestorage.com/my-bucket', where: 'Cloudflare → R2 → your bucket → Settings → S3 API.', key: 'Access Key ID', secret: 'Secret Access Key' },
};

function DestinationForm({ d, b, onDone }: { d: BackupDestination | null; b: Backups; onDone: () => void }) {
    const form = useForm({
        id: d?.id ?? '', type: d?.type ?? 's3', provider: d?.provider ?? 'b2',
        url: d?.type === 's3' && d.endpoint ? `https://${d.endpoint}/${d.bucket}` : '', access_key: d?.access_key ?? '', secret_key: '',
        server: d?.type === 'sftp' ? `${d.user}@${d.host}${d.port && d.port !== '22' ? `:${d.port}` : ''}` : '',
        name: d?.name ?? '', prefix: d?.prefix ?? 'radiusone', path: d?.path ?? 'backups/radiusone', keep: String(d?.keep ?? 7), enabled: d?.enabled ?? true, rescan_host_key: false,
    });
    const type = form.data.type;
    const help = S3_HELP[form.data.provider] ?? S3_HELP.b2;
    return (
        <form className="form-grid" onSubmit={(e) => { e.preventDefault(); form.post('/settings/backups/destinations', { preserveScroll: true, onSuccess: onDone, onFinish: () => form.setData((v) => ({ ...v, secret_key: '' })) }); }}>
            {/* One list for both: Backblaze B2 and Cloudflare R2, then SFTP. An existing destination keeps its kind. */}
            {!d && (
                <Select
                    label="Store backups in"
                    name="destination_kind"
                    className="span2"
                    options={{ ...b.s3Providers, sftp: 'My own server (SFTP)' }}
                    value={type === 'sftp' ? 'sftp' : form.data.provider}
                    onChange={(e) => form.setData((v) => (e.target.value === 'sftp' ? { ...v, type: 'sftp' } : { ...v, type: 's3', provider: e.target.value }))}
                />
            )}
            {type === 's3' && (
                <>
                    <Field label="Bucket address" className="span2" required={form.data.provider !== 'b2'} placeholder={help.url} hint={help.where} {...bind(form, 'url')} />
                    <Field label={help.key} required {...bind(form, 'access_key')} />
                    <SecretField label={help.secret} name="secret_key" saved={Boolean(d?.secret_key_saved)} value={form.data.secret_key} error={form.errors.secret_key} onChange={(v) => form.setData('secret_key', v)} />
                    <p className="span2 hint">Use a private bucket and a key limited to that bucket. <Link href={`/docs#${form.data.provider === 'r2' ? 'r2' : 'b2'}`}>Step-by-step guide</Link></p>
                </>
            )}
            {type === 'sftp' && (
                <>
                    <Field label="Server" className="span2" required placeholder="backup@nas.example.net" hint="user@host, or user@host:port if SSH is not on port 22." {...bind(form, 'server')} />
                    <p className="span2 hint">A key pair is created when you save: add its public key to that user’s ~/.ssh/authorized_keys. The server’s host key is recorded and checked on every upload.</p>
                </>
            )}
            <details className="span2">
                <summary>More options</summary>
                <div className="form-grid">
                    <Field label="Name" placeholder={type === 's3' ? `${b.s3Providers[form.data.provider] ?? 'Cloud storage'} · bucket` : 'user@host'} hint="Optional." {...bind(form, 'name')} />
                    {type === 's3'
                        ? <Field label="Folder in the bucket" hint="Optional." {...bind(form, 'prefix')} />
                        : <Field label="Folder on the server" hint="Relative to the user’s home, or an absolute path." {...bind(form, 'path')} />}
                    <Select label="Copies to keep" options={b.keepOptions} {...bind(form, 'keep')} />
                    <Check label="Enabled" checked={form.data.enabled} onChange={(e) => form.setData('enabled', e.target.checked)} />
                    {d && type === 'sftp' && <Check label="Re-check the server’s host key (only after you changed it on purpose)" checked={form.data.rescan_host_key} onChange={(e) => form.setData('rescan_host_key', e.target.checked)} />}
                </div>
            </details>
            <div className="span2 form-actions">
                <button className="primary" disabled={form.processing}>{d ? 'Save destination' : 'Add destination'}</button>
                <button type="button" onClick={onDone}>Cancel</button>
            </div>
        </form>
    );
}

function DestinationRow({ d, b, open, onToggle }: { d: BackupDestination; b: Backups; open: boolean; onToggle: () => void }) {
    const detail = d.type === 's3' ? `${b.s3Providers[d.provider ?? ''] ?? 'S3'} · ${d.bucket}${d.prefix ? '/' + d.prefix : ''}` : `${d.user}@${d.host}:${d.port} · ${d.path}`;
    return (
        <article className="backup-destination">
            <div className="backup-destination-head">
                <div>
                    <h3>{d.name}</h3>
                    <p className="hint">{d.type === 's3' ? '' : b.types[d.type] + ' · '}{detail} · keeps {d.keep} {d.keep === 1 ? 'copy' : 'copies'}</p>
                </div>
                <span className="toolbar-actions">
                    <Badge tone={d.enabled && d.connected ? 'success' : 'warning'}>{!d.connected ? 'not connected' : d.enabled ? 'enabled' : 'disabled'}</Badge>
                    {d.connected && <PostButton action={`/settings/backups/destinations/${d.id}/test`} label="Test" className="button" />}
                    <button type="button" onClick={onToggle}>{open ? 'Close' : 'Edit'}</button>
                    <DeleteButton action={`/settings/backups/destinations/${d.id}/delete`} label="Remove" message={`Stop sending backups to ${d.name}? Backups already stored there are kept.`} />
                </span>
            </div>
            {d.type === 'sftp' && d.public_key && (
                <div className="backup-sftp-key">
                    <strong>Public key for the server’s ~/.ssh/authorized_keys</strong>
                    <div className="backup-key-line">
                        <code>{d.public_key}</code>
                        <button type="button" onClick={() => void navigator.clipboard?.writeText(d.public_key ?? '')}>Copy</button>
                    </div>
                    <p className="hint">Server host key: {d.fingerprint}. Compare it with the server (ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub).</p>
                </div>
            )}
            {open && <DestinationForm d={d} b={b} onDone={onToggle} />}
        </article>
    );
}

function BackupDestinationsSheet({ b }: { b: Backups }) {
    const initial = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('destination') : null;
    const [open, setOpen] = useState<string | null>(initial);
    return (
        <Sheet title="Remote destinations" description="Where encrypted copies are sent. Use more than one for extra safety." icon="server" meta={<Badge>{b.destinations.length} configured</Badge>}>
            {!b.passphraseSet && b.destinations.length > 0 && <p className="field-error">Set a passphrase first: nothing is uploaded unencrypted.</p>}
            {b.destinations.map((d) => <DestinationRow key={d.id} d={d} b={b} open={open === d.id} onToggle={() => setOpen(open === d.id ? null : d.id)} />)}
            {b.destinations.length === 0 && open !== 'new' && <Empty>No remote destination yet. Backups exist only on this server.</Empty>}
            {open === 'new' ? <DestinationForm d={null} b={b} onDone={() => setOpen(null)} /> : <div className="form-actions"><button className="primary" type="button" onClick={() => setOpen('new')}>Add destination</button></div>}
        </Sheet>
    );
}

function BackupLocal({ b }: { b: Backups }) {
    const form = useForm({ days: String(b.localDays) });
    return (
        <Sheet title="Local copies" description="Backups also stay on this server; older ones are removed (the newest three of each kind are always kept)." icon="logs">
            <form className="inline-form backup-local-form" onSubmit={(e) => { e.preventDefault(); form.post('/settings/backups/local', { preserveScroll: true }); }}>
                <Select label="Keep local copies for" options={b.localDaysOptions} {...bind(form, 'days')} />
                <button disabled={form.processing}>Save</button>
            </form>
            <DataTable rows={b.local} rowKey={(f) => f.name} columns={[
                { header: 'File', cell: (f) => f.name },
                { header: 'Size', cell: (f) => bytes(f.size) },
                { header: 'Created', cell: (f) => f.at },
            ]} />
        </Sheet>
    );
}

export function BackupsTab({ b }: { b: Backups }) {
    return (
        <>
            <BackupStatus b={b} />
            <BackupPassphrase set={b.passphraseSet} />
            <BackupDestinationsSheet b={b} />
            <BackupLocal b={b} />
        </>
    );
}
