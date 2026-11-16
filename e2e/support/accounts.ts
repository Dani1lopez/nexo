// Fictitious, throwaway accounts created in a temporary database for every e2e run.
export const accounts = {
  treasury: { email: 'tesoreria-e2e@example.test', password: 'e2e-treasury-password', role: 'treasury' },
  board: { email: 'junta-e2e@example.test', password: 'e2e-board-password-ok', role: 'board' },
} as const;
