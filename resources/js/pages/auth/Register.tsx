import TextField from '@/common/TextField';
import AuthLayout from '@/modules/identity/AuthLayout';
import { Form } from '@inertiajs/react';

interface RegisterProps {
    plexUsername: string;
    plexEmail: string;
    errors?: {
        name?: string;
        password?: string;
    };
}

export default function Register({
    plexUsername,
    plexEmail,
    errors,
}: RegisterProps) {
    return (
        <AuthLayout title="Register" heading="Finish creating your account">
            <Form action="/register" method="post">
                {/* The server takes the Plex identity from the session, so these
                    two are shown for confirmation only and submit nothing. */}
                <TextField
                    id="plex_username"
                    label="Plex username"
                    type="text"
                    value={plexUsername}
                    readOnly
                />

                <TextField
                    id="plex_email"
                    label="Plex email"
                    type="email"
                    value={plexEmail}
                    readOnly
                />

                <TextField
                    id="name"
                    name="name"
                    label="Display name"
                    type="text"
                    defaultValue={plexUsername}
                    autoComplete="name"
                    required
                    error={errors?.name}
                />

                <TextField
                    id="password"
                    name="password"
                    label="Password"
                    type="password"
                    autoComplete="new-password"
                    required
                    error={errors?.password}
                />

                <TextField
                    id="password_confirmation"
                    name="password_confirmation"
                    label="Confirm password"
                    type="password"
                    autoComplete="new-password"
                    required
                />

                <button type="submit">Create account</button>
            </Form>
        </AuthLayout>
    );
}
