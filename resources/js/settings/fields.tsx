import { Field, TextArea } from '@/components/ui';

/** Rows for a template box: its current lines plus one to type in, within limits. */
const templateRows = (value: string, max: number) => Math.max(2, Math.min(max, value.split('\n').length + 1));

/** One template field, pre-filled with the text in use; "Use default" puts the default back. */
export function TemplateField({ label, name, value, fallback, multiline, maxRows = 8, hint, error, onChange }: {
    label: string; name: string; value: string; fallback: string; multiline: boolean; maxRows?: number; hint?: string; error?: string; onChange: (value: string) => void;
}) {
    const reset = value !== fallback && fallback !== '' && (
        <button type="button" className="link" onClick={() => onChange(fallback)}>Use default</button>
    );
    return (
        <div className="span2 template-field">
            {multiline
                ? <TextArea className="span2" label={label} name={name} rows={templateRows(value, maxRows)} hint={hint} value={value} error={error} onChange={(e) => onChange(e.target.value)} />
                : <Field className="span2" label={label} name={name} hint={hint} value={value} error={error} onChange={(e) => onChange(e.target.value)} />}
            {reset}
        </div>
    );
}

/** Credential input: blank keeps the saved value, which is never sent to the browser. */
export function SecretField({ label, name, saved, value, onChange, error, hint }: { label: string; name: string; saved: boolean; value: string; onChange: (v: string) => void; error?: string; hint?: string }) {
    return (
        <Field
            label={label}
            name={name}
            type="password"
            autoComplete="new-password"
            value={value}
            error={error}
            onChange={(e) => onChange(e.target.value)}
            placeholder={saved ? 'Saved — leave blank to keep' : ''}
            hint={[hint, saved ? 'A value is saved. Type a new one only to replace it.' : ''].filter(Boolean).join(' ') || undefined}
        />
    );
}
