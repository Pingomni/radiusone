import { createInertiaApp } from '@inertiajs/react';
import { ConfirmProvider } from '@/components/confirm';

void createInertiaApp({
    // The layouts add the company name (Settings → Branding), which comes with each page.
    title: (title) => title,
    strictMode: true,
    withApp(app) {
        return <ConfirmProvider>{app}</ConfirmProvider>;
    },
    // The bar's CSS lives in app.css: the Content Security Policy (style-src 'self') blocks the <style> Inertia would inject.
    progress: {
        delay: 250,
        includeCSS: false,
        showSpinner: false,
    },
});
