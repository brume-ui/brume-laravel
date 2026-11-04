import { describe, expect, it } from 'vitest';
import { previewPromotion, type PreviewCondition } from './promotion-preview';

const gift = (id: string) => ({ id });
const conditions: PreviewCondition[] = [
  { amount: '500.00', actions: [{ strategy: 'all', choose_count: null, freebies: [gift('1'), gift('2')] }] },
  {
    amount: '1000.00',
    actions: [
      { strategy: 'choose', choose_count: 1, freebies: [gift('3'), gift('4')] },
      { strategy: 'all', choose_count: null, freebies: [gift('5')] },
    ],
  },
];

describe('previewPromotion', () => {
  it('returns no thresholds below every threshold', () => {
    expect(previewPromotion('499.99', conditions)).toEqual({ thresholds: [] });
  });

  it('qualifies a threshold at exactly its amount', () => {
    const result = previewPromotion('500.00', conditions);
    expect(result).toEqual({
      thresholds: [{ conditionIndex: 0, amount: '500.00', actions: [{ kind: 'all', actionIndex: 0, productIds: ['1', '2'], invalid: false }] }],
    });
  });

  it('returns only the highest threshold reached', () => {
    const result = previewPromotion('1500', conditions);
    if ('error' in result) throw new Error('unexpected error');
    expect(result.thresholds.map((t) => t.amount)).toEqual(['1000.00']);
    expect(result.thresholds[0].conditionIndex).toBe(1);
    expect(result.thresholds[0].actions).toEqual([
      { kind: 'choose', actionIndex: 0, count: 1, productIds: ['3', '4'], invalid: false },
      { kind: 'all', actionIndex: 1, productIds: ['5'], invalid: false },
    ]);
  });

  it('marks a choose action invalid when count exceeds gifts, keeping the others', () => {
    const bad: PreviewCondition[] = [
      {
        amount: '100',
        actions: [
          { strategy: 'choose', choose_count: 3, freebies: [gift('1'), gift('2')] },
          { strategy: 'choose', choose_count: null, freebies: [gift('1')] },
          { strategy: 'choose', choose_count: 0, freebies: [gift('1')] },
          { strategy: 'all', choose_count: null, freebies: [gift('9')] },
        ],
      },
    ];
    const result = previewPromotion('100', bad);
    if ('error' in result) throw new Error('unexpected error');
    expect(result.thresholds[0].actions.map((a) => a.invalid)).toEqual([true, true, true, false]);
  });

  it('rejects invalid amounts', () => {
    for (const value of ['12.345', 'abc', '', '-1', '1e3']) {
      expect(previewPromotion(value, conditions)).toEqual({ error: 'amount' });
    }
  });

  it('treats 500 and 500.00 as the same threshold amount', () => {
    const result = previewPromotion('500', [{ amount: '500', actions: [] }, { amount: '500.0', actions: [] }]);
    if ('error' in result) throw new Error('unexpected error');
    // Equal amounts are a duplicate the form rejects; the first declared one is shown.
    expect(result.thresholds).toEqual([{ conditionIndex: 0, amount: '500.00', actions: [] }]);
    expect(previewPromotion('500', conditions)).toEqual(previewPromotion('500.00', conditions));
  });

  it('picks the highest reached amount regardless of declared order', () => {
    const shuffled = [conditions[1], conditions[0]];
    const result = previewPromotion('999.99', shuffled);
    if ('error' in result) throw new Error('unexpected error');
    expect(result.thresholds.map((t) => [t.conditionIndex, t.amount])).toEqual([[1, '500.00']]);
    const top = previewPromotion('1000', shuffled);
    if ('error' in top) throw new Error('unexpected error');
    expect(top.thresholds.map((t) => [t.conditionIndex, t.amount])).toEqual([[0, '1000.00']]);
  });

  it('skips thresholds with an empty or invalid amount', () => {
    const result = previewPromotion('900', [{ amount: '', actions: [] }, { amount: 'x', actions: [] }, { amount: null, actions: [] }]);
    expect(result).toEqual({ thresholds: [] });
  });

  it('compares large amounts exactly', () => {
    const big: PreviewCondition[] = [{ amount: '90071992547409930.01', actions: [] }];
    expect(previewPromotion('90071992547409930.00', big)).toEqual({ thresholds: [] });
    expect(previewPromotion('90071992547409930.01', big)).toMatchObject({ thresholds: [{}] });
  });
});
