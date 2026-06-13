import { Head } from '@inertiajs/react';

export default function Welcome() {
    return (
        <>
            <Head title="Welcome" />

            <div className="flex min-h-full flex-col items-center justify-center gap-3 bg-white p-8 dark:bg-zinc-900">
                <h1 className="text-4xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                    Lundflix
                </h1>
                <p className="text-zinc-600 dark:text-zinc-400">
                    Laravel + Inertia + React + Tailwind
                </p>
            </div>
        </>
    );
}
