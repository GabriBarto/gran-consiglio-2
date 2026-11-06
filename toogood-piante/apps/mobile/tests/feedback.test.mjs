import assert from 'node:assert/strict';
import test from 'node:test';
import {
  complaintDeadlineText, complaintError, complaintOutcomeText, complaintStatusInfo, localDateTimeOf, reviewError,
  reviewSummaryText, sellerComplaintLine, stars,
} from '../src/feedback.js';

test('stars: sempre cinque, piene e vuote', () => {
  assert.equal(stars(4), '★★★★☆');
  assert.equal(stars(5), '★★★★★');
  assert.equal(stars(1), '★☆☆☆☆');
});

test('reviewError: il voto è obbligatorio e va da 1 a 5, il commento no', () => {
  assert.match(reviewError({ rating: 0 }), /voto/);
  assert.match(reviewError({ rating: 6 }), /voto/);
  assert.match(reviewError({ rating: null }), /voto/);
  assert.match(reviewError({ rating: 3.5 }), /voto/);
  assert.equal(reviewError({ rating: 4 }), '');
  assert.equal(reviewError({ rating: 4, comment: '' }), '');
  assert.match(reviewError({ rating: 4, comment: 'a'.repeat(1001) }), /1000/);
  assert.equal(reviewError({ rating: 4, comment: 'a'.repeat(1000) }), '');
});

test('complaintError: il motivo deve essere tra quelli ammessi e la descrizione di almeno 10 caratteri', () => {
  const allowed = ['VALUE_BELOW_DECLARED', 'PLANTS_DAMAGED'];

  assert.match(complaintError({ reason: '', description: 'abbastanza lungo' }, allowed), /motivo/);
  // Un motivo non ammesso per quest'ordine (es. "negozio chiuso" dopo il ritiro) non passa
  assert.match(complaintError({ reason: 'CLOSED_OR_NOT_DELIVERED', description: 'abbastanza lungo' }, allowed), /motivo/);
  assert.match(complaintError({ reason: 'PLANTS_DAMAGED', description: 'corto' }, allowed), /almeno 10/);
  // Gli spazi non contano come testo
  assert.match(complaintError({ reason: 'PLANTS_DAMAGED', description: '          ' }, allowed), /almeno 10/);
  assert.match(complaintError({ reason: 'PLANTS_DAMAGED', description: 'a'.repeat(2001) }, allowed), /2000/);
  assert.equal(complaintError({ reason: 'PLANTS_DAMAGED', description: 'Due piante erano secche.' }, allowed), '');
});

test('complaintStatusInfo: un nome e un colore per ogni esito', () => {
  assert.deepEqual(complaintStatusInfo('OPEN'), { label: 'In esame', tone: 'warn' });
  assert.equal(complaintStatusInfo('ACCEPTED_FULL').tone, 'ok');
  assert.equal(complaintStatusInfo('REJECTED').tone, 'bad');
  assert.equal(complaintStatusInfo('QUALCOSA').label, 'QUALCOSA');
});

test('complaintOutcomeText: dice al cliente quanto riceve', () => {
  assert.match(complaintOutcomeText({ status: 'OPEN' }), /esaminando/);
  assert.match(complaintOutcomeText({ status: 'ACCEPTED_FULL', refund_cents: 700 }), /7,00 €.*tutto l’ordine/);
  assert.match(complaintOutcomeText({ status: 'ACCEPTED_PARTIAL', refund_cents: 250 }), /accolto in parte.*2,50 €/);
  assert.match(complaintOutcomeText({ status: 'REJECTED', refund_cents: 0 }), /alcun rimborso/);
});

test('sellerComplaintLine: dice al venditore cosa succede al pagamento', () => {
  assert.match(sellerComplaintLine({ status: 'OPEN' }), /sospeso/);
  assert.match(sellerComplaintLine({ status: 'ACCEPTED_FULL', refund_cents: 700 }), /alcun pagamento/);
  assert.match(sellerComplaintLine({ status: 'ACCEPTED_PARTIAL', refund_cents: 200 }), /2,00 €.*ridotto/);
  assert.match(sellerComplaintLine({ status: 'REJECTED' }), /prosegue/);
});

test('localDateTimeOf e complaintDeadlineText: giorno e ora nel fuso del telefono', () => {
  const iso = '2026-06-16T16:00:00+00:00';
  const d = new Date(iso);
  const expected = `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')} alle ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;

  assert.equal(localDateTimeOf(iso), expected);
  assert.equal(complaintDeadlineText({ reasons: [], until: iso }), `Puoi aprire un reclamo fino al ${expected}.`);
  assert.equal(complaintDeadlineText({ reasons: [], until: null }), '');
  assert.equal(complaintDeadlineText(undefined), '');
});

test('reviewSummaryText: media con la virgola e singolare/plurale', () => {
  assert.equal(reviewSummaryText({ average: null, count: 0 }), 'Nessuna recensione ancora.');
  assert.equal(reviewSummaryText(undefined), 'Nessuna recensione ancora.');
  assert.equal(reviewSummaryText({ average: 4.3, count: 7 }), '4,3 su 5 · 7 recensioni');
  assert.equal(reviewSummaryText({ average: 5, count: 1 }), '5 su 5 · 1 recensione');
});
