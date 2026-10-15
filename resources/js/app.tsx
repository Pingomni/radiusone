import { createInertiaApp } from '@inertiajs/react';
import { ConfirmProvider } from '@/components/confirm';

const appName = import.meta.env.VITE_APP_NAME || 'FreeRADIUS Web';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
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
