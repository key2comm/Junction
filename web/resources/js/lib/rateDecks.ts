import { index as carrierRates } from '@/routes/carrier-rates';
import { index as tariffs } from '@/routes/tariffs';

/** Rate decks come in two kinds, each with its own list page and wording. */
export function deckKind(kind: 'tariff' | 'carrier') {
    return kind === 'tariff'
        ? {
              singular: 'Tariff',
              plural: 'Tariffs',
              index: tariffs,
              description: 'What your customer pays when calls are made to each prefix. Only one tariff can be assigned to a customer.',
          }
        : {
              singular: 'Carrier Rates',
              plural: 'Carrier Rates',
              index: carrierRates,
              description: 'What carriers charge you, per destination prefix. Each carrier trunk uses one, and least-cost routing compares them.',
          };
}
