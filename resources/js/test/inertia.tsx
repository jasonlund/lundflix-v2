import type { ReactNode } from 'react';
import { vi } from 'vitest';

// Inertia's helpers need a live Inertia app context; stub the ones a page reaches
// for so it renders as a plain unit. <Form> becomes a real <form> so a submit's
// action/method stay assertable.
export function inertiaStub() {
    return {
        Head: () => null,
        Link: ({ children }: { children?: ReactNode }) => <>{children}</>,
        router: { post: vi.fn(), get: vi.fn(), visit: vi.fn() },
        useForm: () => ({
            data: {},
            setData: vi.fn(),
            post: vi.fn(),
            processing: false,
            errors: {},
        }),
        Form: ({
            children,
            ...props
        }: {
            children?: ReactNode | ((helpers: unknown) => ReactNode);
        }) => (
            <form {...props}>
                {typeof children === 'function'
                    ? children({ processing: false, errors: {} })
                    : children}
            </form>
        ),
    };
}
