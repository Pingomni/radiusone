import { useForm } from '@inertiajs/react';
import { Sheet } from '@/components/settings';
import { ErrorSummary, Field, FormActions, TextArea } from '@/components/ui';
import type { ContractSettings } from '@/settings/types';

export function ContractsTab({ c }: { c: ContractSettings }) {
    const form = useForm({ template: c.template, title: c.title });
    const errors = form.errors as Record<string, string | undefined>;
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/contracts', { preserveScroll: true }); }}>
            <Sheet title="Contract template" icon="book">
                <ErrorSummary errors={errors} />
                <div className="form-grid template-fields">
                    <Field label="Title on the PDF" name="title" maxLength={40} value={form.data.title} disabled={!c.licensed} error={errors.title} hint="For example KONTRATË SHËRBIMI." onChange={(e) => form.setData('title', e.target.value)} />
                    <TextArea
                        label="Template"
                        name="template"
                        rows={24}
                        maxLength={c.max}
                        value={form.data.template}
                        disabled={!c.licensed}
                        error={errors.template}
                        hint="Write it in your own words and language. A line starting with # is a heading. New contracts use this text; contracts already issued keep theirs. Have a lawyer check it before you use it."
                        onChange={(e) => form.setData('template', e.target.value)}
                    />
                    <p className="span2 hint">You can use: {c.placeholders.map((p) => `{${p}}`).join(' ')}</p>
                    <FormActions>
                        <button className="button primary" type="submit" disabled={form.processing || !c.licensed}>Save</button>
                        <a className="button" href="/settings/contracts/preview" target="_blank" rel="noreferrer">Preview saved template</a>
                        {form.data.template !== c.default && <button type="button" className="link" onClick={() => form.setData('template', c.default)}>Use default</button>}
                    </FormActions>
                </div>
            </Sheet>
        </form>
    );
}
