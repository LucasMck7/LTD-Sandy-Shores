// ══ Automatisations CRON ════════════════════════════════
const { apiPost, apiGet } = require('../utils/api');
const { getChannel } = require('../utils/config');

// ── Rapport quotidien (minuit) ─────────────────────────
async function sendDailyReport(client) {
  const channelId = await getChannel('CHANNEL_RAPPORT_QUOTIDIEN');
  if (!channelId) return;

  try {
    const channel = await client.channels.fetch(channelId).catch(()=>null);
    if (!channel) return;

    const [ordersR, stationsR, serviceR] = await Promise.all([
      apiPost('get_orders', null, true).catch(()=>({orders:[]})),
      apiGet('emp_get_stations').catch(()=>({stations:[]})),
      apiGet('get_service_actif').catch(()=>({vendeurs:[]})),
    ]);

    const orders   = ordersR.orders || [];
    const stations = stationsR.stations || [];

    // Filtrer commandes du jour
    const today = new Date().toISOString().split('T')[0];
    const todayOrders = orders.filter(o => (o.date_commande||o.date||'').startsWith(today));
    const caJour = todayOrders.reduce((a,o) => a + parseFloat(o.total||0), 0);
    const livrees = todayOrders.filter(o => o.status==='livree'||o.status==='delivered').length;
    const enCours = orders.filter(o => !['livree','delivered','annule'].includes(o.status));

    // Stock stations
    const stationsInfo = stations.map(s => {
      const a = Math.round(parseFloat(s.volume_actuel||s.volumeActuel)||0);
      const m = Math.round(parseFloat(s.volume_max||s.volumeMax)||5000);
      const p = m>0?Math.round(a/m*100):0;
      const e = p>=60?'🟢':p>=30?'🟡':'🔴';
      return `${e} **${s.nom}** : ${a.toLocaleString('fr-FR')} L (${p}%)`;
    }).join('\n') || '—';

    // Commandes en cours
    const enCoursLines = enCours.slice(0,5).map(o =>
      `• **${o.ref||o.id}** — ${o.company||`${o.prenom||''} ${o.nom||''}`} — ${parseFloat(o.total||0).toFixed(2)} $`
    ).join('\n') || 'Aucune commande en cours';

    const dateStr = new Date().toLocaleDateString('fr-FR', { weekday:'long', day:'2-digit', month:'long', year:'numeric' });

    await channel.send({
      embeds: [{
        title: `📊 Rapport quotidien — ${dateStr}`,
        color: 0xE8C96A,
        fields: [
          { name:'💰 CA du jour',        value:`**${caJour.toFixed(2)} $**`,   inline:true },
          { name:'📦 Commandes du jour', value:String(todayOrders.length),      inline:true },
          { name:'🚚 Livrées aujourd\'hui', value:String(livrees),              inline:true },
          { name:'🔵 En cours total',    value:String(enCours.length),          inline:true },
          { name:'⛽ État des stations', value:stationsInfo,                    inline:false },
          { name:'📋 Commandes en cours (top 5)', value:enCoursLines,          inline:false },
        ],
        footer: { text:'LTD Sandy Shores • Rapport automatique minuit' },
        timestamp: new Date().toISOString(),
      }],
    });

    console.log('📊 Rapport quotidien envoyé');
  } catch(err) { console.error('sendDailyReport:', err.message); }
}

