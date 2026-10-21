import { query } from '../db.js';

const eur = (c) => (c / 100).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
const when = (d) => new Date(d).toLocaleString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Rome' });
const hhmm = (d) => new Date(d).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Rome' });

/** Registra l'email nella tabella `emails` e la scrive nel log; l'invio SMTP vero arriva con il rilascio (Fase 5). */
export async function sendMail(db, { to, subject, body, orderId = null }) {
  await db.query('INSERT INTO emails (to_email, subject, body, order_id) VALUES ($1,$2,$3,$4)', [to, subject, body, orderId]);
  if (process.env.NODE_ENV !== 'test') console.log(`[email] a ${to}: ${subject}`);
}

export function orderConfirmation({ order, shop, items, username }) {
  const lines = items.map((i) =>
    `- ${i.qty} × ${i.title} (${eur(i.unit_price_cents)} l'una) · ritiro ${when(i.pickup_start)}–${hhmm(i.pickup_end)}`).join('\n');
  return {
    subject: `VerdeSalva: ordine ${order.code} confermato`,
    body: `Ciao ${username},\nil tuo pagamento di ${eur(order.total_cents)} è andato a buon fine.\n\nRiepilogo:\n${lines}\n\n` +
      `Dove ritirare: ${shop.name}, ${shop.address}, ${shop.city}\nMostra in negozio il codice ${order.code} nella fascia oraria indicata.\n\nGrazie per aver salvato una pianta! 🌱`,
  };
}
