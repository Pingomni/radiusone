import { createInertiaApp } from '@inertiajs/react';
import { ConfirmProvider } from '@/components/confirm';

const appName = import.meta.env.VITE_APP_NAME || 'FreeRADIUS Web';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    strictMode: true,
    withApp(app) {
        return <ConfirmProvider>{app}</ConfirmProvider>;
    },
    progress: {
        color: '#2563eb',
        delay: 250,
    },
});
