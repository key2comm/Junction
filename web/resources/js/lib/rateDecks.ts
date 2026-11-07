import { index as carrierRates } from '@/routes/carrier-rates';
import { index as tariffs } from '@/routes/tariffs';

/** Rate decks come in two kinds, each with its own list page and wording. */
export function deckKind(kind: 'tariff' | 'carrier') {
    return kind === 'tariff'
        ? {
              singular: 'Tariff',
              plural: 'Tariffs',
              index: tariffs,
              description: 'What your customers pay, per destination prefix. Each customer account uses one tariff.',
          }
        : {
              singular: 'Carrier rates',
              plural: 'Carrier rates',
              index: carrierRates,
              description: 'What carriers charge you, per destination prefix. Each carrier trunk uses one, and least-cost routing compares them.',
          };
}
