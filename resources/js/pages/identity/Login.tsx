import TextField from '@/common/TextField';
import AuthLayout from '@/modules/identity/AuthLayout';
import { Form } from '@inertiajs/react';

interface LoginProps {
    errors?: {
        /** Belongs to the hand-off, not to a field, so it reads page-level. */
        plex?: string;
    };
}

export default function Login({ errors }: LoginProps) {
    return (
        <AuthLayout title="Log in" heading="Log in">
            {errors?.plex && <p role="alert">{errors.plex}</p>}

            <Form action="/login" method="post">
                <TextField
                    id="email"
                    name="email"
                    label="Email"
                    type="email"
                    autoComplete="email"
                    required
                />

                <TextField
                    id="password"
                    name="password"
                    label="Password"
                    type="password"
                    autoComplete="current-password"
                    required
                />

                <button type="submit">Log in</button>
            </Form>

            <Form action="/auth/plex" method="post">
                <button type="submit">Continue with Plex</button>
            </Form>
        </AuthLayout>
    );
}
