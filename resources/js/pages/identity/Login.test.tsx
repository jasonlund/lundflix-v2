import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Login from './Login';

vi.mock('@inertiajs/react', async () =>
    (await import('@/test/inertia')).inertiaStub(),
);

describe('Login page', () => {
    it('renders the email and password fields', () => {
        // Arrange
        // no props or state to set up — pure static render

        // Act
        render(<Login />);

        // Assert
        expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
        expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
    });

    it('renders a Plex hand-off control that posts to the Plex start route', () => {
        // Arrange
        // no props or state to set up — pure static render

        // Act
        render(<Login />);

        // Assert
        const plexForm = screen
            .getByRole('button', { name: /continue with plex/i })
            .closest('form');
        expect(plexForm).toHaveAttribute('action', '/auth/plex');
        expect(plexForm?.getAttribute('method')?.toLowerCase()).toBe('post');
    });

    it('shows the Plex error returned by the server', () => {
        // Arrange
        const errors = {
            plex: 'Your Plex account does not have access to lundflix.',
        };

        // Act
        render(<Login errors={errors} />);

        // Assert
        expect(
            screen.getByText(
                'Your Plex account does not have access to lundflix.',
            ),
        ).toBeInTheDocument();
    });
});
