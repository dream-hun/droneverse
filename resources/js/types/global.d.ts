import type { Auth } from '@/types/auth';
import type { TagManager } from '@/types/consent';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            tagManager: TagManager;
            [key: string]: unknown;
        };
    }
}
