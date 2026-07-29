import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Register from './Register';

vi.mock('@inertiajs/react', async () =>
    (await import('@/test/inertia')).inertiaStub(),
);

describe('Register page', () => {
    // The server reads the Plex identity from the session and ignores anything
    // submitted for it, so the page shows it without letting the user edit it.
    it('shows the verified Plex identity as read-only', () => {
        // Arrange
        const props = { plexUsername: 'lundy', plexEmail: 'lundy@plex.tv' };

        // Act
        render(<Register {...props} />);

        // Assert
        expect(screen.getByLabelText(/plex username/i)).toHaveValue('lundy');
        expect(screen.getByLabelText(/plex username/i)).toHaveAttribute(
            'readonly',
        );
        expect(screen.getByLabelText(/plex email/i)).toHaveValue(
            'lundy@plex.tv',
        );
        expect(screen.getByLabelText(/plex email/i)).toHaveAttribute(
            'readonly',
        );
    });

    it('prefills the display name with the Plex username but leaves it editable', () => {
        // Arrange
        const props = { plexUsername: 'lundy', plexEmail: 'lundy@plex.tv' };

        // Act
        render(<Register {...props} />);

        // Assert
        const displayName = screen.getByLabelText(/^display name$/i);
        expect(displayName).toHaveValue('lundy');
        expect(displayName).not.toHaveAttribute('readonly');
    });

    it('collects a password and its confirmation in a form that posts to the register route', () => {
        // Arrange
        const props = { plexUsername: 'lundy', plexEmail: 'lundy@plex.tv' };

        // Act
        render(<Register {...props} />);

        // Assert
        const password = screen.getByLabelText(/^password$/i);
        expect(password).toHaveAttribute('type', 'password');
        expect(screen.getByLabelText(/confirm password/i)).toHaveAttribute(
            'type',
            'password',
        );
        const form = password.closest('form');
        expect(form).toHaveAttribute('action', '/register');
        expect(form?.getAttribute('method')?.toLowerCase()).toBe('post');
    });

    it('shows the per-field validation errors returned by the server', () => {
        // Arrange
        const errors = {
            name: 'The name field is required.',
            password: 'The password field confirmation does not match.',
        };

        // Act
        render(
            <Register
                plexUsername="lundy"
                plexEmail="lundy@plex.tv"
                errors={errors}
            />,
        );

        // Assert
        expect(
            screen.getByText('The name field is required.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('The password field confirmation does not match.'),
        ).toBeInTheDocument();
    });
});
