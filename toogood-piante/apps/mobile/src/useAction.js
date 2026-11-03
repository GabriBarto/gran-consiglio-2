import { useState } from 'react';
import { errorMessage } from './errors';

// Esegue un'azione asincrona mostrando "in corso" ed eventuale errore tradotto.
export function useAction() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const run = async (fn) => {
    setLoading(true);
    setError('');
    try {
      await fn();
    } catch (e) {
      setError(errorMessage(e));
      return e;
    } finally {
      setLoading(false);
    }
  };
  return { loading, error, setError, run };
}
