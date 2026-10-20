import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { PostButton } from '@/components/confirm';
import Icon from '@/components/Icon';
import { Sheet } from '@/components/settings';
import { Badge, Field } from '@/components/ui';
import { BrandLogo } from '@/layouts/AppLayout';
import type { Branding } from '@/settings/types';

function LogoPreview({ src, label }: { src: string; label: string }) {
    const [status, setStatus] = useState('');
    useEffect(() => setStatus(src ? 'Loading preview…' : ''), [src]);
    if (!src) return null;
    return (
        <div className="logo-draft">
            <img src={src} alt={label} referrerPolicy="no-referrer" onLoad={() => setStatus('Preview ready.')} onError={() => setStatus('This image could not be loaded.')} />
            <span role="status">{status}</span>
        </div>
    );
}

export function BrandingTab({ branding }: { branding: Branding }) {
    const upload = useForm<{ portal_logo: File | null }>({ portal_logo: null });
    const link = useForm({ portal_logo_url: branding.url });
    const company = useForm({ company_name: branding.name });
    const shown = company.data.company_name.trim() || branding.product;
    const [filePreview, setFilePreview] = useState('');
    const [urlPreview, setUrlPreview] = useState('');
    useEffect(() => () => { if (filePreview) URL.revokeObjectURL(filePreview); }, [filePreview]);

    return (
        <Sheet
            title="Workspace branding"
            description="Your company name and logo, as your team and customers see them."
            icon="image"
            className="branding-card"
            meta={<Badge tone={branding.custom ? 'success' : ''}>{branding.custom ? (branding.remote ? 'HTTPS logo' : 'Uploaded logo') : 'Default logo'}</Badge>}
        >
            <div className="branding-layout">
                <div className="branding-preview-panel">
                    <span className="workspace-eyebrow">YOUR WORKSPACE</span>
                    <div className="brand-preview-stage"><BrandLogo className="preview-logo" /><strong>{shown}</strong><span>{shown === branding.product ? 'Client management' : branding.product}</span></div>
                    {branding.hasSetting && (
                        <PostButton action="/settings/branding/logo/delete" label="Reset to default logo" confirm={{ message: 'Remove custom logo and use the default mark?', confirmLabel: 'Reset logo' }} />
                    )}
                </div>
                <div className="branding-methods">
                    <section className="branding-method branding-name">
                        <div className="branding-method-title"><span className="method-number"><Icon name="clients" /></span><h3>Company name</h3></div>
                        <form className="branding-form" onSubmit={(event) => { event.preventDefault(); company.post('/settings/branding/name', { preserveScroll: true }); }}>
                            <Field label="Company name" name="company_name" maxLength={60} placeholder={branding.product} hint={`Leave empty to use ${branding.product}.`} value={company.data.company_name} error={company.errors.company_name} onChange={(e) => company.setData('company_name', e.target.value)} />
                            <div className="form-actions"><button className="primary" disabled={company.processing || !company.isDirty}>Save name</button></div>
                        </form>
                    </section>
                    <section className="branding-method">
                        <div className="branding-method-title"><span className="method-number">01</span><h3>Upload your logo</h3></div>
                        <form
                            className="branding-form"
                            onSubmit={(event) => {
                                event.preventDefault();
                                upload.post('/settings/branding/logo', { forceFormData: true, preserveScroll: true, onSuccess: () => { upload.reset(); setFilePreview(''); } });
                            }}
                        >
                            <label className="logo-dropzone" htmlFor="portal-logo-file">
                                <Icon name="plus" />
                                <strong>Choose a logo file</strong>
                                <span>PNG, JPG or WebP · up to 2 MB</span>
                                <input
                                    id="portal-logo-file"
                                    name="portal_logo"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    required
                                    aria-describedby="logo-file-help"
                                    onChange={(event) => {
                                        const file = event.target.files?.[0] ?? null;
                                        upload.setData('portal_logo', file);
                                        setFilePreview(file ? URL.createObjectURL(file) : '');
                                    }}
                                />
                            </label>
                            <small id="logo-file-help">A square or horizontal logo with a clear background works best.</small>
                            <LogoPreview src={filePreview} label="Selected logo preview" />
                            <div className="form-actions"><button className="primary" disabled={upload.processing}>Upload logo</button></div>
                        </form>
                    </section>
                    <section className="branding-method">
                        <div className="branding-method-title"><span className="method-number">02</span><h3>Use an image URL</h3></div>
                        <form className="branding-form" onSubmit={(event) => { event.preventDefault(); link.post('/settings/branding/logo/url', { preserveScroll: true }); }}>
                            <label htmlFor="portal-logo-url">
                                HTTPS image URL
                                <input
                                    id="portal-logo-url"
                                    type="url"
                                    name="portal_logo_url"
                                    placeholder="https://example.com/logo.png"
                                    maxLength={2048}
                                    pattern="[Hh][Tt][Tt][Pp][Ss]://.+"
                                    required
                                    aria-describedby="logo-url-help"
                                    value={link.data.portal_logo_url}
                                    onChange={(e) => link.setData('portal_logo_url', e.target.value)}
                                />
                            </label>
                            <small id="logo-url-help">Use a public, direct image link. The image stays hosted at this address.</small>
                            <LogoPreview src={urlPreview} label="URL logo preview" />
                            <div className="form-actions">
                                <button type="button" onClick={() => setUrlPreview(/^https:\/\/.+/i.test(link.data.portal_logo_url) ? link.data.portal_logo_url : '')}>Preview logo</button>
                                <button className="primary" disabled={link.processing}>Save logo URL</button>
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </Sheet>
    );
}
