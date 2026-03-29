const { getStations, getServiceActif } = require('../utils/api');
const { getChannel } = require('../utils/config');

async function updateStatsActivity(client) {
  try {
    const stations = await getStations();
    if (!stations.length) return;
    const t = stations.reduce((a,x)=>a+(parseFloat(x.volume_actuel||x.volumeActuel)||0),0);
    const m = stations.reduce((a,x)=>a+(parseFloat(x.volume_max||x.volumeMax)||0),0);
    const p = m>0?Math.round(t/m*100):0;
    const e = p>=60?'🟢':p>=30?'🟡':'🔴';
    client.user.setActivity(`${e} Stock ${p}% | ${stations.length} stations`, { type:0 });
    // Mettre à jour le message public toutes les 5 min
    const chId = await getChannel('CHANNEL_STATIONS_PUBLIC');
    if (chId) {
      const { sendOrUpdateStockMessage } = require('../commands/stock');
      await sendOrUpdateStockMessage(client, chId);
    }
  } catch(e) { console.error('updateStatsActivity:', e.message); }
}

async function buildServiceEmbed() {
  const v = await getServiceActif();
  if (!v.length) return { embeds:[{title:'👥 Vendeurs en service',description:'*Aucun vendeur disponible.*',color:0x555555,timestamp:new Date().toISOString()}] };
  const fields = v.map(x => {
    const d = x.debut?new Date(x.debut.replace(' ','T')):null;
    const m = d?Math.floor((Date.now()-d)/60000):0;
    const t = m>60?`${Math.floor(m/60)}h${String(m%60).padStart(2,'0')}`:`${m}min`;
    return { name:`🛒 ${x.prenom} ${x.nom}`, value:`**${x.poste||'—'}**\n⏱ ${t}`, inline:true };
  });
  return { embeds:[{ title:`👥 En service (${v.length})`, color:0x4ade80, fields, timestamp:new Date().toISOString() }] };
}

module.exports = { updateStatsActivity, buildServiceEmbed };