// ── Archivage commandes terminées ─────────────────────
async function archiveFinishedOrders(client) {
  const channelId = await getChannel('CHANNEL_ARCHIVES');
  if (!channelId) return;

  try {
    const channel = await client.channels.fetch(channelId).catch(()=>null);
    if (!channel) return;

    const r = await apiPost('get_orders', null, true).catch(()=>({orders:[]}));
    const orders = (r.orders||[]).filter(o =>
      ['livree','delivered','annule'].includes(o.status)
    );

    // Ne pas réarchiver — vérifier celles déjà archivées
    const archivedR = await apiGet('bot_get_archived').catch(()=>({ids:[]}));
    const archived = new Set(archivedR.ids || []);

    const toArchive = orders.filter(o => !archived.has(o.ref||o.id));
    if (!toArchive.length) return;

    for (const o of toArchive.slice(0, 10)) {
      const statusE = { livree:'🚚 Livrée', delivered:'🚚 Livrée', annule:'❌ Annulée' };
      const statusC = { livree:0x27AE60, delivered:0x27AE60, annule:0xE74C3C };

      await channel.send({
        embeds: [{
          title: `📁 Archivé — ${o.ref||o.id}`,
          color: statusC[o.status] || 0x888888,
          fields: [
            { name:'📋 Statut',    value:statusE[o.status]||o.status,                                  inline:true },
            { name:'🏷 Type',     value:o.company ? `🏢 ${o.company}` : '👤 Particulier',              inline:true },
            { name:'👤 Contact',  value:o.contact||`${o.prenom||''} ${o.nom||''}`,                    inline:true },
            { name:'📅 Date',     value:o.date_commande||o.date||'—',                                  inline:true },
            { name:'💰 Total',    value:`${parseFloat(o.total||0).toFixed(2)} $`,                     inline:true },
            { name:'🚗 Livreur',  value:o.livreurName||'—',                                           inline:true },
          ],
          footer: { text:'LTD Sandy Shores • Archives automatiques' },
          timestamp: new Date().toISOString(),
        }],
      });

      // Marquer comme archivé
      await apiPost('bot_mark_archived', { id: o.ref||o.id }, true).catch(()=>{});
      await new Promise(r => setTimeout(r, 500)); // Anti rate-limit
    }

    if (toArchive.length) console.log(`📁 ${toArchive.length} commande(s) archivée(s)`);
  } catch(err) { console.error('archiveFinishedOrders:', err.message); }
}

// ── Alerte station à 0% ────────────────────────────────
const ALERTED_STATIONS = new Set(); // éviter le spam

async function checkStationsAlert(client) {
  const channelId = await getChannel('CHANNEL_ALERTES');
  if (!channelId) return;

  try {
    const r = await apiGet('emp_get_stations').catch(()=>({stations:[]}));
    const stations = r.stations || [];

    for (const s of stations) {
      const actuel = parseFloat(s.volume_actuel||s.volumeActuel)||0;
      const max    = parseFloat(s.volume_max||s.volumeMax)||5000;
      const pct    = max>0 ? Math.round(actuel/max*100) : 0;
      const key    = `${s.id}_${Math.floor(pct/10)}`; // alert par tranche de 10%

      // Alerte à 0%
      if (pct === 0 && !ALERTED_STATIONS.has(`${s.id}_0`)) {
        ALERTED_STATIONS.add(`${s.id}_0`);
        const channel = await client.channels.fetch(channelId).catch(()=>null);
        if (channel) {
          await channel.send({
            content: `🚨 **ALERTE STOCK VIDE**`,
            embeds: [{
              title: `⛽ ${s.nom} — STOCK VIDE`,
              color: 0xE74C3C,
              description: `La station **${s.nom}** est à **0 litre** !\nRavitaillement urgent nécessaire.`,
              fields: [
                { name:'📦 Stock',   value:'**0 L**',              inline:true },
                { name:'📊 Capacité', value:`${Math.round(max).toLocaleString('fr-FR')} L`, inline:true },
              ],
              footer: { text:'LTD Sandy Shores • Alerte automatique' },
              timestamp: new Date().toISOString(),
            }],
          });
        }
      }
      // Alerte à 20%
      else if (pct <= 20 && pct > 0 && !ALERTED_STATIONS.has(`${s.id}_20`)) {
        ALERTED_STATIONS.add(`${s.id}_20`);
        const channel = await client.channels.fetch(channelId).catch(()=>null);
        if (channel) {
          await channel.send({
            embeds: [{
              title: `⚠️ ${s.nom} — Stock faible (${pct}%)`,
              color: 0xE74C3C,
              description: `La station **${s.nom}** est à **${pct}%** (${Math.round(actuel).toLocaleString('fr-FR')} L).\nPenser à ravitailler bientôt.`,
              footer: { text:'LTD Sandy Shores • Alerte automatique' },
              timestamp: new Date().toISOString(),
            }],
          });
        }
      }

      // Reset alerte quand ravitaillé
      if (pct > 20) {
        ALERTED_STATIONS.delete(`${s.id}_0`);
        ALERTED_STATIONS.delete(`${s.id}_20`);
      }
    }
  } catch(err) { console.error('checkStationsAlert:', err.message); }
}

module.exports = { sendDailyReport, archiveFinishedOrders, checkStationsAlert };
