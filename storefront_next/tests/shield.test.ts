/**
 * The human-check tokens (lib/shield.ts) — same token format as the PHP Shield class.
 * `next/headers` is only needed by clientIp()/throttle(), which these tests do not call.
 */
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { shieldFields, verifyForm, verifyHuman } from '../src/lib/shield';

const answer = (q: string): string => String(q.split('+').reduce((n, x) => n + Number(x.trim()), 0));
const aged = (ts: string, seconds: number): string => {
  // the signed timestamp cannot be forged, so wait it out by faking the clock instead
  void seconds;
  return ts;
};

describe('shield', () => {
  it('a fresh form is too fast, a correct answer afterwards passes once', async () => {
    const f = shieldFields();
    assert.ok(f.question && f.token);
    const input = { shield_ts: aged(f.ts, 0), shield_ans: answer(f.question!), shield_ch: f.token, shield_hp: '' };
    assert.match(String(verifyForm(input)), /too fast/);

    const realNow = Date.now;
    Date.now = () => realNow() + 3000;
    try {
      assert.equal(verifyForm(input), null);
      assert.equal(await verifyHuman(input), null);
      assert.match(String(await verifyHuman(input)), /already used/); // single use
      assert.match(String(await verifyHuman({ ...input, shield_ans: '999', shield_ch: shieldFields().token })), /not right/);
      assert.match(String(await verifyHuman({ ...input, shield_hp: 'bot' })), /automated/);
      assert.match(String(await verifyHuman({ ...input, shield_ts: 'x' })), /expired/);
      assert.match(String(await verifyHuman({ ...input, shield_ans: '' })), /quick check/);
    } finally {
      Date.now = realNow;
    }
  });

  it('an old form expires', () => {
    const f = shieldFields();
    const realNow = Date.now;
    Date.now = () => realNow() + 7300 * 1000;
    try {
      assert.match(String(verifyForm({ shield_ts: f.ts })), /expired/);
    } finally {
      Date.now = realNow;
    }
  });

  it('tokens are signed: tampering is rejected', async () => {
    const f = shieldFields();
    const realNow = Date.now;
    Date.now = () => realNow() + 3000;
    try {
      const forged = f.token!.replace(/[a-f0-9]$/, (c) => (c === '0' ? '1' : '0'));
      assert.match(String(await verifyHuman({ shield_ts: f.ts, shield_ans: answer(f.question!), shield_ch: forged })), /quick check|not right/);
    } finally {
      Date.now = realNow;
    }
  });
});
