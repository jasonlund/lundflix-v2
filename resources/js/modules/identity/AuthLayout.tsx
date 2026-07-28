import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

interface AuthLayoutProps {
    /** Browser tab title; `heading` is often phrased differently for the page. */
    title: string;
    heading: string;
    children: ReactNode;
}

export default function AuthLayout({
    title,
    heading,
    children,
}: AuthLayoutProps) {
    return (
        <>
            <Head title={title} />

            <h1>{heading}</h1>

            {children}
        </>
    );
}
