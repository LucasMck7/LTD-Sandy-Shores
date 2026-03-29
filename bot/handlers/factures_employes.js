const { apiPost } = require('../utils/api');

const CHANNEL_FACTURES_EMP = process.env.CHANNEL_FACTURES_EMP     || '1441586772403294359';
const CHANNEL_CONFIRMATION  = process.env.CHANNEL_CONFIRMATION_EMP || '1487045705146175488';

// Map: discord_msg_id facture → id du message de confirmation
const CONFIRM_MSGS = {};

function clean(s) { return (s||'').replace(/[*_`~>]/g,'').trim(); }

function extractFacture(text) {
  const factureId    = (text.match(/FACTURE\s+(\d+)/i)||[])[1] || clean((text.match(/Facture ID[`\s]*:\s*`?(\d+)`?/i)||[])[1]);
  const emetteurNom  = clean((text.match(/metteur\*\*\s*:\s*([^\n<*`]+)/)||[])[1]);
  const destNom      = clean((text.match(/Destinataire\*\*\s*:\s*([^\n<*`]+)/)||[])[1]);
  const mentions     = [...text.matchAll(/<@!?(\d{15,20})>/g)].map(m=>m[1]);
  const emetteurDisc = mentions[0]||'';
  const destDisc     = mentions[1]||'';
  const montant      = parseFloat((text.match(/Montant\*\*\s*:\s*([0-9]+(?:[.,][0-9]+)?)\s*\$/)||[])[1]||0);
  const raison       = clean((text.match(/Raison\*\*\s*:\s*([^\n`*>]+)/)||[])[1]);
  const statut       = clean((text.match(/Status\*\*\s*:\s*([^\n`*]+)/)||text.match(/Statut\*\*\s*:\s*([^\n`*]+)/)||[])[1])||'En attente';
  const paiement     = clean((text.match(/Paiement\*\*\s*:\s*([^\n`*]+)/)||[])[1]);
  const dateCreation = clean((text.match(/Cr[eé][eé]e?\s+le[`\s]*:\s*`?([^`\n]+)`?/)||[])[1]);
  const datePaiement = clean((text.match(/Pay[eé][eé]e?\s+le[`\s]*:\s*`?([^`\n]+)`?/)||[])[1]);
  return {factureId,emetteurNom,emetteurDisc,destNom,destDisc,montant,raison,statut,paiement,dateCreation,datePaiement};
}

function buildFullText(message) {
  const parts = [message.content||''];
  for (const e of message.embeds) {
    parts.push(e.title||'', e.description||'');
    for (const f of (e.fields||[])) parts.push((f.name||'')+': '+(f.value||''));
  }
  return parts.join('\n');
}

function buildEmbed(d) {
  const isPaid     = d.statut.toLowerCase().includes('pay');
  const isCanceled = d.statut.toLowerCase().includes('annul');
  const color  = isPaid ? 0x57F287 : isCanceled ? 0xED4245 : 0xFEE75C;
  const emoji  = isPaid ? '✅' : isCanceled ? '❌' : '⏳';
  const label  = isPaid ? 'Payée' : isCanceled ? 'Annulée' : 'En attente';

  const emStr  = d.emetteurDisc  ? `<@${d.emetteurDisc}>`  : d.emetteurNom  || 'Inconnu';
  const dstStr = d.destDisc      ? `<@${d.destDisc}>`      : d.destNom      || '—';

  const description = [
    `> 👤 **Émetteur** : ${emStr}${d.emetteurNom?' — '+d.emetteurNom:''}`,
    `> 🎯 **Destinataire** : ${dstStr}${d.destNom?' — '+d.destNom:''}`,
    `> 💰 **Montant** : ${d.montant>0 ? '**'+d.montant.toLocaleString('fr-FR')+' $**' : '—'}`,
    `> 📋 **Raison** : ${d.raison||'—'}`,
    `> 💳 **Paiement** : ${d.paiement||'—'}`,
    `> 📅 **Créée le** : ${d.dateCreation||'—'}`,
    d.datePaiement ? `> ✅ **Payée le** : ${d.datePaiement}` : null,
  ].filter(Boolean).join('\n');

  return {
    title: `${emoji}  Facture #${d.factureId} — ${label}`,
    color,
    description,
    footer: { text: 'LTD Sandy Shores • Factures vendeurs' },
    timestamp: new Date().toISOString(),
  };
}

async function handleFacturesEmployes(message) {
  if (message.channelId !== CHANNEL_FACTURES_EMP) return;

  const text = buildFullText(message);
  const d    = extractFacture(text);
  if (!d.factureId) return;

  const isPaid     = d.statut.toLowerCase().includes('pay');
  const isCanceled = d.statut.toLowerCase().includes('annul');
  const isPending  = !isPaid && !isCanceled;

  console.log(`Facture: ID=${d.factureId} Montant=${d.montant} Statut=${d.statut}`);

  const ch = await message.client.channels.fetch(CHANNEL_CONFIRMATION).catch(()=>null);
  const existingId = CONFIRM_MSGS[message.id];

  // ── Édition d'une facture déjà connue ──
  if (existingId) {
    // Supprimer toutes les réactions avant d'en ajouter une nouvelle
    try { await message.reactions.removeAll(); } catch(e) {}

    await message.react(isPaid ? '✅' : isCanceled ? '❌' : '⏳').catch(()=>{});

    // Éditer le message de confirmation
    if (ch) {
      try {
        const confirmMsg = await ch.messages.fetch(existingId).catch(()=>null);
        if (confirmMsg) await confirmMsg.edit({ embeds:[buildEmbed(d)] });
      } catch(e) { console.error('edit:', e.message); }
    }

    // Mettre à jour BDD
    await apiPost('emp_factures_update_statut', {
      facture_id:d.factureId, statut:d.statut, montant:d.montant,
      paiement:d.paiement, date_paiement:d.datePaiement||null,
    }, true).catch(()=>{});

    if (!isPending) delete CONFIRM_MSGS[message.id];
    console.log(`Mise à jour #${d.factureId} → ${d.statut}`);
    return;
  }

  // ── Nouvelle facture ──

  // En attente sans montant → juste ⏳, pas de confirmation
  if (isPending && !d.montant) {
    await message.react('⏳').catch(()=>{});
    return;
  }

  // Sauvegarder en BDD
  const saved = await apiPost('emp_factures_add', {
    facture_id:d.factureId, emetteur_nom:d.emetteurNom, emetteur_discord:d.emetteurDisc,
    destinataire_nom:d.destNom, destinataire_discord:d.destDisc,
    montant:d.montant, raison:d.raison, statut:d.statut, paiement:d.paiement,
    date_facture:new Date().toISOString().replace('T',' ').slice(0,19),
    discord_msg_id:message.id, channel_id:message.channelId, raw_text:text.slice(0,800),
  }, true).catch(err=>{ console.error('add:', err.message); return null; });

  if (!saved) { await message.react('❌').catch(()=>{}); return; }
  if (saved.duplicate) {
    await apiPost('emp_factures_update_statut', {
      facture_id:d.factureId, statut:d.statut, montant:d.montant,
      paiement:d.paiement, date_paiement:d.datePaiement||null,
    }, true).catch(()=>{});
  }

  // Réaction
  await message.react(isPaid ? '✅' : isCanceled ? '❌' : '⏳').catch(()=>{});

  // Envoyer confirmation et mémoriser
  if (ch) {
    const sentMsg = await ch.send({ embeds:[buildEmbed(d)] }).catch(()=>null);
    if (sentMsg && isPending) {
      CONFIRM_MSGS[message.id] = sentMsg.id;
    }
  }

  console.log(`OK Facture #${d.factureId} (${d.montant}$ - ${d.statut})`);
}

module.exports = { handleFacturesEmployes, CHANNEL_FACTURES_EMP };
