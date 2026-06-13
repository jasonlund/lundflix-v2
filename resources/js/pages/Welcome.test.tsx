import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Welcome from './Welcome';

// Inertia's <Head> needs the app's head manager context; stub it for unit rendering.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

describe('Welcome page', () => {
    it('renders the lundflix heading', () => {
        render(<Welcome />);
        expect(
            screen.getByRole('heading', { name: /lundflix/i }),
        ).toBeInTheDocument();
    });
});
